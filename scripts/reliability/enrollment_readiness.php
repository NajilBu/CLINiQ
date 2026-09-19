<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/config/database.php';
require_once dirname(__DIR__, 2) . '/app/services/ApeWorkflow.php';
require_once dirname(__DIR__, 2) . '/app/services/BackupService.php';

$db = auth_db();
$checks = [];

foreach (['max_connections', 'thread_cache_size', 'table_open_cache', 'innodb_buffer_pool_size'] as $variable) {
    $stmt = $db->prepare('SHOW VARIABLES LIKE ?');
    $stmt->execute([$variable]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $checks['db_' . $variable] = $row === false ? null : $row[1];
}

$status = $db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running', 'Max_used_connections')")->fetchAll(PDO::FETCH_KEY_PAIR);
$checks['threads_connected'] = (int) ($status['Threads_connected'] ?? 0);
$checks['threads_running'] = (int) ($status['Threads_running'] ?? 0);
$checks['max_used_connections'] = (int) ($status['Max_used_connections'] ?? 0);

$checks['tables'] = (int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
$checks['registration_rows'] = (int) $db->query('SELECT COUNT(*) FROM patient_registration_verifications')->fetchColumn();
$checks['ape_document_rows'] = (int) $db->query('SELECT COUNT(*) FROM ape_documents')->fetchColumn();

$projectRoot = dirname(__DIR__, 2);
$documentAudit = [];
foreach ($db->query('SELECT document_id, file_path FROM ape_documents') as $row) {
    $filename = basename(str_replace('\\', '/', trim((string) $row['file_path'])));
    $found = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) === 1
        && (is_readable($projectRoot . '/storage/documents/ape/' . $filename)
            || is_readable($projectRoot . '/public/uploads/ape/' . $filename));
    if (!$found) {
        $documentAudit[] = (int) $row['document_id'];
    }
}
$checks['missing_ape_document_ids'] = $documentAudit;

$checks['backup_status'] = cliniq_backup_status();
$checks['disk_free_bytes'] = disk_free_space($projectRoot) ?: null;
$checks['disk_total_bytes'] = disk_total_space($projectRoot) ?: null;

$warnings = [];
if (count($documentAudit) > 0) {
    $warnings[] = 'Missing APE document files: ' . implode(', ', $documentAudit);
}
if (($checks['backup_status']['state'] ?? '') !== 'success') {
    $warnings[] = 'Backup status is ' . (string) ($checks['backup_status']['state'] ?? 'unknown');
}
if ($checks['disk_free_bytes'] !== null && $checks['disk_total_bytes'] !== null && $checks['disk_free_bytes'] < ($checks['disk_total_bytes'] * 0.15)) {
    $warnings[] = 'Less than 15% disk space remains.';
}

$checks['state'] = $warnings === [] ? 'ready_for_load_test' : 'warning';
$checks['warnings'] = $warnings;
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($warnings === [] ? 0 : 2);
