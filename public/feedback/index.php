<?php
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ClinicFeedback.php';
require_login();
if (!in_array(current_user()['role'] ?? '', ['admin', 'doctor'], true)) {
    http_response_code(403);
    exit('Only clinic administrators and doctors can view feedback.');
}
header('Cache-Control: no-store, private');
$error = '';
$rows = $summary = $groups = [];
$total = 0;
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$from = is_string($_GET['from'] ?? null) ? $_GET['from'] : '';
$to = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
$service = is_string($_GET['service'] ?? null) ? $_GET['service'] : '';
$periodOptions = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'semestral' => 'Semestral', 'yearly' => 'Yearly'];
$period = strtolower((string) ($_GET['period'] ?? 'monthly'));
$semester = (int) ($_GET['semester'] ?? 0);
if (!isset($periodOptions[$period])) $period = 'monthly';
if (!in_array($semester, [1, 2], true)) $semester = (int) date('n') >= 6 && (int) date('n') <= 11 ? 1 : 2;
$hasManualRange = array_key_exists('from', $_GET) || array_key_exists('to', $_GET);
if (!$hasManualRange) {
    $anchor = new DateTimeImmutable('today');
    if ($period === 'weekly') {
        $from = $anchor->modify('-7 days')->format('Y-m-d');
        $to = $anchor->format('Y-m-d');
    } elseif ($period === 'yearly') {
        $from = $anchor->modify('-1 year')->format('Y-m-d');
        $to = $anchor->format('Y-m-d');
    } elseif ($period === 'semestral') {
        $schoolYearStart = (int) $anchor->format('n') >= 6 ? (int) $anchor->format('Y') : (int) $anchor->format('Y') - 1;
        if ($semester === 1) {
            $from = sprintf('%04d-06-01', $schoolYearStart);
            $to = sprintf('%04d-11-30', $schoolYearStart);
        } else {
            $from = sprintf('%04d-12-01', $schoolYearStart);
            $to = sprintf('%04d-05-31', $schoolYearStart + 1);
        }
    } else {
        $from = $anchor->modify('-1 month')->format('Y-m-d');
        $to = $anchor->format('Y-m-d');
    }
}
$columns = ['tangibles', 'reliability', 'responsiveness', 'assurance', 'empathy', 'overall'];
try {
    foreach ([$from, $to] as $date) {
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Select valid dates.');
        }
    }
    if ($from !== '' && $to !== '' && $from > $to) throw new InvalidArgumentException('The start date must be on or before the end date.');
    if ($service !== '' && !in_array($service, clinic_feedback_services(), true)) throw new InvalidArgumentException('Select a valid service.');
    $db = auth_db();
    if (!clinic_feedback_ready($db)) throw new InvalidArgumentException('Feedback is not set up yet. Apply the clinic feedback database migration.');
    $conditions = ['1=1'];
    $params = [];
    if ($from !== '') { $conditions[] = 'f.submitted_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to !== '') { $conditions[] = 'f.submitted_at < ?'; $params[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'); }
    if ($service !== '') { $conditions[] = 'f.service_type = ?'; $params[] = $service; }
    $where = implode(' AND ', $conditions);
    $averages = implode(', ', array_map(fn($column) => "AVG(f.{$column}) AS {$column}", $columns));
    $query = $db->prepare("SELECT COUNT(*) AS responses, {$averages} FROM clinic_feedback f WHERE {$where}");
    $query->execute($params);
    $summary = $query->fetch();
    $total = (int) $summary['responses'];
    $page = min($page, max(1, (int) ceil($total / 25)));
    $offset = ($page - 1) * 25;
    $query = $db->prepare("SELECT f.service_type, COUNT(*) AS responses, {$averages} FROM clinic_feedback f WHERE {$where} GROUP BY f.service_type ORDER BY f.service_type");
    $query->execute($params);
    $groups = $query->fetchAll();
    $query = $db->prepare("SELECT f.*, v.visit_datetime FROM clinic_feedback f JOIN visits v ON v.visit_id = f.visit_id WHERE {$where} ORDER BY f.submitted_at DESC, f.feedback_id DESC LIMIT 25 OFFSET {$offset}");
    $query->execute($params);
    $rows = $query->fetchAll();
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Feedback report: ' . $exception->getMessage());
    $error = 'Feedback reports are temporarily unavailable. Please try again later.';
}
render_header('Clinic Feedback');
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/feedback.css?v=design-2')) ?>">
<div class="feedback-page feedback-admin">
    <?php render_clinic_command_header('Service evaluation', 'Clinic Feedback', 'Student feedback for Active and Completed clinic visits.'); ?>
    <form method="get" class="clinic-card feedback-section" id="feedbackFilterForm">
        <div class="feedback-filter-heading"><span class="clinic-label">Quick period</span><div class="feedback-period-grid" role="radiogroup" aria-label="Feedback period">
            <?php foreach ($periodOptions as $periodValue => $periodLabel): ?><label class="feedback-period-option <?= $period === $periodValue && !$hasManualRange ? 'is-active' : '' ?>"><input type="radio" name="period" value="<?= e($periodValue) ?>" <?= $period === $periodValue && !$hasManualRange ? 'checked' : '' ?>><span><?= e($periodLabel) ?></span></label><?php endforeach; ?>
        </div></div>
        <div class="feedback-filter-grid">
            <label class="feedback-field"><span class="clinic-label">Submitted from</span><input class="clinic-input" type="date" name="from" value="<?= e($from) ?>"></label>
            <label class="feedback-field"><span class="clinic-label">Submitted through</span><input class="clinic-input" type="date" name="to" value="<?= e($to) ?>"></label>
            <label class="feedback-field"><span class="clinic-label">Service</span><select class="clinic-select" name="service"><option value="">All services</option><?php foreach (clinic_feedback_services() as $option): ?><option <?= $service === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
            <label class="feedback-field" data-feedback-semester-wrap <?= $period !== 'semestral' || $hasManualRange ? 'hidden' : '' ?>><span class="clinic-label">Semester</span><select class="clinic-select" name="semester" <?= $period !== 'semestral' || $hasManualRange ? 'disabled' : '' ?>><option value="1" <?= $semester === 1 ? 'selected' : '' ?>>1st Sem</option><option value="2" <?= $semester === 2 ? 'selected' : '' ?>>2nd Sem</option></select></label>
        </div>
    </form>
    <?php if ($error): ?><div class="feedback-notice" role="alert"><?= e($error) ?></div><?php else: ?>
        <section class="clinic-card feedback-section"><h2>Evaluation summary</h2>
            <div class="feedback-summary-grid"><div class="feedback-stat">Responses<strong><?= $total ?></strong></div>
                <?php foreach ($columns as $column): ?><div class="feedback-stat"><?= e($column === 'overall' ? 'Overall SERVPERF' : ucfirst($column)) ?><strong><?= $total ? number_format((float) $summary[$column], 2) : '—' ?></strong></div><?php endforeach; ?>
            </div>
            <?php if ($total): ?><p><strong><?= e(clinic_feedback_tier((float) $summary['overall'])) ?></strong> · <?= $total ?> response<?= $total === 1 ? '' : 's' ?></p><?php endif; ?>
            <p class="feedback-muted">Each section averages its questions. Overall SERVPERF averages the five sections equally. Summary scores average individual responses, including all responses matching these filters.</p>
            <p class="feedback-muted">Excellent: 6–7 · Satisfactory: 4–below 6 · Critical: 1–below 4. Scores display two decimals; tiers use unrounded scores.</p>
        </section>
        <section class="clinic-card feedback-section"><h2>Scores by service</h2>
            <?php if (!$groups): ?><div class="empty-state"><span class="material-symbols-outlined" aria-hidden="true">rate_review</span><p class="empty-state-title">No feedback to summarize</p><p class="empty-state-text">Try clearing the filters or return after students submit feedback.</p></div><?php else: ?>
            <div class="feedback-table-wrap" tabindex="0" role="region" aria-label="Service score comparison"><table class="feedback-table"><caption class="feedback-sr-only">Average feedback scores by service, rated from 1 to 7.</caption><thead><tr><th scope="col">Service</th><th scope="col">Responses</th><?php foreach ($columns as $column): ?><th scope="col"><?= e(ucfirst($column)) ?></th><?php endforeach; ?><th scope="col">Performance</th></tr></thead><tbody>
            <?php foreach ($groups as $group): ?><tr><td><?= e($group['service_type']) ?></td><td><?= (int) $group['responses'] ?></td><?php foreach ($columns as $column): ?><td><?= number_format((float) $group[$column], 2) ?></td><?php endforeach; ?><td><?= e(clinic_feedback_tier((float) $group['overall'])) ?></td></tr><?php endforeach; ?>
            <tr><th scope="row">Grand total</th><td><?= $total ?></td><?php foreach ($columns as $column): ?><td><?= number_format((float) $summary[$column], 2) ?></td><?php endforeach; ?><td><?= e(clinic_feedback_tier((float) $summary['overall'])) ?></td></tr>
            </tbody></table></div><?php endif; ?>
        </section>
        <section class="clinic-card feedback-section"><h2>Student responses</h2><p class="feedback-muted">Confidential · For authorized clinic evaluation only. Names and student IDs are omitted from this view.</p>
            <?php if (!$rows): ?><div class="empty-state"><p class="empty-state-title">No student responses</p><p class="empty-state-text">Responses matching your filters will appear here.</p></div><?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <details class="feedback-response">
                    <summary><strong><?= e($row['service_type'] === 'Other' ? 'Other: ' . $row['service_other'] : $row['service_type']) ?></strong><br><span class="feedback-muted">Submitted <?= e($row['submitted_at']) ?> · Overall <?= number_format((float) $row['overall'], 2) ?> · <?= e(clinic_feedback_tier((float) $row['overall'])) ?></span></summary>
                    <p>Visit #<?= (int) $row['visit_id'] ?> · <?= e($row['visit_datetime']) ?><br><?= e($row['academic_term'] === 'Other' ? $row['term_other'] : $row['academic_term']) ?> · <?= e($row['year_level'] === 'Other' ? $row['year_other'] : $row['year_level']) ?> · <?= e($row['program']) ?></p>
                    <h3>Written feedback</h3><p class="feedback-comment"><?= e($row['comments'] ?: 'No written feedback provided.') ?></p>
                    <?php $ratings = json_decode($row['ratings_json'], true) ?: []; foreach (clinic_feedback_sections() as $section => $questions): ?>
                        <h3><?= e($section) ?> · <?= number_format((float) $row[strtolower($section)], 2) ?></h3>
                        <?php foreach ($questions as $code => $question): ?><p><?= e($code . '. ' . $question) ?> <strong><?= (int) ($ratings[$code] ?? 0) ?>/7</strong></p><?php endforeach; ?>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <?php if ($total > 25): ?><nav class="pagination" aria-label="Response pages">
                <?php foreach ([$page - 1 => 'Previous', $page + 1 => 'Next'] as $target => $label): if ($target < 1 || $target > ceil($total / 25)) continue; ?>
                    <a href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'service' => $service, 'page' => $target])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?><span>Page <?= $page ?> of <?= (int) ceil($total / 25) ?></span></nav><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<script>
(() => {
    const form = document.getElementById('feedbackFilterForm');
    if (!form) return;
    const periods = Array.from(form.querySelectorAll('input[name="period"]'));
    const semesterWrap = form.querySelector('[data-feedback-semester-wrap]');
    const semester = semesterWrap?.querySelector('select[name="semester"]');
    periods.forEach((input) => input.addEventListener('change', () => {
        const isSemestral = input.value === 'semestral';
        if (semesterWrap) semesterWrap.hidden = !isSemestral;
        if (semester) semester.disabled = !isSemestral;
        form.querySelector('input[name="from"]').value = '';
        form.querySelector('input[name="to"]').value = '';
        form.submit();
    }));
})();
</script>
<?php render_footer(); ?>
