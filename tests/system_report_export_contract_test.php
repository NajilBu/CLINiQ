<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$payload = file_get_contents($root . '/public/reports/export-data.php');
$exporter = file_get_contents($root . '/public/assets/js/system-report-export.js');
$chartRenderer = file_get_contents($root . '/public/assets/js/system-report-charts.js');
$builder = file_get_contents($root . '/app/services/SystemReport.php');
$download = file_get_contents($root . '/public/reports/download.php');
$serverPdf = file_get_contents($root . '/app/services/SystemReportPdf.php');
$package = json_decode((string) file_get_contents($root . '/package.json'), true);

if ($payload === false || $exporter === false || $chartRenderer === false || $builder === false || $download === false || $serverPdf === false || !is_array($package)) {
    throw new RuntimeException('Unable to read report export sources.');
}

$checks = [
    'only protected POST requests receive report export data' => str_contains($payload, 'require_report_access();')
        && str_contains($payload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($payload, "require_once __DIR__ . '/../../app/helpers/view.php';"),
    'payload uses the report builder rather than direct export queries' => str_contains($payload, 'build_system_report($dateFrom, $dateTo, $modules)')
        && !str_contains($payload, 'SELECT ')
        && str_contains($payload, 'csrf_enforce_request();'),
    'report contains only four approved transaction groups' => str_contains($builder, "'patient_care' => 'Patient Care Transactions'")
        && str_contains($builder, "'scheduling_referrals' => 'Scheduling and Referral Transactions'")
        && str_contains($builder, "'inventory_loans' => 'Inventory and Loan Transactions'")
        && str_contains($builder, "'safety' => 'Safety Transactions'")
        && !str_contains($builder, 'Patient Demographics')
        && !str_contains($builder, 'Clinic Feedback')
        && !str_contains($builder, 'passport_access_logs'),
    'transaction exports cannot be narrowed by request parameters' => str_contains($builder, 'return array_keys(system_report_module_labels());')
        && !str_contains($builder, 'array_intersect($allowed, $requested)'),
    'summary and attention counts come from the transaction builder' => str_contains($builder, "'transaction_summary' => [")
        && str_contains($builder, "'attention_summary' => [")
        && str_contains($builder, "system_report_metric('Clinical Entries'")
        && str_contains($builder, "system_report_metric('High or Critical Alerts'"),
    'supporting breakdowns are tables rather than extra diagrams' => substr_count($builder, "'tables' => [") === 4
        && str_contains($builder, 'Common Complaints')
        && str_contains($builder, 'Referral Destination')
        && str_contains($builder, 'Medicine Dispensed by Item')
        && str_contains($builder, 'Alert Incident Type')
        && str_contains($builder, 'Incident Report Status')
        && str_contains($exporter, '(section.tables || []).forEach'),
    'all requested libraries are declared' => isset($package['dependencies']['jspdf'], $package['dependencies']['jspdf-autotable'], $package['dependencies']['exceljs'], $package['dependencies']['pdfjs-dist']),
    'pdf contains tables and charts from canonical chart rows' => str_contains($exporter, 'chartImage(chart)')
        && str_contains($exporter, 'chartRows(chart)')
        && str_contains($exporter, 'autoTable(doc')
        && str_contains($exporter, 'window.cliniqSystemReportCharts.imageFor')
        && str_contains($chartRenderer, 'const optionFor = (chart, { dark = false } = {}) =>'),
    'empty measures stay compact in both export formats' => str_contains($exporter, 'function emptyChartMessage(chart)')
        && str_contains($exporter, 'function emptyChartsMessage(charts)')
        && !str_contains($exporter, 'ctx.fillText(chart.title || "Report chart"')
        && !str_contains($exporter, 'ctx.strokeRect(1, 1, width - 2, height - 2)'),
    'flat and zero-value charts use canonical compact presentations' => str_contains($builder, 'function system_report_chart_presentation')
        && str_contains($builder, "return 'summary';")
        && str_contains($builder, "return 'empty';")
        && str_contains($exporter, 'function chartPresentation(chart)')
        && str_contains($exporter, 'function chartGroups(section)')
        && str_contains($exporter, 'function emptyChartsMessage(charts)')
        && str_contains($exporter, 'const { charts, summaries, empty } = chartGroups(section);')
        && str_contains($exporter, 'chart.detail_in_pdf !== false'),
    'xlsx keeps complete source tables when a diagram is suppressed' => str_contains($exporter, '[...summaries, ...empty].forEach((chart) =>')
        && str_contains($exporter, 'addDataTable(`${chart.title} data`, chart);'),
    'keyed report sections are normalized once for both browser formats' => str_contains($exporter, 'const sectionsFor = (report)')
        && substr_count($exporter, 'const sections = sectionsFor(report);') === 2
        && !str_contains($exporter, 'report.sections.forEach'),
    'remarks retain their module keys after section normalization' => str_contains($exporter, 'data.remarks?.[section.key]'),
    'PDF follows the transaction-summary letterhead structure instead of a dashboard banner' => str_contains($exporter, 'doc.line(14, 37, width - 14, 37)')
        && str_contains($exporter, 'Clinic Transaction Summary')
        && str_contains($exporter, 'doc.text("Clinic Transaction Summary", width / 2, 49, { align: "center" })')
        && !str_contains($exporter, 'doc.line(14, 53, 74, 53)')
        && str_contains($exporter, 'Reporting period:')
        && str_contains($exporter, 'const transactionRows = summaryRows(report, "transaction_summary");')
        && str_contains($exporter, 'const attentionRows = summaryRows(report, "attention_summary");'),
    'report body restores color hierarchy with low-ink table fills and branded framing' => str_contains($chartRenderer, 'const palette = ["#2f8553"')
        && str_contains($exporter, 'fillColor: [237, 246, 237]')
        && str_contains($exporter, 'alternateRowStyles: { fillColor: [250, 252, 250] }')
        && str_contains($exporter, 'doc.setDrawColor(...green); doc.setLineWidth(.4);'),
    'transaction groups use natural pagination and remarks retain restrained highlighting in both formats' => str_contains($exporter, 'function chartInsight(chart)')
        && str_contains($exporter, 'const ensureSpace =')
        && str_contains($exporter, 'const gap = { block: 8, caption: 2, note: 7 };')
        && str_contains($exporter, 'for (const section of sections) {')
        && str_contains($exporter, 'if (y > 235) y = nextPage(section.title);')
        && str_contains($exporter, 'doc.rect(14, y, 180, remarkHeight, "S")')
        && str_contains($exporter, 'sheet.getCell(row, 1).value = "Remarks"')
        && str_contains($exporter, 'const wrapRemarkParagraph = (paragraph) =>')
        && str_contains($exporter, 'String(remark).split(/\\r?\\n\\s*\\r?\\n/)')
        && str_contains($exporter, 'doc.text(line, lineIndex ? 20 : 25, textY)')
        && str_contains($exporter, 'const outline = { style: "thin", color: { argb: "FF3E7C45" } };')
        && str_contains($exporter, 'const chartImage = (chart, compact = false) => window.cliniqSystemReportCharts.imageFor')
        && str_contains($exporter, 'const chartPanel = charts.length === 1 ? { width: 96, height: 59, gap: 7 } : { width: 56, height: 39, gap: 7 };')
        && str_contains($exporter, 'image: await chartImage(chart, true)')
        && str_contains($exporter, 'const startX = 14 + (180 - rowWidth) / 2;')
        && str_contains($exporter, 'doc.roundedRect(x, y, chartPanel.width, chartPanel.height, 1.5, 1.5, "S")')
        && str_contains($exporter, 'for (let start = 0; start < chartDetails.length; start += 3)'),
    'xlsx keeps numeric values and filters' => str_contains($exporter, 'Number(metric.value) || 0')
        && str_contains($exporter, 'sheet.autoFilter'),
    'xlsx overview is a compact transaction summary rather than duplicate detail' => str_contains($exporter, 'workbook.addWorksheet("Transaction Summary"')
        && str_contains($exporter, 'addSummaryTable("TRANSACTION SUMMARY", summaryRows(report, "transaction_summary"));')
        && str_contains($exporter, 'addSummaryTable("ATTENTION SUMMARY", summaryRows(report, "attention_summary"));')
        && !str_contains($exporter, 'overview.addImage'),
    'transaction export has a stable filename and excludes the retired analytics title' => str_contains($exporter, 'CLINiQ-Clinic-Transaction-Summary')
        && !str_contains($exporter, 'System Analytics Report'),
    'custom logo does not replace the fixed PLP logo' => str_contains($exporter, 'plp_logo_url')
        && str_contains($exporter, 'custom_logo_url'),
    'expired CSRF is handled without weakening the shared guard' => str_contains($exporter, 'form has expired|could not be verified')
        && str_contains($exporter, 'Refresh the page and try again.'),
    'PDF is generated by the protected server fallback' => str_contains($download, 'render_system_report_pdf')
        && str_contains($download, "header('Content-Type: application/pdf')")
        && str_contains($serverPdf, '%PDF-1.4')
        && str_contains($serverPdf, "'Transaction Summary', (array) (\$report['transaction_summary'] ?? [])")
        && str_contains($serverPdf, "'Attention Summary', (array) (\$report['attention_summary'] ?? [])")
        && str_contains($serverPdf, '$this->y = 744;')
        && str_contains($serverPdf, 'public function table(string $title, array $rows): void')
        && str_contains($serverPdf, "'Measure'")
        && str_contains($serverPdf, 'private function footer(int $page, int $total): string'),
    'XLSX chart images render from a paintable off-screen host' => str_contains($chartRenderer, 'pointer-events:none;')
        && !str_contains($chartRenderer, 'visibility:hidden;')
        && str_contains($chartRenderer, 'animation: false')
        && str_contains($chartRenderer, 'window.setTimeout(finish, 1000)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('System report export contract failed: ' . $label);
    }
}

echo "System report export contract passed.\n";
