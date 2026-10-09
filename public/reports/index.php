<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_report_access();

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
$reportView = ($_GET['view'] ?? '') === 'tables' ? 'tables' : 'charts';
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
        $dateFrom = $anchor->modify('-6 days')->format('Y-m-d');
        $dateTo = $anchor->format('Y-m-d');
    } elseif ($period === 'yearly') {
        $dateFrom = $anchor->setDate((int) $anchor->format('Y'), 1, 1)->format('Y-m-d');
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
        $dateFrom = $anchor->modify('first day of this month')->format('Y-m-d');
        $dateTo = $anchor->format('Y-m-d');
    }
}
$mainSystemReport = build_system_report($dateFrom, $dateTo, []);

render_header('Clinic Transaction Summary');
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/reports.css?v=' . filemtime(__DIR__ . '/../assets/css/reports.css'))) ?>">
<!-- ═══ Title ═══ -->
<?php render_clinic_command_header(
    'Reports',
    'Clinic Transaction Summary',
    'Review and export the clinic activity totals and follow-up queues for a selected period.',
    '<a class="btn btn-outline text-decoration-none" id="reportHeaderTablesLink" href="index.php?view=' . ($reportView === 'tables' ? 'charts' : 'tables') . '&from=' . e($dateFrom) . '&to=' . e($dateTo) . '&period=' . e($period) . '&semester=' . e((string) $semester) . '"><span class="material-symbols-outlined text-[20px]">' . ($reportView === 'tables' ? 'bar_chart' : 'table_chart') . '</span>' . ($reportView === 'tables' ? 'View charts' : 'Data tables') . '</a><a class="btn btn-primary text-decoration-none" id="reportHeaderPreviewLink" href="preview.php?from=' . e($dateFrom) . '&to=' . e($dateTo) . '&period=' . e($period) . '&semester=' . e((string) $semester) . '"><span class="material-symbols-outlined text-[20px]">preview</span>Preview Report</a>'
); ?>

<div class="reports-page">

<div class="report-live-actions">
    <?= render_system_report_action_summary($mainSystemReport['action_summary'] ?? []) ?>
</div>

<!-- ═══ Date Range Filter ═══ -->
<form method="get" class="clinic-card overflow-hidden" data-no-ajax="true" id="reportDateForm">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Transaction reporting period</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Transaction totals and follow-up queues update when the date range changes.</p>
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
    <div class="px-6 pb-6 flex justify-end"><button class="btn btn-primary" type="submit">Apply period</button></div>
</form>

<p class="report-analytics-range">
    <span class="material-symbols-outlined" aria-hidden="true">date_range</span>
    <span>Showing activity from <?= e(date('M j, Y', strtotime($dateFrom))) ?> to <?= e(date('M j, Y', strtotime($dateTo))) ?></span>
</p>
<div class="report-analytics-layout">
    <div class="report-analytics-content">
        <style><?= system_report_styles() ?></style>
        <?= render_system_report_document($mainSystemReport, false, ['include_cover' => false, 'include_tables' => false, 'table_only' => $reportView === 'tables']) ?>
    </div>
    <aside class="report-section-navigation" aria-label="Report section navigation">
        <p class="report-section-navigation-label">Jump to section</p>
        <nav class="report-section-navigation-list">
            <?php $sectionIndex = 0; foreach ($mainSystemReport['sections'] as $sectionKey => $section): ?>
                <a class="report-section-navigation-link<?= $sectionIndex === 0 ? ' is-active' : '' ?>" href="#report-section-<?= e((string) $sectionKey) ?>">
                    <span class="report-section-navigation-number"><?= $sectionIndex + 1 ?></span>
                    <span class="report-section-navigation-name"><?= e((string) $section['title']) ?></span>
                </a>
            <?php $sectionIndex++; endforeach; ?>
        </nav>
    </aside>
</div>

