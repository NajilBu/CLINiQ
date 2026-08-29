<?php

require_once __DIR__ . '/SystemSettings.php';

function system_report_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function system_report_format_number(float|int $value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals);
}

function system_report_chart_type(string $title): string
{
    if (preg_match('/by Day|Trend/i', $title)) {
        return 'line';
    }
    if (preg_match('/Workflow|Requirement Status|Stock Condition/i', $title)) {
        return 'progress';
    }
    if (preg_match('/Status|Sex|Patient Type|Items by Type|Risk Level|Classification|Request Source|Visit Source|Finding Result/i', $title)) {
        return 'donut';
    }
    if (preg_match('/Program|Purpose|Transactions|Referred To|Borrowed Equipment|Incident Type/i', $title)) {
        return 'column';
    }
    return 'bar';
}

function system_report_chart_colors(): array
{
    return ['#2f8553', '#58a978', '#89c79f', '#d4a72c', '#5377b8', '#8b69c7', '#d26b6b', '#64748b', '#38a3a5', '#b7791f'];
}

function render_system_report_bar_chart(array $rows): string
{
    $maxValue = max(array_column($rows, 'value')) ?: 1;
    ob_start();
    foreach ($rows as $row):
        $width = max(2, round(((float) $row['value'] / $maxValue) * 100, 1)); ?>
        <div class="report-chart-row" title="<?= system_report_escape($row['label']) ?>: <?= system_report_format_number($row['value']) ?>">
            <span class="report-chart-label"><?= system_report_escape($row['label']) ?></span>
            <div class="report-chart-track"><div class="report-chart-fill" style="width: <?= $width ?>%"></div></div>
            <span class="report-chart-value"><?= system_report_format_number($row['value']) ?></span>
        </div>
    <?php endforeach;
    return (string) ob_get_clean();
}

function render_system_report_donut_chart(array $rows): string
{
    $total = array_sum(array_column($rows, 'value')) ?: 1;
    $circumference = 251.327;
    $offset = 0.0;
    $colors = system_report_chart_colors();
    ob_start(); ?>
    <div class="report-donut-layout">
        <svg class="report-donut" viewBox="0 0 100 100" role="img" aria-label="Distribution chart">
            <circle cx="50" cy="50" r="40" fill="none" stroke="#edf3ef" stroke-width="14"></circle>
            <?php foreach ($rows as $index => $row):
                $length = ((float) $row['value'] / $total) * $circumference;
                $color = $colors[$index % count($colors)]; ?>
                <circle cx="50" cy="50" r="40" fill="none" stroke="<?= $color ?>" stroke-width="14" stroke-dasharray="<?= round($length, 3) ?> <?= round($circumference - $length, 3) ?>" stroke-dashoffset="<?= round(-$offset, 3) ?>" transform="rotate(-90 50 50)"></circle>
                <?php $offset += $length; ?>
            <?php endforeach; ?>
            <text x="50" y="48" text-anchor="middle" class="report-donut-total"><?= system_report_format_number($total) ?></text>
            <text x="50" y="59" text-anchor="middle" class="report-donut-caption">TOTAL</text>
        </svg>
        <div class="report-chart-legend">
            <?php foreach ($rows as $index => $row): $pct = round(((float) $row['value'] / $total) * 100); ?>
                <div><i style="background:<?= $colors[$index % count($colors)] ?>"></i><span title="<?= system_report_escape($row['label']) ?>"><?= system_report_escape($row['label']) ?></span><strong><?= system_report_format_number($row['value']) ?> (<?= $pct ?>%)</strong></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}

