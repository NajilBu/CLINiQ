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
render_header('Duplicate Patient Accounts');
render_clinic_command_header('Account Administration', 'Duplicate Patient Accounts',
    'Matches are suggestions. Verify identity before merging; a matching name alone does not prove duplication.',
    '<a class="btn btn-outline" href="' . e(app_url('patient-accounts/index.php')) . '">Back to Patient Accounts</a>');
?>
<?php if ($error): ?><div class="clinic-card p-5 mb-5" role="alert"><?= e($error) ?> <a href="<?= e(app_url('patient-accounts/duplicates.php')) ?>">Return to duplicate review</a></div><?php endif; ?>
<?php if ($review): ?>
<section class="clinic-card p-5 mb-5">
    <h2>Review merge</h2>
    <p><strong>Keep:</strong> <?= e($review['keep']['people']['id_number']) ?> — <?= e($byId[$keepId]['full_name']) ?> (account <?= $keepId ?>)</p>
    <p><strong>Remove:</strong> <?= e($review['remove']['people']['id_number']) ?> — <?= e($byId[$removeId]['full_name']) ?> (account <?= $removeId ?>)</p>
    <p>The retained account keeps its ID number, email, password and access to login. Profile differences require a choice below. A verified backup is required before the merge. Linked records with conflicting unique keys will stop the merge without saving changes.</p>
    <form method="post" id="merge-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="keep_id" value="<?= $keepId ?>">
        <input type="hidden" name="remove_id" value="<?= $removeId ?>">
        <input type="hidden" name="fingerprint" value="<?= e($review['fingerprint']) ?>">
        <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>Profile field</th><th>Retained account</th><th>Duplicate</th><th>Use value from</th></tr></thead><tbody>
        <?php $other = patient_merge_fields($review['remove']); foreach (patient_merge_fields($review['keep']) as $key => $value): if ($value === ($other[$key] ?? null)) continue; ?>
            <tr><td class="p-3"><?= e(str_replace(['.', '_'], ' ', $key)) ?></td><td class="p-3"><?= e((string) ($value ?? '—')) ?></td><td class="p-3"><?= e((string) ($other[$key] ?? '—')) ?></td><td class="p-3"><select class="clinic-input" name="choices[<?= e($key) ?>]" required><option value="">Choose…</option><option value="keep">Retained account</option><option value="duplicate">Duplicate</option></select></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <details class="my-4"><summary>Linked records to transfer</summary><ul><?php foreach ($review['counts'] as $name => $count): if (!$count) continue; ?><li><?= e($name) ?>: <?= $count ?></li><?php endforeach; ?></ul></details>
        <label class="block my-4"><input type="checkbox" name="confirm" value="1" required> I verified that these accounts belong to the same patient. Merge their records and remove the duplicate account.</label>
        <button class="btn btn-primary" type="submit">Merge into selected account</button>
        <a class="btn btn-outline" href="<?= e(app_url('patient-accounts/duplicates.php')) ?>">Cancel</a>
        <p id="merge-progress" hidden role="status">Creating and verifying the backup, then merging. Please keep this page open.</p>
    </form>
</section>
<?php endif; ?>
<section class="clinic-card p-5">
    <div class="flex flex-wrap justify-between gap-3 mb-4"><h2><?= count($pairs) ?> possible duplicate pair(s)</h2><button type="button" class="btn btn-primary" id="export-duplicates">Export accounts and duplicate check</button></div>
    <p>Both records are shown for every match. Select which account to keep to review the merge.</p>
    <?php if (!$pairs): ?><p>No matching IDs, emails, or names were found.</p><?php endif; ?>
    <?php foreach ($pairs as $pair): $left = $byId[$pair['left']]; $right = $byId[$pair['right']]; ?>
    <article class="clinic-card p-4 mb-4">
        <p><strong>Matched:</strong> <?= e(implode(', ', $pair['reasons'])) ?></p>
        <?php foreach ([[$left, $right], [$right, $left]] as [$record, $duplicate]): ?>
        <div class="flex flex-wrap items-center justify-between gap-3 my-3">
            <span><?= e($record['full_name']) ?> · <?= e($record['id_number']) ?> · <?= e((string) $record['email']) ?> · <?= e($record['patient_type']) ?> · <?= e($record['account_status']) ?> (account <?= (int) $record['account_id'] ?>)</span>
            <a class="btn btn-outline" href="?keep_id=<?= (int) $record['account_id'] ?>&amp;remove_id=<?= (int) $duplicate['account_id'] ?>">Keep this account / Review</a>
        </div>
        <?php endforeach; ?>
    </article>
    <?php endforeach; ?>
</section>
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