<script src="<?= e(app_url('assets/vendor/echarts/echarts.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/system-report-charts.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-charts.js'))) ?>"></script>
<script>
(() => {
    const isDarkReport = document.documentElement.classList.contains('cliniq-dark');
    const reportCharts = [];
    let reportRequest = null;
    let reportSectionObserver = null;

    const disposeReportCharts = () => {
        reportCharts.splice(0).forEach(({ chart, observer }) => {
            observer.disconnect();
            chart.dispose();
        });
    };

    const enhanceReportCharts = () => {
        if (!window.echarts) return;

        document.querySelectorAll('[data-report-chart]').forEach((card) => {
            let chartData;
            try {
                chartData = JSON.parse(card.dataset.reportChart || '{}');
            } catch (_) {
                return;
            }
            const tableRows = Array.isArray(chartData.rows) ? chartData.rows : [];
            if (!tableRows.length || chartData.presentation !== 'diagram') return;
            const rows = chartData.type === 'line' ? tableRows : tableRows.slice(0, 10);

            const visual = card.querySelector('.report-chart-visual');
            if (!visual) return;
            const mounted = window.cliniqSystemReportCharts?.mount(visual, chartData, { dark: isDarkReport });
            if (!mounted) return;
            const observer = new ResizeObserver(() => mounted.chart.resize());
            observer.observe(mounted.canvas);
            reportCharts.push({ chart: mounted.chart, observer });
        });
    };

    enhanceReportCharts();

    const form = document.getElementById('reportDateForm');
    const fromInput = document.getElementById('reportFrom');
    const toInput = document.getElementById('reportTo');
    const semesterInput = document.getElementById('reportSemester');
    const periodInputs = Array.from(document.querySelectorAll('input[name="period"]'));
    const previewLink = document.getElementById('reportHeaderPreviewLink');
    const tablesLink = document.getElementById('reportHeaderTablesLink');
    let reportView = <?= json_encode($reportView) ?>;
    if (!form || !fromInput || !toInput) return;

    let submitTimer = null;
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
            from.setDate(anchor.getDate() - 6);
            return [formatDate(from), formatDate(anchor)];
        }

        if (period === 'yearly') {
            const from = new Date(anchor.getFullYear(), 0, 1);
            return [formatDate(from), formatDate(anchor)];
        }

        if (period === 'semestral') {
            const startYear = schoolYearStart(anchor);
            const semester = semesterInput && semesterInput.value === '2' ? 2 : 1;
            return semester === 1
                ? [`${startYear}-06-01`, `${startYear}-11-30`]
                : [`${startYear}-12-01`, `${startYear + 1}-05-31`];
        }

        const from = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
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

    const syncReportLinks = () => {
        [previewLink, tablesLink].filter(Boolean).forEach((link) => {
            const destinationUrl = new URL(link.href, window.location.href);
            destinationUrl.searchParams.set('from', fromInput.value);
            destinationUrl.searchParams.set('to', toInput.value);
            destinationUrl.searchParams.set('period', selectedPeriod());
            if (semesterInput) destinationUrl.searchParams.set('semester', semesterInput.value);
            if (link === tablesLink) {
                destinationUrl.searchParams.set('view', reportView === 'tables' ? 'charts' : 'tables');
                link.innerHTML = '<span class="material-symbols-outlined text-[20px]">' + (reportView === 'tables' ? 'bar_chart' : 'table_chart') + '</span>' + (reportView === 'tables' ? 'View charts' : 'Data tables');
            } else {
                destinationUrl.searchParams.delete('view');
            }
            link.href = destinationUrl.pathname.split('/').pop() + '?' + destinationUrl.searchParams.toString();
        });
    };

    const reportUrl = () => {
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('from', fromInput.value);
        nextUrl.searchParams.set('to', toInput.value);
        nextUrl.searchParams.set('period', selectedPeriod());
        if (semesterInput) {
            nextUrl.searchParams.set('semester', semesterInput.value);
        }
        if (reportView === 'tables') nextUrl.searchParams.set('view', 'tables');
        else nextUrl.searchParams.delete('view');
        return nextUrl;
    };

    const bindSectionNavigation = () => {
        reportSectionObserver?.disconnect();
        const sectionLinks = Array.from(document.querySelectorAll('.report-section-navigation-link'));
        const sectionTargets = sectionLinks
            .map((link) => document.querySelector(link.getAttribute('href')))
            .filter(Boolean);
        const setActiveSection = (sectionId) => {
            sectionLinks.forEach((link) => {
                const isActive = link.getAttribute('href') === `#${sectionId}`;
                link.classList.toggle('is-active', isActive);
                if (isActive) link.setAttribute('aria-current', 'location');
                else link.removeAttribute('aria-current');
            });
        };
        sectionLinks.forEach((link) => link.addEventListener('click', (event) => {
            const target = document.querySelector(link.getAttribute('href'));
            if (!target) return;
            event.preventDefault();
            target.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'start',
            });
            setActiveSection(target.id);
        }));
        if ('IntersectionObserver' in window && sectionTargets.length) {
            reportSectionObserver = new IntersectionObserver((entries) => {
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                if (visible[0]) setActiveSection(visible[0].target.id);
            }, { root: document.querySelector('.app-content'), rootMargin: '-16px 0px -65% 0px', threshold: 0 });
            sectionTargets.forEach((section) => reportSectionObserver.observe(section));
        }
    };

    const refreshAnalytics = async () => {
        const nextUrl = reportUrl();
        if (nextUrl.search === window.location.search) return;

        reportRequest?.abort();
        const request = new AbortController();
        reportRequest = request;
        const layout = document.querySelector('.report-analytics-layout');
        if (!layout) {
            window.location.assign(nextUrl.href);
            return;
        }
        layout.setAttribute('aria-busy', 'true');
        layout.classList.add('opacity-60', 'pointer-events-none');
        try {
            const response = await fetch(nextUrl.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
                signal: request.signal,
            });
            if (!response.ok) throw new Error(`Report request failed (${response.status})`);
            const nextDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextRange = nextDocument.querySelector('.report-analytics-range');
            const nextLayout = nextDocument.querySelector('.report-analytics-layout');
            const currentRange = document.querySelector('.report-analytics-range');
            const nextActions = nextDocument.querySelector('.report-live-actions');
            const currentActions = document.querySelector('.report-live-actions');
            if (!nextRange || !nextLayout || !currentRange || !nextActions || !currentActions) throw new Error('Report response did not contain analytics.');

            disposeReportCharts();
            currentActions.replaceWith(nextActions);
            currentRange.replaceWith(nextRange);
            layout.replaceWith(nextLayout);
            window.history.replaceState({ reportFilter: true }, '', nextUrl.href);
            enhanceReportCharts();
            bindSectionNavigation();
        } catch (error) {
            if (error.name !== 'AbortError') window.location.assign(nextUrl.href);
        } finally {
            if (reportRequest !== request) return;
            const currentLayout = document.querySelector('.report-analytics-layout');
            currentLayout?.removeAttribute('aria-busy');
            currentLayout?.classList.remove('opacity-60', 'pointer-events-none');
        }
    };

    const submitWhenComplete = () => {
        syncReportLinks();
        if (!fromInput.value || !toInput.value) return;
        clearTimeout(submitTimer);
        submitTimer = setTimeout(() => {
            refreshAnalytics();
        }, 250);
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        syncReportLinks();
        refreshAnalytics();
    });

    tablesLink?.addEventListener('click', (event) => {
        event.preventDefault();
        reportView = reportView === 'tables' ? 'charts' : 'tables';
        syncReportLinks();
        refreshAnalytics();
    });

    [fromInput, toInput].forEach((input) => {
        input.addEventListener('change', submitWhenComplete);
        input.addEventListener('input', syncReportLinks);
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
    syncReportLinks();
    bindSectionNavigation();
})();
</script>

</div>
<?php render_footer(); ?>