function render_system_report_line_chart(array $rows): string
{
    $maxValue = max(array_column($rows, 'value')) ?: 1;
    $count = count($rows);
    $points = [];
    foreach ($rows as $index => $row) {
        $x = $count === 1 ? 100 : 12 + ($index / ($count - 1)) * 176;
        $y = 82 - ((float) $row['value'] / $maxValue) * 62;
        $points[] = ['x' => round($x, 2), 'y' => round($y, 2), 'row' => $row];
    }
    $polyline = implode(' ', array_map(static fn(array $point): string => $point['x'] . ',' . $point['y'], $points));
    $labelIndexes = array_values(array_unique([0, (int) floor(($count - 1) / 2), $count - 1]));
    ob_start(); ?>
    <svg class="report-line-chart" viewBox="0 0 200 112" role="img" aria-label="Trend line chart">
        <line x1="12" y1="82" x2="188" y2="82" stroke="#dfe9e2" stroke-width="1"></line>
        <line x1="12" y1="50" x2="188" y2="50" stroke="#edf3ef" stroke-width="1"></line>
        <line x1="12" y1="20" x2="188" y2="20" stroke="#edf3ef" stroke-width="1"></line>
        <polyline points="<?= $polyline ?>" fill="none" stroke="#2f8553" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
        <?php foreach ($points as $index => $point): ?>
            <circle cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>" r="3" fill="#fff" stroke="#2f8553" stroke-width="2"><title><?= system_report_escape($point['row']['label']) ?>: <?= system_report_format_number($point['row']['value']) ?></title></circle>
            <?php if (in_array($index, $labelIndexes, true)): ?>
                <text x="<?= $point['x'] ?>" y="101" text-anchor="middle" class="report-axis-label"><?= system_report_escape(mb_substr($point['row']['label'], 0, 12)) ?></text>
                <text x="<?= $point['x'] ?>" y="<?= max(11, $point['y'] - 6) ?>" text-anchor="middle" class="report-point-value"><?= system_report_format_number($point['row']['value']) ?></text>
            <?php endif; ?>
        <?php endforeach; ?>
    </svg>
    <?php return (string) ob_get_clean();
}

function render_system_report_column_chart(array $rows): string
{
    $maxValue = max(array_column($rows, 'value')) ?: 1;
    $visibleRows = array_slice($rows, 0, 10);
    ob_start(); ?>
    <div class="report-column-chart" style="grid-template-columns:repeat(<?= count($visibleRows) ?>, minmax(0, 1fr))">
        <?php foreach ($visibleRows as $row): $height = max(5, round(((float) $row['value'] / $maxValue) * 100, 1)); ?>
            <div class="report-column-item" title="<?= system_report_escape($row['label']) ?>: <?= system_report_format_number($row['value']) ?>">
                <strong><?= system_report_format_number($row['value']) ?></strong>
                <div class="report-column-track"><div style="height:<?= $height ?>%"></div></div>
                <span><?= system_report_escape(mb_substr($row['label'], 0, 13)) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php return (string) ob_get_clean();
}

function render_system_report_progress_chart(array $rows): string
{
    $total = array_sum(array_column($rows, 'value')) ?: 1;
    $colors = system_report_chart_colors();
    ob_start(); ?>
    <div class="report-progress-summary">
        <div class="report-progress-track">
            <?php foreach ($rows as $index => $row): $pct = ((float) $row['value'] / $total) * 100; ?>
                <span style="width:<?= round($pct, 2) ?>%;background:<?= $colors[$index % count($colors)] ?>" title="<?= system_report_escape($row['label']) ?>: <?= round($pct) ?>%"></span>
            <?php endforeach; ?>
        </div>
        <div class="report-progress-legend">
            <?php foreach ($rows as $index => $row): $pct = round(((float) $row['value'] / $total) * 100); ?>
                <div><i style="background:<?= $colors[$index % count($colors)] ?>"></i><span><?= system_report_escape($row['label']) ?></span><strong><?= system_report_format_number($row['value']) ?> / <?= $pct ?>%</strong></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}

