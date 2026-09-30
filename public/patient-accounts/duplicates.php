<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/PatientAccountMerge.php';
require_login();
$user = current_user();
if (!can_manage_patient_accounts($user)) { http_response_code(403); exit('Administrator access required.'); }
header('Cache-Control: no-store');
$db = auth_db();
$error = '';
$review = null;
$keepId = (int) ($_POST['keep_id'] ?? $_GET['keep_id'] ?? 0);
$removeId = (int) ($_POST['remove_id'] ?? $_GET['remove_id'] ?? 0);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_request_is_valid()) throw new InvalidArgumentException('Reload the page and try again.');
        if (($_POST['confirm'] ?? '') !== '1') throw new InvalidArgumentException('Confirm that both records belong to the same patient.');
        $choices = $_POST['choices'] ?? [];
        if (!is_array($choices)) throw new InvalidArgumentException('Invalid field choices.');
        merge_patient_accounts($keepId, $removeId, $choices, (string) ($_POST['fingerprint'] ?? ''), $user);
        flash_message('success', 'Accounts merged. The selected account and linked history have been retained.');
        header('Location: ' . app_url('patient-accounts/duplicates.php'));
        exit;
    }
    if ($keepId && $removeId) $review = patient_merge_review($db, $keepId, $removeId);
} catch (Throwable $e) {
    error_log('Patient account duplicate review: ' . $e->getMessage());
    $error = $e instanceof PDOException ? 'Unable to load account records. Please try again.' : $e->getMessage();
}
$accounts = patient_duplicate_accounts($db);
$pairs = patient_duplicate_pairs($accounts);
$byId = array_column($accounts, null, 'account_id');
set_page_back_link(app_url('patient-accounts/index.php'), 'Back to Patient Accounts');
render_header('Duplicate Patient Accounts');
render_clinic_command_header('Account Administration', 'Duplicate Patient Accounts',
    'Matches are suggestions. Verify identity before merging; a matching name alone does not prove duplication.');
?>
<div class="duplicate-review-page space-y-6">
<?php if ($error): ?><div class="duplicate-review-notice" role="alert"><span class="material-symbols-outlined" aria-hidden="true">error</span><div><?= e($error) ?> <a href="<?= e(app_url('patient-accounts/duplicates.php')) ?>">Return to duplicate review</a></div></div><?php endif; ?>
<?php if ($review): ?>
<section class="clinic-card overflow-hidden duplicate-merge-card">
    <header class="duplicate-merge-header">
        <div class="duplicate-merge-header-icon"><span class="material-symbols-outlined" aria-hidden="true">merge_type</span></div>
        <div><p class="appointment-availability-eyebrow">Identity review</p><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Review account merge</h2><p class="text-sm font-semibold text-slate-500 mb-0">Choose the values to retain before combining these two patient accounts.</p></div>
    </header>
    <form method="post" id="merge-form" class="duplicate-merge-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="keep_id" value="<?= $keepId ?>">
        <input type="hidden" name="remove_id" value="<?= $removeId ?>">
        <input type="hidden" name="fingerprint" value="<?= e($review['fingerprint']) ?>">
        <div class="duplicate-account-compare">
            <article class="duplicate-account-card is-kept"><span class="duplicate-account-label"><span class="material-symbols-outlined" aria-hidden="true">verified</span> Account to keep</span><strong><?= e($byId[$keepId]['full_name']) ?></strong><span><?= e($review['keep']['people']['id_number']) ?> · Account <?= $keepId ?></span></article>
            <span class="duplicate-account-merge-icon material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            <article class="duplicate-account-card is-removed"><span class="duplicate-account-label"><span class="material-symbols-outlined" aria-hidden="true">person_remove</span> Duplicate to remove</span><strong><?= e($byId[$removeId]['full_name']) ?></strong><span><?= e($review['remove']['people']['id_number']) ?> · Account <?= $removeId ?></span></article>
        </div>
        <div class="duplicate-merge-guidance"><span class="material-symbols-outlined" aria-hidden="true">info</span><p>The retained account keeps its ID number, email, password, and sign-in access. A verified backup is required; conflicting linked records stop the merge before anything is saved.</p></div>
        <div class="duplicate-choice-table-wrap"><table class="duplicate-choice-table"><caption>Profile differences to resolve</caption><thead><tr><th>Profile field</th><th>Retained account</th><th>Duplicate</th><th>Keep this value</th></tr></thead><tbody>
        <?php $other = patient_merge_fields($review['remove']); foreach (patient_merge_fields($review['keep']) as $key => $value): if ($value === ($other[$key] ?? null)) continue; ?>
            <tr><th scope="row"><?= e(str_replace(['.', '_'], ' ', $key)) ?></th><td><?= e((string) ($value ?? '—')) ?></td><td><?= e((string) ($other[$key] ?? '—')) ?></td><td><select class="clinic-input" name="choices[<?= e($key) ?>]" required><option value="">Choose…</option><option value="keep">Retained account</option><option value="duplicate">Duplicate</option></select></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <details class="duplicate-linked-records"><summary><span class="material-symbols-outlined" aria-hidden="true">folder_shared</span>Linked records to transfer</summary><ul><?php foreach ($review['counts'] as $name => $count): if (!$count) continue; ?><li><span><?= e($name) ?></span><strong><?= e((string) $count) ?></strong></li><?php endforeach; ?></ul></details>
        <label class="duplicate-merge-confirm"><input type="checkbox" name="confirm" value="1" required><span>I verified that these accounts belong to the same patient. Merge their records and remove the duplicate account.</span></label>
        <div class="duplicate-merge-actions"><a class="btn btn-outline text-decoration-none" href="<?= e(app_url('patient-accounts/duplicates.php')) ?>">Cancel review</a><button class="btn btn-danger" type="submit" data-confirm-submit data-confirm-type="danger" data-confirm-title="Merge these patient accounts?" data-confirm-message="The duplicate account will be removed after its selected records are transferred. This cannot be undone." data-confirm-toast="Creating backup and merging accounts..."><span class="material-symbols-outlined" aria-hidden="true">merge_type</span>Merge into retained account</button></div>
        <p id="merge-progress" hidden role="status">Creating and verifying the backup, then merging. Please keep this page open.</p>
    </form>
