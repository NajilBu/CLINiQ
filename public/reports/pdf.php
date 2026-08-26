<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_login();

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
@media print {
    .print-toolbar {
        display: none !important;
    }
}
CSS;

$toolbar = '
    <div class="print-toolbar">
        <div>
            <strong>Printable Report</strong>
            <span>Choose &quot;Save as PDF&quot; in the print window to export this report.</span>
        </div>
        <div class="print-toolbar-actions">
            <a href="preview.php?' . system_report_escape($backQuery) . '">Back to Preview</a>
            <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
        </div>
    </div>
';

$autoPrintScript = <<<'HTML'
<script>
    window.addEventListener('load', () => {
        setTimeout(() => window.print(), 350);
    });
</script>
HTML;

$html = render_system_report_document($report, true, ['remarks_mode' => 'print', 'remarks' => $remarks]);
$html = str_replace('</style>', "\n{$toolbarStyles}\n</style>", $html);
$html = str_replace('<body class="system-report-standalone">', '<body class="system-report-standalone">' . $toolbar, $html);
$html = str_replace('</body>', $autoPrintScript . "\n</body>", $html);

echo $html;
