<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/config/database.php';
require_once dirname(__DIR__, 2) . '/app/helpers/data_normalization.php';
require_once dirname(__DIR__, 2) . '/app/services/AuditLog.php';

$apply = in_array('--apply', $argv, true);
$db = auth_db();
$rows = $db->query('SELECT p.id, p.first_name, p.middle_name, p.last_name, a.email, pt.guardian_or_contact_name, pt.emergency_instructions FROM people p LEFT JOIN accounts a ON a.person_id = p.id LEFT JOIN patients pt ON pt.person_id = p.id ORDER BY p.id')->fetchAll();
$changed = 0;
if ($apply) $db->beginTransaction();
try {
    foreach ($rows as $row) {
        $after = [
            'first_name' => cliniq_normalize_person_name($row['first_name']),
            'middle_name' => (($value = cliniq_normalize_person_name($row['middle_name'])) === '') ? null : $value,
            'last_name' => cliniq_normalize_person_name($row['last_name']),
            'email' => $row['email'] === null ? null : cliniq_normalize_email($row['email']),
            'guardian_or_contact_name' => $row['guardian_or_contact_name'] === null ? null : cliniq_normalize_person_name($row['guardian_or_contact_name']),
            'emergency_instructions' => $row['emergency_instructions'] === null ? null : cliniq_normalize_free_text($row['emergency_instructions']),
        ];
        $before = array_intersect_key($row, $after);
        if ($before === $after) continue;
        $changed++;
        if (!$apply) continue;
        $db->prepare('UPDATE people SET first_name=?, middle_name=?, last_name=? WHERE id=?')->execute([$after['first_name'], $after['middle_name'] ?: null, $after['last_name'], $row['id']]);
        if ($row['email'] !== null) $db->prepare('UPDATE accounts SET email=? WHERE person_id=?')->execute([$after['email'], $row['id']]);
        if ($row['guardian_or_contact_name'] !== null || $row['emergency_instructions'] !== null) $db->prepare('UPDATE patients SET guardian_or_contact_name=?, emergency_instructions=? WHERE person_id=?')->execute([$after['guardian_or_contact_name'], $after['emergency_instructions'], $row['id']]);
        audit_log_event('maintenance', 'user_data_normalized', null, 'system-normalization', 'person', (int) $row['id'], ['before' => $before, 'after' => $after]);
    }
    if ($apply) $db->commit();
} catch (Throwable $e) { if ($apply && $db->inTransaction()) $db->rollBack(); throw $e; }
echo json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'changed_records' => $changed], JSON_PRETTY_PRINT), PHP_EOL;
