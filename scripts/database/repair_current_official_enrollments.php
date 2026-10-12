<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/config/database.php';
require_once dirname(__DIR__, 2) . '/app/services/AuditLog.php';

const REPAIR_ACADEMIC_YEAR = '2026-2027';
const EXPECTED_CANDIDATES = 2;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This repair can only run from the command line.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$expected = null;
foreach ($argv as $argument) {
    if (preg_match('/^--expected=(\d+)$/', $argument, $matches)) {
        $expected = (int) $matches[1];
    }
}

if ($apply && $expected !== EXPECTED_CANDIDATES) {
    fwrite(STDERR, 'Applying this repair requires --expected=' . EXPECTED_CANDIDATES . ".\n");
    exit(1);
}

function repair_current_official_enrollment_summary(PDO $db, bool $lock = false): array
{
    $lockSuffix = $lock ? ' FOR UPDATE' : '';
    $insertCandidates = $db->query("
        SELECT s.person_id, s.program_id, s.year_level, s.section
        FROM students s
        INNER JOIN patients pt ON pt.person_id = s.person_id AND pt.access_status = 'Official'
        INNER JOIN accounts a ON a.person_id = s.person_id AND a.account_status = 'active'
        LEFT JOIN student_school_year_enrollments e
            ON e.student_person_id = s.person_id AND e.academic_year = '" . REPAIR_ACADEMIC_YEAR . "'
        LEFT JOIN student_enrollment_declarations d
            ON d.account_id = a.id AND d.academic_year = '" . REPAIR_ACADEMIC_YEAR . "'
        WHERE e.enrollment_id IS NULL
          AND TRIM(COALESCE(s.academic_year, '')) = ''
          AND (d.enrollment_status IS NULL OR d.enrollment_status <> 'Not Currently Enrolled')
        ORDER BY s.person_id" . $lockSuffix
    )->fetchAll(PDO::FETCH_ASSOC);

    $reconciliationCandidates = $db->query("
        SELECT s.person_id, s.program_id, s.year_level, s.section
        FROM students s
        INNER JOIN patients pt ON pt.person_id = s.person_id AND pt.access_status = 'Official'
        INNER JOIN accounts a ON a.person_id = s.person_id AND a.account_status = 'active'
        INNER JOIN student_school_year_enrollments e
            ON e.student_person_id = s.person_id
            AND e.academic_year = '" . REPAIR_ACADEMIC_YEAR . "'
            AND e.enrollment_status = 'Enrolled'
            AND e.promotion_source = 'Administrative Repair'
        LEFT JOIN student_enrollment_declarations d
            ON d.account_id = a.id AND d.academic_year = '" . REPAIR_ACADEMIC_YEAR . "'
        WHERE TRIM(COALESCE(s.academic_year, '')) = ''
          AND (d.enrollment_status IS NULL OR d.enrollment_status <> 'Not Currently Enrolled')
        ORDER BY s.person_id" . $lockSuffix
    )->fetchAll(PDO::FETCH_ASSOC);

    $conflicts = (int) $db->query("
        SELECT COUNT(*)
        FROM students s
        INNER JOIN patients pt ON pt.person_id = s.person_id AND pt.access_status = 'Official'
        INNER JOIN accounts a ON a.person_id = s.person_id AND a.account_status = 'active'
        INNER JOIN student_enrollment_declarations d
            ON d.account_id = a.id
            AND d.academic_year = '" . REPAIR_ACADEMIC_YEAR . "'
            AND d.enrollment_status = 'Not Currently Enrolled'
    ")->fetchColumn();

    return [
        'insert_candidates' => $insertCandidates,
        'reconciliation_candidates' => $reconciliationCandidates,
        'candidates' => array_merge($insertCandidates, $reconciliationCandidates),
        'conflicts' => $conflicts,
    ];
}

try {
    $db = auth_db();
    $summary = repair_current_official_enrollment_summary($db);
    $report = [
        'academic_year' => REPAIR_ACADEMIC_YEAR,
        'eligible_official_students' => count($summary['candidates']),
            'history_rows_to_insert' => count($summary['insert_candidates']),
            'academic_years_to_reconcile' => count($summary['reconciliation_candidates']),
        'conflicting_non_enrollment_declarations' => $summary['conflicts'],
        'expected_candidates' => EXPECTED_CANDIDATES,
        'mode' => $apply ? 'apply' : 'dry-run',
    ];

    if (!$apply) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    ensure_audit_log_schema();
    $db->beginTransaction();
    try {
        $summary = repair_current_official_enrollment_summary($db, true);
        if (count($summary['candidates']) !== EXPECTED_CANDIDATES || $summary['conflicts'] !== 0) {
            throw new RuntimeException('Repair preconditions changed; no enrollment history was written.');
        }

        $insert = $db->prepare("
            INSERT INTO student_school_year_enrollments
                (student_person_id, academic_year, program_id, year_level, section, enrollment_status, non_enrollment_reason, promotion_source, promoted_by_person_id)
            VALUES (?, ?, ?, ?, ?, 'Enrolled', NULL, 'Administrative Repair', NULL)
        ");
        foreach ($summary['insert_candidates'] as $candidate) {
            $insert->execute([
                (int) $candidate['person_id'],
                REPAIR_ACADEMIC_YEAR,
                $candidate['program_id'] !== null ? (int) $candidate['program_id'] : null,
                (string) ($candidate['year_level'] ?? ''),
                (string) ($candidate['section'] ?? ''),
            ]);
        }

        $updateAcademicYear = $db->prepare("UPDATE students SET academic_year = ? WHERE person_id = ? AND TRIM(COALESCE(academic_year, '')) = ''");
        foreach ($summary['candidates'] as $candidate) {
            $updateAcademicYear->execute([REPAIR_ACADEMIC_YEAR, (int) $candidate['person_id']]);
            if ($updateAcademicYear->rowCount() !== 1) {
                throw new RuntimeException('Repair candidate no longer has a blank academic year.');
            }
        }

        if ($summary['insert_candidates'] !== [] && audit_log_event('settings', 'current_official_enrollment_repaired', null, 'system', 'student_school_year_enrollment', null, [
            'academic_year' => REPAIR_ACADEMIC_YEAR,
            'count' => count($summary['insert_candidates']),
        ]) < 1) {
            throw new RuntimeException('The enrollment repair audit entry could not be recorded.');
        }
        if ($summary['reconciliation_candidates'] !== [] && audit_log_event('settings', 'current_official_enrollment_reconciled', null, 'system', 'student_school_year_enrollment', null, [
            'academic_year' => REPAIR_ACADEMIC_YEAR,
            'count' => count($summary['reconciliation_candidates']),
        ]) < 1) {
            throw new RuntimeException('The enrollment reconciliation audit entry could not be recorded.');
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $report['applied'] = EXPECTED_CANDIDATES;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[CLINiQ Enrollment Repair] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
