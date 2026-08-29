<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_login();
ensure_alert_workflow_schema();

// ── Date range filter ───────────────────────────────────────
$periodOptions = [
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'semestral' => 'Semestral',
    'yearly' => 'Yearly',
];
$period = strtolower((string) ($_GET['period'] ?? 'monthly'));
if (!isset($periodOptions[$period])) {
    $period = 'monthly';
}
$semester = (int) ($_GET['semester'] ?? 0);
if (!in_array($semester, [1, 2], true)) {
    $semester = (int) date('n') >= 6 && (int) date('n') <= 11 ? 1 : 2;
}
if (isset($_GET['from'], $_GET['to'])) {
    $dateFrom = normalize_system_report_date($_GET['from'] ?? null, date('Y-m-01'));
    $dateTo = normalize_system_report_date($_GET['to'] ?? null, date('Y-m-d'));
} else {
    $anchor = new DateTimeImmutable('today');
    if ($period === 'weekly') {
        $dateFrom = $anchor->modify('-7 days')->format('Y-m-d');
        $dateTo = $anchor->format('Y-m-d');
    } elseif ($period === 'yearly') {
        $dateFrom = $anchor->modify('-1 year')->format('Y-m-d');
        $dateTo = $anchor->format('Y-m-d');
    } elseif ($period === 'semestral') {
        $schoolYearStart = (int) $anchor->format('n') >= 6
            ? (int) $anchor->format('Y')
            : (int) $anchor->format('Y') - 1;
        if ($semester === 1) {
            $dateFrom = sprintf('%04d-06-01', $schoolYearStart);
            $dateTo = sprintf('%04d-11-30', $schoolYearStart);
        } else {
            $dateFrom = sprintf('%04d-12-01', $schoolYearStart);
            $dateTo = sprintf('%04d-05-31', $schoolYearStart + 1);
        }
    } else {
        $dateFrom = $anchor->modify('-1 month')->format('Y-m-d');
        $dateTo = $anchor->format('Y-m-d');
    }
}
$mainSystemReport = build_system_report($dateFrom, $dateTo, []);

render_header('Reports');
?>

<!-- ═══ Title ═══ -->
<?php render_clinic_command_header(
    'Reports',
    'Reports & Analytics',
    'Build, preview, and export consolidated analytics across every CLINiQ module.',
    '<a class="btn btn-primary text-decoration-none" id="reportHeaderPreviewLink" href="preview.php?from=' . e($dateFrom) . '&to=' . e($dateTo) . '&period=' . e($period) . '&semester=' . e((string) $semester) . '"><span class="material-symbols-outlined text-[20px]">preview</span>Preview Report</a>'
); ?>