function render_system_report_chart(array $chart): string
{
    if (!$chart['rows']) {
        return '<p class="report-empty">' . system_report_escape($chart['empty']) . '</p>';
    }
    return match (system_report_chart_type($chart['title'])) {
        'donut' => render_system_report_donut_chart($chart['rows']),
        'line' => render_system_report_line_chart($chart['rows']),
        'column' => render_system_report_column_chart($chart['rows']),
        'progress' => render_system_report_progress_chart($chart['rows']),
        default => render_system_report_bar_chart($chart['rows']),
    };
}

function system_report_styles(): string
{
    return <<<'CSS'
* { box-sizing: border-box; }
.system-report-standalone { margin: 0; background: #edf4ef; }
.report-document { width: min(1120px, calc(100% - 32px)); margin: 28px auto; background: #fff; color: #17261d; font-family: Arial, Helvetica, sans-serif; box-shadow: 0 18px 50px rgba(23, 38, 29, .09); }
.report-document-dashboard { width: 100%; margin: 0; border: 1px solid #dfe9e2; border-radius: 20px; box-shadow: 0 12px 34px rgba(23,38,29,.05); overflow: hidden; }
.report-cover { padding: 46px 52px 38px; background: linear-gradient(135deg, #174d32, #2f8553); color: #fff; }
.report-cover-card { display: contents; }
.report-cover-details { display: none; }
.report-cover-footer { display: none; }
.report-brand { display: flex; align-items: center; gap: 14px; margin-bottom: 46px; }
.report-logo { display: grid; place-items: center; width: 46px; height: 46px; border-radius: 13px; background: #fff; color: #287548; font-size: 25px; font-weight: 900; }
.report-brand strong { display: block; font-size: 21px; letter-spacing: -.3px; }
.report-brand span { display: block; margin-top: 2px; color: #d9f1e2; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.report-cover h1 { max-width: 680px; margin: 0 0 12px; color: #fff; font-size: 34px; line-height: 1.08; letter-spacing: -1px; }
.report-cover p { max-width: 720px; margin: 0; color: #e0f2e7; font-size: 13px; font-weight: 600; line-height: 1.6; }
.report-meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 30px; }
.report-meta div { padding: 13px 15px; border: 1px solid rgba(255,255,255,.22); border-radius: 12px; background: rgba(255,255,255,.09); }
.report-meta span { display: block; margin-bottom: 5px; color: #c9e8d5; font-size: 9px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
.report-meta strong { font-size: 12px; }
.report-body { padding: 34px 40px 42px; }
.report-section { margin-bottom: 30px; padding-bottom: 30px; border-bottom: 1px solid #dfe9e2; }
.report-section[hidden] { display: none !important; }
.report-section:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: 0; }
.report-section-heading { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
.report-section-number { display: grid; place-items: center; flex: 0 0 34px; height: 34px; border-radius: 10px; background: #e6f4eb; color: #287548; font-size: 13px; font-weight: 900; }
.report-section h2 { margin: 0 0 4px; color: #17261d; font-size: 20px; letter-spacing: -.3px; }
.report-section-description { margin: 0; color: #64748b; font-size: 11px; font-weight: 600; line-height: 1.5; }
.report-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; }
.report-metric { min-height: 88px; padding: 14px; border: 1px solid #dfe9e2; border-radius: 12px; background: #fbfdfb; }
.report-metric-label { min-height: 24px; margin: 0 0 8px; color: #64748b; font-size: 9px; font-weight: 900; letter-spacing: .07em; text-transform: uppercase; }
.report-metric-value { margin: 0; color: #205f3d; font-size: 23px; font-weight: 900; line-height: 1; }
.report-metric-note { margin: 5px 0 0; color: #94a3b8; font-size: 9px; font-weight: 700; }
.report-charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.report-chart { min-height: 180px; padding: 15px; border: 1px solid #dfe9e2; border-radius: 12px; background: #fff; break-inside: avoid; page-break-inside: avoid; }
.report-chart h3 { margin: 0 0 13px; color: #334155; font-size: 12px; }
.report-chart-kind { float: right; color: #94a3b8; font-size: 8px; font-weight: 900; letter-spacing: .06em; text-transform: uppercase; }
.report-chart-row { display: grid; grid-template-columns: minmax(82px, 34%) minmax(80px, 1fr) 35px; align-items: center; gap: 8px; margin: 7px 0; }
.report-chart-label { overflow: hidden; color: #475569; font-size: 9px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
.report-chart-track { height: 8px; overflow: hidden; border-radius: 99px; background: #edf3ef; }
.report-chart-fill { height: 100%; min-width: 2px; border-radius: 99px; background: linear-gradient(90deg, #3b8b5d, #6bb689); }
.report-chart-value { color: #205f3d; font-size: 9px; font-weight: 900; text-align: right; }
.report-donut-layout { display: grid; grid-template-columns: 116px minmax(0,1fr); align-items: center; gap: 13px; }
.report-donut { width: 112px; height: 112px; }
.report-donut-total { fill: #205f3d; font-size: 12px; font-weight: 900; }
.report-donut-caption { fill: #94a3b8; font-size: 5px; font-weight: 900; letter-spacing: .08em; }
.report-chart-legend, .report-progress-legend { display: grid; gap: 7px; }
.report-chart-legend div, .report-progress-legend div { display: grid; grid-template-columns: 8px minmax(0,1fr) auto; align-items: center; gap: 6px; color: #64748b; font-size: 8px; font-weight: 700; }
.report-chart-legend i, .report-progress-legend i { width: 8px; height: 8px; border-radius: 3px; }
.report-chart-legend span, .report-progress-legend span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.report-chart-legend strong, .report-progress-legend strong { color: #334155; font-size: 8px; }
.report-line-chart { display: block; width: 100%; height: 126px; }
.report-axis-label { fill: #64748b; font-size: 6px; font-weight: 700; }
.report-point-value { fill: #205f3d; font-size: 6px; font-weight: 900; }
.report-column-chart { display: grid; align-items: end; gap: 5px; height: 132px; }
.report-column-item { display: grid; grid-template-rows: 14px 88px 22px; align-items: end; min-width: 0; text-align: center; }
.report-column-item strong { color: #205f3d; font-size: 8px; }
.report-column-track { display: flex; align-items: flex-end; justify-content: center; height: 84px; border-bottom: 1px solid #dfe9e2; }
.report-column-track div { width: min(22px, 72%); min-height: 3px; border-radius: 4px 4px 0 0; background: linear-gradient(#6bb689,#2f8553); }
.report-column-item span { overflow: hidden; color: #64748b; font-size: 7px; font-weight: 700; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
.report-progress-summary { padding-top: 18px; }
.report-progress-track { display: flex; width: 100%; height: 22px; overflow: hidden; border-radius: 99px; background: #edf3ef; }
.report-progress-track span { display: block; height: 100%; min-width: 2px; }
.report-progress-legend { margin-top: 18px; grid-template-columns: repeat(2, minmax(0,1fr)); }
.report-remarks { margin-top: 14px; break-inside: avoid; page-break-inside: avoid; }
.report-remarks label, .report-remarks-title { display: block; margin: 0 0 7px; color: #475569; font-size: 9px; font-weight: 900; letter-spacing: .07em; text-transform: uppercase; }
.report-remarks textarea { width: 100%; min-height: 84px; padding: 11px 12px; border: 1px solid #cfded3; border-radius: 10px; background: #fbfdfb; color: #334155; font: 600 11px/1.5 Arial, sans-serif; resize: vertical; outline: none; }
.report-remarks textarea:focus { border-color: #58a978; box-shadow: 0 0 0 3px rgba(47,133,83,.1); }
.report-remarks-print { min-height: 65px; padding: 10px 12px; border: 1px solid #d8e4dc; border-radius: 9px; background: repeating-linear-gradient(#fff 0, #fff 20px, #e8efe9 21px); color: #334155; font-size: 10px; font-weight: 600; line-height: 21px; white-space: pre-wrap; }
.report-empty { display: grid; place-items: center; min-height: 112px; margin: 0; border: 1px dashed #d8e2db; border-radius: 9px; color: #94a3b8; font-size: 10px; font-weight: 700; text-align: center; }
.report-footer { display: flex; justify-content: space-between; padding: 16px 40px; border-top: 1px solid #dfe9e2; color: #94a3b8; font-size: 9px; font-weight: 700; }
@media (max-width: 760px) {
  .report-document { width: 100%; margin: 0; }
  .report-cover, .report-body { padding: 28px 22px; }
  .report-meta, .report-metrics, .report-charts { grid-template-columns: 1fr; }
}
@page { size: A4 portrait; margin: 11mm 10mm 13mm; }
@media print {
  body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .report-document { width: auto !important; max-width: none !important; margin: 0 !important; background: #fff; box-shadow: none !important; }
  .report-cover { min-height: 250mm !important; display: block; margin: 0 !important; padding: 16mm 18mm 0; background: #fff !important; color: #fff; border: 0; border-radius: 0; box-shadow: none !important; break-after: page; page-break-after: always; }
  .report-cover-card { display: block; max-width: 145mm; min-height: 118mm; margin: 0 auto; padding: 17mm 14mm; border-radius: 14px; background: #1f6b43 !important; color: #fff; }
  .report-cover-footer { display: grid; max-width: 145mm; margin: 16mm auto 0; color: #17261d; }
  .report-confidential-note { padding: 4mm 5mm; border: 1px solid #dfe9e2; border-radius: 10px; color: #475569; font-size: 9px; font-weight: 700; line-height: 1.45; text-align: center; }
  .report-cover-footer-brand { margin-top: 5mm; color: #64748b; font-size: 8px; font-weight: 900; letter-spacing: .12em; text-align: center; text-transform: uppercase; }
  .report-brand { margin-bottom: 10mm; }
  .report-logo { width: 34px; height: 34px; border: 1px solid #b9d1c2; background: #fff; color: #205f3d; font-size: 18px; }
  .report-brand strong { color: #fff; font-size: 16px; }
  .report-brand span { color: #d9f1e2; font-size: 8px; }
  .report-cover h1 { max-width: 125mm; color: #fff; font-size: 24px; }
  .report-cover p { max-width: 130mm; color: #e0f2e7; font-size: 10px; line-height: 1.45; }
  .report-meta { grid-template-columns: repeat(3, 1fr); gap: 7px; margin-top: 8mm; }
  .report-meta div { padding: 7px 8px; border-color: rgba(255,255,255,.26); background: rgba(255,255,255,.1); }
  .report-meta span { color: #c9e8d5; font-size: 7px; }
  .report-meta strong { color: #fff; font-size: 9px; }
  .report-cover-details { display: grid; grid-template-columns: repeat(2, 1fr); gap: 7px; max-width: 145mm; margin: 7mm auto 0; color: #17261d; }
  .report-cover-detail { padding: 7px 8px; border: 1px solid #dfe9e2; border-radius: 10px; background: #fff; }
  .report-cover-detail span { display: block; margin-bottom: 3px; color: #64748b; font-size: 7px; font-weight: 900; letter-spacing: .09em; text-transform: uppercase; }
  .report-cover-detail strong { display: block; color: #17261d; font-size: 9px; font-weight: 900; line-height: 1.35; }
  .report-cover-detail-wide { grid-column: 1 / -1; }
  .report-body { padding: 0 !important; }
  .report-section { min-height: 0 !important; box-shadow: none !important; break-before: page; page-break-before: always; break-inside: avoid; page-break-inside: avoid; margin: 0 !important; padding: 4mm 0 0 !important; border-top: 1px solid #e5ece7; border-bottom: 0; background: #fff; }
  .report-section:first-child { break-before: auto; page-break-before: auto; }
  .report-section-heading { margin-bottom: 9px; }
  .report-section h2 { font-size: 16px; }
  .report-section-description { font-size: 9px; }
  .report-section-number { flex-basis: 24px; height: 24px; border: 1px solid #cfe0d5; background: #fff; font-size: 10px; }
  .report-metrics { grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 9px; }
  .report-metric { min-height: 54px; padding: 8px; background: #fff; }
  .report-metric-label { min-height: 0; margin-bottom: 4px; font-size: 7px; }
  .report-metric-value { font-size: 17px; }
  .report-metric-note { font-size: 7px; }
  .report-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .report-chart { min-height: 130px; padding: 9px; }
  .report-chart h3 { margin-bottom: 7px; font-size: 10px; }
  .report-chart-row { margin: 4px 0; }
  .report-donut { width: 92px; height: 92px; }
  .report-donut-layout { grid-template-columns: 96px minmax(0,1fr); }
  .report-line-chart { height: 102px; }
  .report-column-chart { height: 106px; }
  .report-column-item { grid-template-rows: 12px 66px 18px; }
  .report-column-track { height: 64px; }
  .report-progress-summary { padding-top: 9px; }
  .report-progress-track { height: 15px; }
  .report-progress-legend { margin-top: 10px; }
  .report-remarks { margin-top: 8px; }
  .report-remarks-print { min-height: 42px; font-size: 8px; line-height: 15px; background: #fff; }
  .report-footer { display: none; }
}
CSS;
}

function render_system_report_document(array $report, bool $standalone = false, array $options = []): string
{
    $clinicProfile = clinic_profile_settings();
    $systemName = trim((string) ($clinicProfile['system_name'] ?? 'CLINiQ')) ?: 'CLINiQ';
    $institutionName = trim((string) ($clinicProfile['institution_name'] ?? 'Pamantasan ng Lungsod ng Pasig')) ?: 'Pamantasan ng Lungsod ng Pasig';
    $departmentName = trim((string) ($clinicProfile['department'] ?? 'University Health Services')) ?: 'University Health Services';
    $includeCover = (bool) ($options['include_cover'] ?? true);
    $remarksMode = (string) ($options['remarks_mode'] ?? 'none');
    $remarks = is_array($options['remarks'] ?? null) ? $options['remarks'] : [];
    $preparedBy = is_array($options['prepared_by'] ?? null) ? $options['prepared_by'] : [];
    $preparedByName = trim((string) ($preparedBy['name'] ?? ''));
    if ($preparedByName === '') {
        $preparedByName = 'CLINiQ Staff';
    }
    $preparedByRole = trim((string) ($preparedBy['role'] ?? ''));
    $preparedByRole = $preparedByRole !== '' ? ucwords(str_replace('_', ' ', $preparedByRole)) : 'Authorized Staff';
    $coverage = implode(', ', array_map(static fn(array $section): string => (string) ($section['title'] ?? ''), $report['sections']));
    ob_start();
    if ($standalone): ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= system_report_escape($report['title']) ?></title><style><?= system_report_styles() ?></style></head><body class="system-report-standalone">
    <?php endif; ?>
    <article class="report-document<?= $includeCover ? '' : ' report-document-dashboard' ?>" id="systemReportDocument">
        <?php if ($includeCover): ?>
            <header class="report-cover">
                <div class="report-cover-card">
                    <div class="report-brand"><div class="report-logo">+</div><div><strong><?= system_report_escape($systemName) ?></strong><span><?= system_report_escape($departmentName) ?></span></div></div>
                    <h1><?= system_report_escape($report['title']) ?></h1>
                    <p>Consolidated operational analytics from the CLINiQ patient, clinical, appointment, inventory, APE, referral, and incident modules.</p>
                    <div class="report-meta">
                        <div><span>Reporting Period</span><strong><?= system_report_escape(date('M j, Y', strtotime($report['date_from']))) ?> - <?= system_report_escape(date('M j, Y', strtotime($report['date_to']))) ?></strong></div>
                        <div><span>Included Modules</span><strong data-report-module-total><?= count($report['sections']) ?> module(s)</strong></div>
                        <div><span>Generated</span><strong><?= system_report_escape(date('M j, Y g:i A', strtotime($report['generated_at']))) ?></strong></div>
                    </div>
                </div>
                <div class="report-cover-footer">
                    <div class="report-cover-details">
                        <div class="report-cover-detail">
                            <span>Prepared for</span>
                            <strong><?= system_report_escape($institutionName) ?> · <?= system_report_escape($departmentName) ?></strong>
                        </div>
                        <div class="report-cover-detail">
                            <span>Prepared by</span>
                            <strong><?= system_report_escape($preparedByName) ?> · <?= system_report_escape($preparedByRole) ?></strong>
                        </div>
                        <div class="report-cover-detail">
                            <span>Report type</span>
                            <strong>System Analytics Report</strong>
                        </div>
                        <div class="report-cover-detail">
                            <span>School office</span>
                            <strong><?= system_report_escape($departmentName) ?></strong>
                        </div>
                        <div class="report-cover-detail report-cover-detail-wide">
                            <span>Coverage</span>
                            <strong><?= system_report_escape($coverage) ?></strong>
                        </div>
                    </div>
                    <div class="report-confidential-note">
                        This report contains clinic operational data and should be handled only by authorized <?= system_report_escape($departmentName) ?> personnel.
                    </div>
                    <div class="report-cover-footer-brand">CLINiQ · University Health Services</div>
                </div>
            </header>
        <?php endif; ?>
        <main class="report-body">
            <?php $sectionNumber = 0; foreach ($report['sections'] as $sectionKey => $section): $sectionNumber++; ?>
                <section class="report-section" data-report-section="<?= system_report_escape((string) $sectionKey) ?>">
                    <div class="report-section-heading"><div class="report-section-number"><?= $sectionNumber ?></div><div><h2><?= system_report_escape($section['title']) ?></h2><p class="report-section-description"><?= system_report_escape($section['description']) ?></p></div></div>
                    <div class="report-metrics">
                        <?php foreach ($section['metrics'] as $metric): ?>
                            <div class="report-metric"><p class="report-metric-label"><?= system_report_escape($metric['label']) ?></p><p class="report-metric-value"><?= system_report_format_number($metric['value'], (int) ($metric['decimals'] ?? 0)) ?></p><?php if (($metric['note'] ?? '') !== ''): ?><p class="report-metric-note"><?= system_report_escape($metric['note']) ?></p><?php endif; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="report-charts">
                        <?php foreach ($section['charts'] as $chart): $chartType = system_report_chart_type($chart['title']); ?>
                            <div class="report-chart"><h3><?= system_report_escape($chart['title']) ?><span class="report-chart-kind"><?= system_report_escape($chartType) ?></span></h3><?= render_system_report_chart($chart) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($remarksMode === 'input'): ?>
                        <div class="report-remarks"><label for="reportRemark<?= system_report_escape((string) $sectionKey) ?>">Remarks</label><textarea id="reportRemark<?= system_report_escape((string) $sectionKey) ?>" name="remarks[<?= system_report_escape((string) $sectionKey) ?>]" maxlength="1500" placeholder="Add observations, interpretation, or follow-up notes for this section..."><?= system_report_escape((string) ($remarks[$sectionKey] ?? '')) ?></textarea></div>
                    <?php elseif ($remarksMode === 'print'): ?>
                        <div class="report-remarks"><p class="report-remarks-title">Remarks</p><div class="report-remarks-print"><?= system_report_escape((string) ($remarks[$sectionKey] ?? '')) ?></div></div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </main>
        <?php if ($includeCover): ?><footer class="report-footer"><span>CLINiQ - University Health Services</span><span>Confidential operational report</span></footer><?php endif; ?>
    </article>
    <?php if ($standalone): ?></body></html><?php endif;
    return (string) ob_get_clean();
}