</section>
<?php endif; ?>
<section class="clinic-card overflow-hidden duplicate-pairs-card">
    <header class="duplicate-pairs-header"><div><p class="appointment-availability-eyebrow">Possible matches</p><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= count($pairs) ?> account pair<?= count($pairs) === 1 ? '' : 's' ?> to review</h2><p class="text-xs font-bold text-slate-500 mb-0">Select the account to retain, then verify each conflicting value.</p></div><button type="button" class="btn btn-outline" id="export-duplicates"><span class="material-symbols-outlined" aria-hidden="true">download</span>Export review</button></header>
    <div class="duplicate-pair-list">
    <?php if (!$pairs): ?><div class="appointment-timeline-empty"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span><div><strong>No duplicate matches found</strong><span>There are no matching IDs, email addresses, or names to review.</span></div></div><?php endif; ?>
    <?php foreach ($pairs as $pair): $left = $byId[$pair['left']]; $right = $byId[$pair['right']]; ?>
    <article class="duplicate-pair-card">
        <header><span class="badge badge-pending">Matched by <?= e(implode(', ', $pair['reasons'])) ?></span><span class="text-xs font-bold text-slate-500">Choose the account to retain</span></header>
        <?php foreach ([[$left, $right], [$right, $left]] as [$record, $duplicate]): ?>
        <div class="duplicate-account-option"><div><strong><?= e($record['full_name']) ?></strong><span><?= e($record['id_number']) ?> · <?= e((string) $record['email']) ?></span><small><?= e($record['patient_type']) ?> · <?= e($record['account_status']) ?> · Account <?= (int) $record['account_id'] ?></small></div>
            <a class="btn btn-outline text-decoration-none" href="?keep_id=<?= (int) $record['account_id'] ?>&amp;remove_id=<?= (int) $duplicate['account_id'] ?>">Keep &amp; review <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a>
        </div>
        <?php endforeach; ?>
    </article>
    <?php endforeach; ?>
    </div>
</section>
</div>
<script src="<?= app_url('assets/vendor/sheetjs/xlsx.full.min.js?v=0.20.3') ?>"></script>
<script>
(() => {
    const accounts = <?= json_encode($accounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const pairs = <?= json_encode($pairs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.getElementById('export-duplicates').addEventListener('click', () => {
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(accounts), 'Patient Accounts');
        const records = Object.fromEntries(accounts.map(record => [record.account_id, record]));
        const matches = pairs.map(pair => ({
            'Account A': pair.left, 'Name A': records[pair.left].full_name, 'ID A': records[pair.left].id_number, 'Email A': records[pair.left].email,
            'Account B': pair.right, 'Name B': records[pair.right].full_name, 'ID B': records[pair.right].id_number, 'Email B': records[pair.right].email,
            'Match reasons': pair.reasons.join(', '), 'Action': 'Review identity in Patient Accounts before merging'
        }));
        XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(matches.length ? matches : [{'Result': 'No possible duplicates found'}]), 'Duplicate Check');
        XLSX.writeFile(workbook, 'patient-accounts-duplicate-check.xlsx');
    });
    document.getElementById('merge-form')?.addEventListener('submit', event => {
        event.target.querySelector('button[type="submit"]').disabled = true;
        document.getElementById('merge-progress').hidden = false;
    });
})();
</script>
<?php render_footer(); ?>
