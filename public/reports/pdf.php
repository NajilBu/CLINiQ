<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_login();

$currentUser = current_user() ?? [];
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);
$report = build_system_report($dateFrom, $dateTo, $modules);

$backQuery = http_build_query([
    'from' => $report['date_from'],
    'to' => $report['date_to'],
    'modules' => $modules,
]);

$downloadFields = '<input type="hidden" name="from" value="' . system_report_escape($report['date_from']) . '">'
    . '<input type="hidden" name="to" value="' . system_report_escape($report['date_to']) . '">';
foreach ($modules as $module) {
    $downloadFields .= '<input type="hidden" name="modules[]" value="' . system_report_escape((string) $module) . '">';
}
foreach ($remarks as $module => $remark) {
    $downloadFields .= '<input type="hidden" name="remarks[' . system_report_escape((string) $module) . ']" value="' . system_report_escape((string) $remark) . '">';
}

$toolbarStyles = <<<'CSS'
.print-toolbar {
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 18px;
    background: rgba(255,255,255,.94);
    border-bottom: 1px solid #dfe9e2;
    box-shadow: 0 10px 24px rgba(23, 38, 29, .08);
    font-family: Arial, Helvetica, sans-serif;
}
.print-toolbar strong {
    display: block;
    color: #17261d;
    font-size: 14px;
}
.print-toolbar span {
    display: block;
    margin-top: 2px;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
}
.print-toolbar-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.print-toolbar-actions form {
    margin: 0;
}
.print-toolbar button,
.print-toolbar a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 0 15px;
    border: 1px solid #cfded3;
    border-radius: 999px;
    background: #fff;
    color: #205f3d;
    font-size: 12px;
    font-weight: 900;
    text-decoration: none;
    cursor: pointer;
}
.print-toolbar button.primary {
    border-color: #2f8553;
    background: #2f8553;
    color: #fff;
}
.print-toolbar button.secondary {
    border-color: #7bd19b;
    background: #eaf7ef;
    color: #14532d;
}
.system-report-standalone {
    background: #e8efeb;
    padding-bottom: 36px;
}
.system-report-standalone .report-document {
    width: min(210mm, calc(100vw - 48px));
    margin: 0 auto;
    background: transparent;
    box-shadow: none;
}
.system-report-standalone .report-cover {
    min-height: 297mm;
    display: block;
    margin: 26px auto;
    padding: 28mm 18mm 0;
    background: #fff !important;
    color: #fff;
    box-shadow: 0 18px 46px rgba(15, 23, 42, .16);
}
.system-report-standalone .report-cover-card {
    display: block;
    max-width: 145mm;
    min-height: 118mm;
    margin: 8mm auto 0;
    padding: 17mm 14mm;
    border-radius: 14px;
    background: #1f6b43 !important;
    color: #fff;
}
.system-report-standalone .report-cover-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 7px;
    max-width: 145mm;
    margin: 7mm auto 0;
    color: #17261d;
}
.system-report-standalone .report-cover-detail {
    padding: 7px 8px;
    border: 1px solid #dfe9e2;
    border-radius: 10px;
    background: #fff;
}
.system-report-standalone .report-cover-detail span {
    display: block;
    margin-bottom: 3px;
    color: #64748b;
    font-size: 7px;
    font-weight: 900;
    letter-spacing: .09em;
    text-transform: uppercase;
}
.system-report-standalone .report-cover-detail strong {
    display: block;
    color: #17261d;
    font-size: 9px;
    font-weight: 900;
    line-height: 1.35;
}
.system-report-standalone .report-cover-detail-wide {
    grid-column: 1 / -1;
}
.system-report-standalone .report-cover-footer {
    display: grid;
    max-width: 145mm;
    margin: 16mm auto 0;
    color: #17261d;
}
.system-report-standalone .report-confidential-note {
    padding: 4mm 5mm;
    border: 1px solid #dfe9e2;
    border-radius: 10px;
    color: #475569;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.45;
    text-align: center;
}
.system-report-standalone .report-cover-footer-brand {
    margin-top: 5mm;
    color: #64748b;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .12em;
    text-align: center;
    text-transform: uppercase;
}
.system-report-standalone .report-body {
    padding: 0;
}
.system-report-standalone .report-section {
    min-height: 297mm;
    margin: 26px auto;
    padding: 14mm 13mm;
    border: 0;
    background: #fff;
    box-shadow: 0 18px 46px rgba(15, 23, 42, .16);
}
.system-report-standalone .report-section:last-child {
    margin-bottom: 26px;
}
@media print {
    .print-toolbar {
        display: none !important;
    }
    .system-report-standalone {
        padding: 0;
    }
    .system-report-standalone .report-document,
    .system-report-standalone .report-cover,
    .system-report-standalone .report-section {
        width: auto;
        min-height: 0;
        margin: 0;
        box-shadow: none;
    }
}
CSS;

$toolbar = '
    <div class="print-toolbar">
        <div>
            <strong>PDF Preview</strong>
            <span>This preview follows the printable page layout.</span>
        </div>
        <div class="print-toolbar-actions">
            <a href="preview.php?' . system_report_escape($backQuery) . '">Back to Preview</a>
            <button type="button" class="secondary" onclick="window.print()">Print</button>
            <form method="post" action="download.php">
                ' . $downloadFields . '
                <button type="submit" class="primary">Save as PDF</button>
            </form>
        </div>
    </div>
';

$html = render_system_report_document($report, true, ['remarks_mode' => 'print', 'remarks' => $remarks, 'prepared_by' => $currentUser]);
$html = str_replace('</style>', "\n{$toolbarStyles}\n</style>", $html);
$html = str_replace('<body class="system-report-standalone">', '<body class="system-report-standalone">' . $toolbar, $html);

echo $html;