<!-- ═══ Date Range Filter ═══ -->
<form method="get" class="clinic-card overflow-hidden" data-no-ajax="true" id="reportDateForm">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">System Analytics Period</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">All available module graphs update automatically when the date range changes.</p>
    </div>
    <div class="p-6 grid grid-cols-1 xl:grid-cols-[1fr_auto] gap-5 items-end">
        <div class="space-y-3">
            <span class="clinic-label">Quick period</span>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3" role="radiogroup" aria-label="Report period">
                <?php foreach ($periodOptions as $periodValue => $periodLabel): ?>
                    <label class="report-period-option <?= $period === $periodValue ? 'is-active' : '' ?>">
                        <input type="radio" name="period" value="<?= e($periodValue) ?>" <?= $period === $periodValue ? 'checked' : '' ?>>
                        <span><?= e($periodLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <label class="care-timeline-filter min-w-[13rem]" data-semester-wrap <?= $period !== 'semestral' ? 'hidden' : '' ?>>
            <span class="clinic-label">Semester</span>
            <select class="clinic-select" name="semester" id="reportSemester" <?= $period !== 'semestral' ? 'disabled' : '' ?>>
                <option value="1" <?= $semester === 1 ? 'selected' : '' ?>>1st Sem</option>
                <option value="2" <?= $semester === 2 ? 'selected' : '' ?>>2nd Sem</option>
            </select>
        </label>
    </div>
    <div class="px-6 pb-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label class="clinic-label" for="reportFrom">From</label><input class="clinic-input" id="reportFrom" type="date" name="from" value="<?= e($dateFrom) ?>" required></div>
        <div><label class="clinic-label" for="reportTo">To</label><input class="clinic-input" id="reportTo" type="date" name="to" value="<?= e($dateTo) ?>" required></div>
    </div>
</form>

<section class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 pt-2">
    <div>
        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-primary mb-1">Complete Analytics</p>
        <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">All System Graphs</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Live summaries from every reporting module for <?= e(date('M j, Y', strtotime($dateFrom))) ?> - <?= e(date('M j, Y', strtotime($dateTo))) ?>.</p>
    </div>
</section>
<style>
    .report-period-option {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 3rem;
        border: 1px solid #dbe7df;
        border-radius: 1rem;
        background: #fff;
        color: #475569;
        font-size: 0.82rem;
        font-weight: 900;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        cursor: pointer;
        transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
    }

    .report-period-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .report-period-option.is-active,
    .report-period-option:has(input:checked) {
        border-color: rgba(var(--cliniq-focus-rgb), 0.45);
        background: var(--cliniq-primary-fixed);
        color: var(--cliniq-primary-hover);
        box-shadow: 0 10px 22px rgba(var(--cliniq-shadow-rgb), 0.1);
    }
</style>
<style><?= system_report_styles() ?></style>
<?= render_system_report_document($mainSystemReport, false, ['include_cover' => false]) ?>

<script>
(() => {
    const form = document.getElementById('reportDateForm');
    const fromInput = document.getElementById('reportFrom');
    const toInput = document.getElementById('reportTo');
    const semesterInput = document.getElementById('reportSemester');
    const periodInputs = Array.from(document.querySelectorAll('input[name="period"]'));
    const previewLink = document.getElementById('reportHeaderPreviewLink');
    if (!form || !fromInput || !toInput) return;

    let submitTimer = null;
    const currentUrl = new URL(window.location.href);

    const formatDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const selectedPeriod = () => {
        const selected = periodInputs.find((input) => input.checked);
        return selected ? selected.value : 'monthly';
    };

    const schoolYearStart = (anchor) => {
        return anchor.getMonth() + 1 >= 6 ? anchor.getFullYear() : anchor.getFullYear() - 1;
    };

    const computeRange = (period) => {
        const anchor = new Date();
        anchor.setHours(0, 0, 0, 0);
        if (Number.isNaN(anchor.getTime())) return null;

        if (period === 'weekly') {
            const from = new Date(anchor);
            from.setDate(anchor.getDate() - 7);
            return [formatDate(from), formatDate(anchor)];
        }

        if (period === 'yearly') {
            const from = new Date(anchor);
            from.setFullYear(anchor.getFullYear() - 1);
            return [formatDate(from), formatDate(anchor)];
        }

        if (period === 'semestral') {
            const startYear = schoolYearStart(anchor);
            const semester = semesterInput && semesterInput.value === '2' ? 2 : 1;
            return semester === 1
                ? [`${startYear}-06-01`, `${startYear}-11-30`]
                : [`${startYear}-12-01`, `${startYear + 1}-05-31`];
        }

        const from = new Date(anchor);
        from.setMonth(anchor.getMonth() - 1);
        return [formatDate(from), formatDate(anchor)];
    };

    const syncPeriodUi = () => {
        const period = selectedPeriod();
        periodInputs.forEach((input) => {
            input.closest('.report-period-option')?.classList.toggle('is-active', input.checked);
        });
        if (semesterInput) {
            const semesterWrap = semesterInput.closest('[data-semester-wrap]');
            const isSemestral = period === 'semestral';
            semesterInput.disabled = !isSemestral;
            if (semesterWrap) {
                semesterWrap.hidden = !isSemestral;
            }
        }
    };

    const syncPreviewLink = () => {
        if (!previewLink) return;
        const previewUrl = new URL(previewLink.href, window.location.href);
        previewUrl.searchParams.set('from', fromInput.value);
        previewUrl.searchParams.set('to', toInput.value);
        previewUrl.searchParams.set('period', selectedPeriod());
        if (semesterInput) {
            previewUrl.searchParams.set('semester', semesterInput.value);
        }
        previewLink.href = previewUrl.pathname.split('/').pop() + '?' + previewUrl.searchParams.toString();
    };

    const submitWhenComplete = () => {
        syncPreviewLink();
        if (!fromInput.value || !toInput.value) return;
        clearTimeout(submitTimer);
        submitTimer = setTimeout(() => {
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.set('from', fromInput.value);
            nextUrl.searchParams.set('to', toInput.value);
            nextUrl.searchParams.set('period', selectedPeriod());
            if (semesterInput) {
                nextUrl.searchParams.set('semester', semesterInput.value);
            }
            if (nextUrl.search === currentUrl.search) return;
            window.location.assign(nextUrl.href);
        }, 250);
    };

    [fromInput, toInput].forEach((input) => {
        input.addEventListener('change', submitWhenComplete);
        input.addEventListener('input', syncPreviewLink);
    });

    periodInputs.forEach((input) => {
        input.addEventListener('change', () => {
            syncPeriodUi();
            const range = computeRange(input.value);
            if (range) {
                fromInput.value = range[0];
                toInput.value = range[1];
            }
            submitWhenComplete();
        });
    });

    if (semesterInput) {
        semesterInput.addEventListener('change', () => {
            const range = computeRange('semestral');
            if (range) {
                fromInput.value = range[0];
                toInput.value = range[1];
            }
            submitWhenComplete();
        });
    }

    syncPeriodUi();
    syncPreviewLink();
})();
</script>

<?php render_footer(); ?>
