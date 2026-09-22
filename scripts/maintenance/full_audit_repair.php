<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/config/env.php';
require_once dirname(__DIR__, 2) . '/app/config/database.php';
require_once dirname(__DIR__, 2) . '/app/services/ClinicFeedback.php';
require_once dirname(__DIR__, 2) . '/app/services/PatientNotification.php';

$apply = in_array('--apply', $argv, true);
$db = auth_db();
$repairReferralIds = [1, 2, 3, 5];
$repairApeIds = [48, 49, 50, 51, 52, 53, 66];

function audit_rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function audit_ids(array $rows, string $key): array
{
    return array_map(static fn(array $row): int => (int) $row[$key], $rows);
}

function audit_pending_feedback(PDO $db): array
{
    return audit_rows($db, "
        SELECT v.visit_id, v.patient_person_id, v.visit_datetime, v.visit_purpose, v.chief_complaint
        FROM visits v
        JOIN students s ON s.person_id = v.patient_person_id
        JOIN accounts a ON a.person_id = s.person_id AND a.account_status = 'active'
        LEFT JOIN clinic_feedback f ON f.visit_id = v.visit_id
        WHERE v.status = 'Completed' AND f.feedback_id IS NULL
        ORDER BY v.visit_datetime ASC, v.visit_id ASC
    ");
}

function audit_report_path(): string
{
    $preferred = '/var/backups/cliniq';
    $fallback = dirname(__DIR__, 2) . '/storage/audit';
    $directory = is_dir($preferred) && is_writable($preferred) ? $preferred : $fallback;
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    return $directory . '/full-audit-repair-' . date('Ymd-His') . '.json';
}

$before = [
    'completed_without_timestamp' => audit_rows($db, "
        SELECT visit_id, patient_person_id, status, visit_datetime, completed_at, updated_at
        FROM visits
        WHERE status = 'Completed' AND completed_at IS NULL
        ORDER BY visit_id
    "),
    'orphan_referrals' => audit_rows($db, 'SELECT referral_id, patient_person_id, visit_id, referred_to, status FROM referrals WHERE visit_id IS NULL ORDER BY referral_id'),
    'non_student_ape' => audit_rows($db, '
        SELECT ar.ape_id, ar.patient_id, ar.entry_mode, ar.academic_year
        FROM ape_records ar
        LEFT JOIN students s ON s.person_id = ar.patient_id
        WHERE s.person_id IS NULL
        ORDER BY ar.ape_id
    '),
    'pending_feedback' => audit_pending_feedback($db),
];

$expectedReferralIds = $repairReferralIds;
$actualReferralIds = audit_ids($before['orphan_referrals'], 'referral_id');
$expectedApeIds = $repairApeIds;
$actualApeIds = audit_ids($before['non_student_ape'], 'ape_id');

if ($apply) {
    sort($expectedReferralIds);
    sort($actualReferralIds);
    sort($expectedApeIds);
    sort($actualApeIds);
    if ($actualReferralIds !== $expectedReferralIds) {
        throw new RuntimeException('Refusing referral repair: the audited orphan ID set changed.');
    }
    if ($actualApeIds !== $expectedApeIds) {
        throw new RuntimeException('Refusing APE repair: the audited non-student ID set changed.');
    }

    $db->beginTransaction();
    try {
        $repairTimestamp = $db->prepare("UPDATE visits SET completed_at = COALESCE(updated_at, visit_datetime) WHERE visit_id = ? AND status = 'Completed' AND completed_at IS NULL");
        foreach ($before['completed_without_timestamp'] as $visit) {
            $repairTimestamp->execute([(int) $visit['visit_id']]);
        }

        $referralDelete = $db->prepare('DELETE FROM referrals WHERE referral_id = ? AND visit_id IS NULL');
        foreach ($repairReferralIds as $referralId) {
            $referralDelete->execute([$referralId]);
        }

        $apeDelete = $db->prepare('DELETE FROM ape_records WHERE ape_id = ?');
        foreach ($repairApeIds as $apeId) {
            $apeDelete->execute([$apeId]);
        }

        $notificationCount = 0;
        foreach ($before['pending_feedback'] as $visit) {
            if (patient_notification_for_feedback_required($db, $visit, null) !== null) {
                $notificationCount++;
            }
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
} else {
    $notificationCount = 0;
}

$after = [
    'completed_without_timestamp' => audit_rows($db, "SELECT visit_id FROM visits WHERE status = 'Completed' AND completed_at IS NULL ORDER BY visit_id"),
    'orphan_referrals' => audit_rows($db, 'SELECT referral_id, patient_person_id, visit_id FROM referrals WHERE visit_id IS NULL ORDER BY referral_id'),
    'non_student_ape' => audit_rows($db, '
        SELECT ar.ape_id, ar.patient_id, ar.entry_mode, ar.academic_year
        FROM ape_records ar
        LEFT JOIN students s ON s.person_id = ar.patient_id
        WHERE s.person_id IS NULL
        ORDER BY ar.ape_id
    '),
    'pending_feedback' => audit_pending_feedback($db),
];

if ($apply && ($after['completed_without_timestamp'] !== [] || $after['orphan_referrals'] !== [] || $after['non_student_ape'] !== [])) {
    throw new RuntimeException('Post-repair integrity check failed.');
}

$report = [
    'mode' => $apply ? 'apply' : 'audit',
    'generated_at' => date(DATE_ATOM),
    'before' => $before,
    'after' => $after,
    'notification_calls' => $notificationCount,
    'deleted_referral_ids' => $apply ? $repairReferralIds : [],
    'deleted_ape_ids' => $apply ? $repairApeIds : [],
    'notes' => [
        'APE rows were removed with their permitted dependent rows through database foreign-key cascades; patient and person rows were retained.',
        'Feedback notifications are source-keyed by completed visit and deduplicated by PatientNotification.',
        'Migration history rows were not modified.',
    ],
];

$reportPath = audit_report_path();
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo json_encode([
    'mode' => $report['mode'],
    'report' => $reportPath,
    'completed_without_timestamp' => count($after['completed_without_timestamp']),
    'orphan_referrals' => count($after['orphan_referrals']),
    'non_student_ape' => count($after['non_student_ape']),
    'pending_feedback' => count($after['pending_feedback']),
    'notification_calls' => $notificationCount,
], JSON_PRETTY_PRINT), PHP_EOL;
