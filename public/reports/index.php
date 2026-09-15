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
<link hidden rel="stylesheet" href="<?= e(app_url('assets/css/reports.css?v=3')) ?>">
<!-- ═══ Title ═══ -->
<?php render_clinic_command_header(
    'Reports',
    'Reports & Analytics',
    'Build, preview, and export consolidated clinic operational analytics.',
    '<a class="btn btn-primary text-decoration-none" id="reportHeaderPreviewLink" href="preview.php?from=' . e($dateFrom) . '&to=' . e($dateTo) . '&period=' . e($period) . '&semester=' . e((string) $semester) . '"><span class="material-symbols-outlined text-[20px]">preview</span>Preview Report</a>'
); ?>

<div class="reports-page">

<!-- ═══ Date Range Filter ═══ -->
<form method="get" class="clinic-card overflow-hidden" data-no-ajax="true" id="reportDateForm">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">System Analytics Period</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">All available operational graphs update automatically when the date range changes.</p>
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

<p class="report-analytics-range">
    <span class="material-symbols-outlined" aria-hidden="true">date_range</span>
    <span>Showing activity from <?= e(date('M j, Y', strtotime($dateFrom))) ?> to <?= e(date('M j, Y', strtotime($dateTo))) ?></span>
</p>
<div class="report-analytics-layout">
    <div class="report-analytics-content">
        <style><?= system_report_styles() ?></style>
        <?= render_system_report_document($mainSystemReport, false, ['include_cover' => false]) ?>
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
<script>
(() => {
    const chartPalette = ['#2f8553', '#58a978', '#89c79f', '#d4a72c', '#5377b8', '#8b69c7', '#d26b6b', '#64748b'];
    const chartText = '#475569';
    const chartValue = '#205f3d';

    const enhanceReportCharts = () => {
        if (!window.echarts) return;

        document.querySelectorAll('[data-report-chart]').forEach((card) => {
            let chartData;
            try {
                chartData = JSON.parse(card.dataset.reportChart || '{}');
            } catch (_) {
                return;
            }
            const rows = Array.isArray(chartData.rows) ? chartData.rows : [];
            if (!rows.length) return;

            const visual = card.querySelector('.report-chart-visual');
            if (!visual) return;
            const canvas = document.createElement('div');
            canvas.className = 'report-echarts-canvas';
            visual.append(canvas);

            const labels = rows.map((row) => String(row.label ?? ''));
            const values = rows.map((row) => Number(row.value) || 0);
            const chart = echarts.init(canvas, null, { renderer: 'svg' });
            const shared = {
                animationDuration: 420,
                color: chartPalette,
                textStyle: { fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif' },
                tooltip: { trigger: chartData.type === 'donut' ? 'item' : 'axis', confine: true },
            };
            let option;

            if (chartData.type === 'donut') {
                option = {
                    ...shared,
                    tooltip: { trigger: 'item', formatter: '{b}: <b>{c}</b> ({d}%)' },
                    series: [{
                        type: 'pie', radius: ['48%', '72%'], center: ['50%', '50%'], avoidLabelOverlap: true,
                        itemStyle: { borderColor: '#fff', borderWidth: 3, borderRadius: 5 },
                        label: { color: chartText, fontSize: 12, fontWeight: 700, formatter: '{b}' },
                        labelLine: { length: 8, length2: 8 },
                        data: rows.map((row) => ({ name: String(row.label ?? ''), value: Number(row.value) || 0 })),
                    }],
                    graphic: [{ type: 'text', left: 'center', top: '42%', style: { text: String(values.reduce((total, value) => total + value, 0)), fill: chartValue, font: '800 18px Inter, sans-serif', textAlign: 'center' } }, { type: 'text', left: 'center', top: '55%', style: { text: 'TOTAL', fill: '#64748b', font: '700 9px Inter, sans-serif', textAlign: 'center' } }],
                };
            } else if (chartData.type === 'line') {
                option = {
                    ...shared,
                    grid: { left: 36, right: 18, top: 22, bottom: 38 },
                    xAxis: { type: 'category', data: labels, axisLabel: { color: chartText, fontSize: 11, fontWeight: 600, interval: 'auto' }, axisLine: { lineStyle: { color: '#dfe9e2' } } },
                    yAxis: { type: 'value', axisLabel: { color: chartText, fontSize: 11, fontWeight: 600 }, splitLine: { lineStyle: { color: '#edf3ef' } } },
                    series: [{ type: 'line', data: values, smooth: true, symbolSize: 8, lineStyle: { width: 3 }, areaStyle: { color: 'rgba(47,133,83,.12)' }, label: { show: true, position: 'top', color: chartValue, fontWeight: 800, fontSize: 11 } }],
                };
            } else if (chartData.type === 'progress') {
                option = {
                    ...shared,
                    tooltip: { trigger: 'item', formatter: '{b}: <b>{c}</b>' },
                    grid: { left: 4, right: 4, top: 48, bottom: 10 },
                    xAxis: { type: 'value', max: values.reduce((total, value) => total + value, 0) || 1, show: false },
                    yAxis: { type: 'category', data: [''], show: false },
                    legend: { top: 0, type: 'scroll', textStyle: { color: chartText, fontSize: 11, fontWeight: 600 } },
                    series: rows.map((row, index) => ({ name: String(row.label ?? ''), type: 'bar', stack: 'total', barWidth: 26, data: [values[index]], label: { show: values[index] > 0, formatter: '{c}', color: '#fff', fontWeight: 800, fontSize: 11 } })),
                };
            } else {
                const isColumn = chartData.type === 'column';
                option = {
                    ...shared,
                    grid: isColumn ? { left: 36, right: 16, top: 20, bottom: 52 } : { left: 116, right: 36, top: 16, bottom: 12 },
                    xAxis: isColumn ? { type: 'category', data: labels, axisLabel: { color: chartText, fontSize: 10, fontWeight: 600, rotate: labels.length > 5 ? 24 : 0, interval: 0 }, axisLine: { lineStyle: { color: '#dfe9e2' } } } : { type: 'value', axisLabel: { color: chartText, fontSize: 11, fontWeight: 600 }, splitLine: { lineStyle: { color: '#edf3ef' } } },
                    yAxis: isColumn ? { type: 'value', axisLabel: { color: chartText, fontSize: 11, fontWeight: 600 }, splitLine: { lineStyle: { color: '#edf3ef' } } } : { type: 'category', data: labels, axisLabel: { color: chartText, fontSize: 11, fontWeight: 600, width: 102, overflow: 'truncate' }, axisLine: { show: false }, axisTick: { show: false } },
                    series: [{ type: 'bar', data: values, barMaxWidth: 34, itemStyle: { borderRadius: isColumn ? [6, 6, 0, 0] : [0, 6, 6, 0] }, label: { show: true, position: isColumn ? 'top' : 'right', color: chartValue, fontWeight: 800, fontSize: 11 } }],
                };
            }

            try {
                chart.setOption(option);
                new ResizeObserver(() => chart.resize()).observe(canvas);
            } catch (_) {
                chart.dispose();
                canvas.remove();
            }
        });
    };

    enhanceReportCharts();

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
        const observer = new IntersectionObserver((entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
            if (visible[0]) setActiveSection(visible[0].target.id);
        }, { root: document.querySelector('.app-content'), rootMargin: '-16px 0px -65% 0px', threshold: 0 });
        sectionTargets.forEach((section) => observer.observe(section));
    }
})();
</script>

</div>
<?php render_footer(); ?>
