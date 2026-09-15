<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AuditLog.php';

require_login();
$user = current_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('You are not authorized to print the audit log.');
}
ensure_audit_log_schema();

$module = trim((string) ($_GET['module'] ?? ''));
$action = trim((string) ($_GET['action'] ?? ''));
$actor = trim((string) ($_GET['actor'] ?? ''));
$outcome = trim((string) ($_GET['outcome'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$where = ['1 = 1'];
$params = [];
foreach ([['a.module', $module], ['a.action', $action], ['a.actor_type', $actor]] as [$field, $value]) {
    if ($value !== '') {
        $where[] = "{$field} = ?";
        $params[] = $value;
    }
}
if ($outcome !== '') {
    if (in_array($outcome, ['unsuccessful', 'failure', 'failed'], true)) {
        $where[] = "a.outcome IN ('failure', 'failed')";
    } else {
        $where[] = 'a.outcome = ?';
        $params[] = $outcome;
    }
}
if ($search !== '') {
    $where[] = '(a.target_type LIKE ? OR CAST(a.target_id AS CHAR) LIKE ? OR a.metadata LIKE ? OR CONCAT_WS(" ", p.first_name, p.last_name) LIKE ? OR p.id_number LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'DATE(a.created_at) >= ?';
    $params[] = $dateFrom;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'DATE(a.created_at) <= ?';
    $params[] = $dateTo;
}
$whereSql = implode(' AND ', $where);

$stmt = auth_db()->prepare("SELECT a.*,
        TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS actor_name,
        p.id_number AS actor_id_number,
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', direct_target.first_name, direct_target.middle_name, direct_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', account_target.first_name, account_target.middle_name, account_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', ape_target.first_name, ape_target.middle_name, ape_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', visit_target.first_name, visit_target.middle_name, visit_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', alert_target.first_name, alert_target.middle_name, alert_target.last_name)), '')
        ) AS target_name,
        COALESCE(direct_target.id_number, account_target.id_number, ape_target.id_number, visit_target.id_number, alert_target.id_number) AS target_id_number,
        inventory_item.item_name AS target_item_name
    FROM audit_logs a
    LEFT JOIN people p ON p.id = a.actor_person_id
    LEFT JOIN people direct_target ON direct_target.id = a.target_id AND a.target_type IN ('person', 'patient')
    LEFT JOIN accounts target_account ON target_account.id = a.target_id AND a.target_type = 'account'
    LEFT JOIN people account_target ON account_target.id = target_account.person_id
    LEFT JOIN ape_records target_ape ON target_ape.ape_id = a.target_id AND a.target_type = 'ape_record'
    LEFT JOIN people ape_target ON ape_target.id = target_ape.patient_id
    LEFT JOIN visits target_visit ON target_visit.visit_id = a.target_id AND a.target_type = 'visit'
    LEFT JOIN people visit_target ON visit_target.id = target_visit.patient_person_id
    LEFT JOIN nurse_alerts target_alert ON target_alert.id = a.target_id AND a.target_type = 'nurse_alert'
    LEFT JOIN people alert_target ON alert_target.id = target_alert.patient_id
    LEFT JOIN inventory_transactions target_inventory ON target_inventory.transaction_id = a.target_id AND a.target_type = 'inventory_transaction'
    LEFT JOIN inventory_items inventory_item ON inventory_item.item_id = target_inventory.item_id
    WHERE {$whereSql}
    ORDER BY a.created_at DESC, a.id DESC");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$clinicProfile = clinic_profile_settings();
$clinicName = trim((string) ($clinicProfile['system_name'] ?? 'CLINiQ')) ?: 'CLINiQ';
$clinicDepartment = trim((string) ($clinicProfile['department'] ?? 'University Health Services')) ?: 'University Health Services';
$clinicLogoUrl = app_url(clinic_profile_logo_path($clinicProfile));
$preparedBy = trim((string) ($user['name'] ?? 'System Administrator')) ?: 'System Administrator';
$preparedById = trim((string) ($user['id_number'] ?? ''));
$backQuery = http_build_query(array_filter([
    'search' => $search,
    'module' => $module,
    'action' => $action,
    'actor' => $actor,
    'outcome' => $outcome,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], static fn ($value): bool => $value !== ''));

$filterLabels = [];
if ($search !== '') {
    $filterLabels[] = 'Search: ' . $search;
}
if ($module !== '') {
    $filterLabels[] = 'Activity area: ' . audit_log_module_label($module);
}
if ($action !== '') {
    $filterLabels[] = 'Activity: ' . audit_log_action_label($action);
}
if ($actor !== '') {
    $filterLabels[] = 'Performed by type: ' . ucfirst(str_replace('_', ' ', $actor));
}
if ($outcome !== '') {
    $filterLabels[] = 'Result: ' . (in_array($outcome, ['unsuccessful', 'failure', 'failed'], true) ? 'Unsuccessful' : 'Successful');
}
if ($dateFrom !== '') {
    $filterLabels[] = 'From: ' . date('M j, Y', strtotime($dateFrom));
}
if ($dateTo !== '') {
    $filterLabels[] = 'To: ' . date('M j, Y', strtotime($dateTo));
}
if (!$filterLabels) {
    $filterLabels[] = 'All recorded activities';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log | <?= e($clinicName) ?></title>
    <style>
        :root { color-scheme: light; --green: #236b45; --ink: #17261d; --muted: #586b61; --line: #d9e5dd; --soft: #f2f8f4; }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: #eef4f0; font-family: Arial, Helvetica, sans-serif; }
        .toolbar { position: sticky; top: 0; z-index: 2; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 24px; background: rgba(255,255,255,.96); border-bottom: 1px solid var(--line); box-shadow: 0 8px 20px rgba(23,38,29,.08); }
        .toolbar strong { display: block; font-size: 15px; }
        .toolbar span { display: block; margin-top: 3px; color: var(--muted); font-size: 12px; }
        .toolbar-actions { display: flex; gap: 10px; }
        .toolbar a, .toolbar button { border: 1px solid #bfd2c5; border-radius: 10px; padding: 10px 16px; color: var(--ink); background: white; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar button { color: white; border-color: var(--green); background: var(--green); }
        .document { width: min(1200px, calc(100% - 32px)); margin: 28px auto; padding: 34px; background: white; border-radius: 18px; box-shadow: 0 18px 45px rgba(23,38,29,.10); }
        .report-header { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 20px; border-bottom: 3px solid var(--green); }
        .report-brand { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .clinic-logo { width: 66px; height: 66px; flex: 0 0 66px; border: 1px solid var(--line); border-radius: 50%; background: white; object-fit: contain; }
        .eyebrow { margin: 0 0 6px; color: var(--green); font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 30px; }
        .clinic-name { margin: 7px 0 0; color: var(--muted); font-size: 14px; font-weight: 700; }
        .clinic-department { margin: 3px 0 0; color: var(--muted); font-size: 11px; }
        .report-meta { min-width: 245px; font-size: 12px; line-height: 1.55; }
        .report-meta p { margin: 0 0 5px; }
        .report-meta strong { color: var(--ink); }
        .filters { display: flex; flex-wrap: wrap; gap: 7px; margin: 18px 0; }
        .filter { padding: 6px 9px; color: #315d45; background: var(--soft); border: 1px solid #d7e9dc; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .summary { margin: 0 0 13px; color: var(--muted); font-size: 12px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
        thead { display: table-header-group; }
        th { padding: 10px 8px; color: #385447; background: var(--soft); border: 1px solid var(--line); text-align: left; font-size: 9px; letter-spacing: .05em; text-transform: uppercase; }
        td { padding: 9px 8px; border: 1px solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th:nth-child(1), td:nth-child(1) { width: 15%; }
        th:nth-child(2), td:nth-child(2) { width: 18%; }
        th:nth-child(3), td:nth-child(3) { width: 22%; }
        th:nth-child(4), td:nth-child(4) { width: 18%; }
        th:nth-child(5), td:nth-child(5) { width: 10%; }
        th:nth-child(6), td:nth-child(6) { width: 17%; }
        .primary { display: block; font-weight: 700; }
        .secondary { display: block; margin-top: 3px; color: var(--muted); font-size: 9px; }
        .result { font-weight: 800; }
        .result.success { color: #087443; }
        .result.failed { color: #bd1e2d; }
        .empty { padding: 30px; text-align: center; color: var(--muted); }
        @page { size: A4 landscape; margin: 12mm; }
        @media print {
            body { background: white; }
            .toolbar { display: none !important; }
            .document { width: auto; margin: 0; padding: 0; border-radius: 0; box-shadow: none; }
            .report-header { break-after: avoid; page-break-after: avoid; }
            .filters, .summary { break-after: avoid; page-break-after: avoid; }
        }
        @media (max-width: 760px) {
            .toolbar, .report-header { align-items: flex-start; flex-direction: column; }
            .document { width: calc(100% - 20px); margin: 10px auto; padding: 20px; overflow-x: auto; }
            table { min-width: 900px; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <div><strong>Audit Log Print Preview</strong><span>All activities matching the selected filters are included.</span></div>
    <div class="toolbar-actions">
        <a href="index.php<?= $backQuery !== '' ? '?' . e($backQuery) : '' ?>">Back to Audit Log</a>
        <button type="button" onclick="window.print()">Print</button>
    </div>
</div>
<main class="document">
    <header class="report-header">
        <div class="report-brand">
            <img class="clinic-logo" src="<?= e($clinicLogoUrl) ?>" alt="<?= e($clinicDepartment) ?> logo">
            <div>
                <p class="eyebrow">Governance and accountability</p>
                <h1>Audit Log</h1>
                <p class="clinic-name"><?= e($clinicName) ?></p>
                <p class="clinic-department"><?= e($clinicDepartment) ?></p>
            </div>
        </div>
        <div class="report-meta">
            <p><strong>Generated:</strong> <?= e(date('M j, Y g:i A')) ?></p>
            <p><strong>Prepared by:</strong> <?= e($preparedBy) ?><?= $preparedById !== '' ? ' (' . e($preparedById) . ')' : '' ?></p>
            <p><strong>Activities included:</strong> <?= number_format(count($logs)) ?></p>
        </div>
    </header>
    <div class="filters" aria-label="Applied filters">
        <?php foreach ($filterLabels as $label): ?><span class="filter"><?= e($label) ?></span><?php endforeach; ?>
    </div>
    <p class="summary"><?= number_format(count($logs)) ?> matching audit activities</p>
    <table>
        <thead><tr><th>Date and time</th><th>Performed by</th><th>Activity</th><th>Affected record</th><th>Result</th><th>Additional information</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= e(date('M d, Y g:i A', strtotime($log['created_at']))) ?></td>
                <td><span class="primary"><?= e($log['actor_name'] ?: ucfirst((string) $log['actor_type'])) ?></span><span class="secondary"><?= e($log['actor_id_number'] ?: audit_log_module_label((string) $log['actor_type'])) ?></span></td>
                <td><span class="primary"><?= e(audit_log_action_label((string) $log['action'])) ?></span><span class="secondary"><?= e(audit_log_module_label((string) $log['module'])) ?></span></td>
                <td><?= e(audit_log_target_label($log)) ?></td>
                <td><span class="result <?= $log['outcome'] === 'success' ? 'success' : 'failed' ?>"><?= e($log['outcome'] === 'success' ? 'Successful' : 'Failed') ?></span></td>
                <td><?= e(audit_log_metadata_summary($log['metadata'] ?? null)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6" class="empty">No audit activities match the selected filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</main>
</body>
</html>
