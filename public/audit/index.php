<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AuditLog.php';

require_login();
$user = current_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('You are not authorized to view the audit log.');
}
ensure_audit_log_schema();

$module = trim((string) ($_GET['module'] ?? ''));
$action = trim((string) ($_GET['action'] ?? ''));
$actor = trim((string) ($_GET['actor'] ?? ''));
$outcome = trim((string) ($_GET['outcome'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

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

$count = auth_db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN people p ON p.id = a.actor_person_id WHERE {$whereSql}");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

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
    WHERE {$whereSql} ORDER BY a.created_at DESC, a.id DESC LIMIT ?, ?");
$parameterIndex = 1;
foreach ($params as $value) {
    $stmt->bindValue($parameterIndex++, $value);
}
$stmt->bindValue($parameterIndex++, $offset, PDO::PARAM_INT);
$stmt->bindValue($parameterIndex, $perPage, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
$modules = auth_db()->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$actions = auth_db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

render_header('Audit Log');
render_clinic_command_header('Governance', 'System Audit Log', 'Review sensitive actions across CLINiQ.');
?>
<div class="audit-page">
<section class="clinic-card overflow-hidden mb-6">
    <div class="p-6 border-b border-slate-100"><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Audit Filters</h2><p class="text-xs font-bold text-slate-500 mb-0">Filter sensitive system activity by module, actor, result, or date.</p></div>
    <form method="get" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="lg:col-span-2"><label class="clinic-label" for="auditSearch">Search</label><input id="auditSearch" class="clinic-input" name="search" value="<?= e($search) ?>" placeholder="Search names, ID numbers, or affected records"></div>
        <div><label class="clinic-label" for="auditModule">Activity area</label><select id="auditModule" class="clinic-select" name="module"><option value="">All activity areas</option><?php foreach ($modules as $value): ?><option value="<?= e($value) ?>" <?= $module === $value ? 'selected' : '' ?>><?= e(audit_log_module_label((string) $value)) ?></option><?php endforeach; ?></select></div>
        <div><label class="clinic-label" for="auditAction">Activity</label><select id="auditAction" class="clinic-select" name="action"><option value="">All activities</option><?php foreach ($actions as $value): ?><option value="<?= e($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= e(audit_log_action_label((string) $value)) ?></option><?php endforeach; ?></select></div>
        <div><label class="clinic-label" for="auditOutcome">Result</label><select id="auditOutcome" class="clinic-select" name="outcome"><option value="">All results</option><option value="success" <?= $outcome === 'success' ? 'selected' : '' ?>>Successful</option><option value="unsuccessful" <?= in_array($outcome, ['unsuccessful', 'failure', 'failed'], true) ? 'selected' : '' ?>>Unsuccessful</option></select></div>
        <div><label class="clinic-label" for="auditFrom">Date from</label><input id="auditFrom" class="clinic-input" type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
        <div><label class="clinic-label" for="auditTo">Date to</label><input id="auditTo" class="clinic-input" type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
        <div class="flex items-end"><a class="btn btn-outline text-decoration-none w-full justify-center" href="index.php">Clear</a></div>
    </form>
</section>
<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100"><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Activity history</h2><p class="text-xs font-bold text-slate-500 mb-0"><?= number_format($total) ?> matching activities</p></div>
    <div class="overflow-x-auto"><table class="audit-table"><thead><tr><th>Date and time</th><th>Performed by</th><th>Activity</th><th>Affected record</th><th>Result</th><th>Additional information</th></tr></thead><tbody>
    <?php foreach ($logs as $log): ?>
        <tr>
            <td class="audit-nowrap"><?= e(date('M d, Y g:i A', strtotime($log['created_at']))) ?></td>
            <td>
                <p class="audit-primary-text"><?= e($log['actor_name'] ?: ucfirst((string) $log['actor_type'])) ?></p>
                <p class="audit-secondary-text"><?= e($log['actor_id_number'] ?: audit_log_module_label((string) $log['actor_type'])) ?></p>
            </td>
            <td>
                <p class="audit-primary-text"><?= e(audit_log_action_label((string) $log['action'])) ?></p>
                <p class="audit-secondary-text"><?= e(audit_log_module_label((string) $log['module'])) ?></p>
            </td>
            <td class="audit-target-text"><?= e(audit_log_target_label($log)) ?></td>
            <td><span class="badge <?= e($log['outcome'] === 'success' ? 'badge-completed' : 'badge-high') ?>"><?= e($log['outcome'] === 'success' ? 'Successful' : 'Failed') ?></span></td>
            <td class="audit-detail-text">
                <p class="audit-detail-summary"><?= e(audit_log_metadata_summary($log['metadata'] ?? null)) ?></p>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6" class="p-8 text-center text-sm font-bold text-slate-400">No audit events match these filters.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($pages > 1): ?><div class="p-4 flex items-center justify-between border-t border-slate-100"><span class="text-xs font-bold text-slate-500">Page <?= $page ?> of <?= $pages ?></span><div class="flex gap-2"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline text-decoration-none" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="btn btn-sm btn-outline text-decoration-none" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></div></div><?php endif; ?>
</section>
</div>
<script>
(() => {
    const form = document.querySelector('.audit-page form[method="get"]');
    if (!form) return;
    form.querySelectorAll('select, input[type="date"]').forEach((control) => {
        control.addEventListener('change', () => form.submit());
    });
    const search = form.querySelector('input[name="search"]');
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            form.submit();
        }
    });
})();
</script>
<?php render_footer(); ?>
