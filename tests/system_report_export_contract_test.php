<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$payload = file_get_contents($root . '/public/reports/export-data.php');
$exporter = file_get_contents($root . '/public/assets/js/system-report-export.js');
$chartRenderer = file_get_contents($root . '/public/assets/js/system-report-charts.js');
$builder = file_get_contents($root . '/app/services/SystemReport.php');
$renderer = file_get_contents($root . '/app/services/SystemReportRenderer.php');
$paperPreview = file_get_contents($root . '/public/reports/pdf.php');
$package = json_decode((string) file_get_contents($root . '/package.json'), true);

if ($payload === false || $exporter === false || $chartRenderer === false || $builder === false || $renderer === false || $paperPreview === false || !is_array($package)) {
    throw new RuntimeException('Unable to read report export sources.');
}

$checks = [
    'only protected POST requests receive report export data' => str_contains($payload, 'require_report_access();')
        && str_contains($payload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($payload, "require_once __DIR__ . '/../../app/helpers/view.php';"),
    'payload uses the report builder rather than direct export queries' => str_contains($payload, 'build_system_report($dateFrom, $dateTo, $modules)')
        && !str_contains($payload, 'SELECT ')
        && str_contains($payload, 'csrf_enforce_request();'),
    'report contains six operational sections with appointments inside Visits' => str_contains($builder, "'visits' => 'Visits'")
        && str_contains($builder, "'ape' => 'APE Records'")
        && str_contains($builder, "'inventory' => 'Inventory'")
        && str_contains($builder, "'referrals' => 'Referrals'")
        && str_contains($builder, "'alerts' => 'Alerts and Incidents'")
        && str_contains($builder, "'feedback' => 'Clinic Feedback'")
        && !str_contains($builder, 'Patient Demographics')
        && str_contains($builder, 'passport_access_logs'),
    'transaction exports honor recognized selected sections and map old appointment links to Visits' => str_contains($builder, "if (in_array('appointments', \$requested, true)) \$requested[] = 'visits';")
        && str_contains($builder, 'array_intersect($allowed, $requested)')
        && str_contains($builder, 'return $selected ?: $allowed;'),
    'summary and attention counts reuse selected section metrics' => str_contains($builder, '$metricsByLabel = [];')
        && str_contains($builder, '$transactionSummary = [];')
        && str_contains($builder, '$attentionSummary = [];')
        && str_contains($builder, '\'transaction_summary\' => $transactionSummary')
        && str_contains($builder, '\'attention_summary\' => $attentionSummary')
        && !str_contains($builder, "'transaction_summary' => [")
        && !str_contains($builder, "'attention_summary' => ["),
    'appointment reporting is grouped with Visits and follows scheduled appointment dates' => str_contains($builder, "'description' => 'Clinic visits recorded and appointment activity scheduled during the selected period.'")
        && str_contains($builder, 'appointments WHERE appointment_datetime >= ? AND appointment_datetime < ?')
        && !str_contains($builder, "'Appointment Requests', system_report_scalar"),
    'tables are reserved for complete high-cardinality, audit, and snapshot detail' => substr_count($builder, "'tables' => [") === 6
        && str_contains($builder, 'Visit Purpose')
        && !str_contains($builder, 'Appointment Request Source')
        && str_contains($builder, 'APE Activity')
        && str_contains($builder, 'Medicine Dispensed by Item')
        && str_contains($builder, 'All Responses by Service')
        && !str_contains($builder, 'All Referral Destinations')
        && !str_contains($builder, "system_report_chart('Referral Status'")
        && !str_contains($builder, "system_report_chart('Referral Source'")
        && !str_contains($builder, 'Alert Incident Type')
        && !str_contains($builder, 'Incident Report Status')
        && !str_contains($builder, "system_report_chart('Alert Status'")
        && !str_contains($builder, 'Common Complaints')
        && str_contains($exporter, '(section.tables || []).forEach'),
    'browser XLSX library is declared' => isset($package['dependencies']['exceljs']),
    'A4 chart rendering keeps canonical chart rows while XLSX stays table-only' => str_contains($exporter, 'function chartRows(chart)')
        && str_contains($chartRenderer, 'const optionFor = (chart, { dark = false, pdf = false } = {}) =>')
        && str_contains($paperPreview, "mount(card.querySelector('.report-chart-visual'), chart, { dark, pdf: true })"),
    'horizontal charts honor bounded decimal scales when supplied' => str_contains($chartRenderer, 'const configuredMax = Number(axisMax);')
        && str_contains($chartRenderer, 'const horizontalAxisMax = horizontalMax + Math.max(1, horizontalMax * 0.16);')
        && str_contains($chartRenderer, 'max: horizontalAxisMax,')
        && str_contains($chartRenderer, 'decimalsInFloat: valueDecimals,')
        && str_contains($builder, "'axis_max' => isset(\$options['axis_max'])")
        && str_contains($builder, "['axis_max' => 7]"),
    'screen bar values remain outside full bars without clipping while PDF bars use square corners' => str_contains($chartRenderer, 'padding: { left: 4, right: 56, top: -4, bottom: -8 }')
        && str_contains($chartRenderer, 'textAnchor: "start",')
        && str_contains($chartRenderer, 'const placeHorizontalBarLabels = (host, horizontal) =>')
        && str_contains($chartRenderer, 'if (!horizontal) return;')
        && str_contains($chartRenderer, 'bar.getBBox()')
        && str_contains($chartRenderer, 'box.x + box.width + 10')
        && str_contains($chartRenderer, 'placeHorizontalBarLabels(host, instance.w?.config?.plotOptions?.bar?.horizontal);')
        && str_contains($chartRenderer, 'placeHorizontalBarLabels(canvas, instance.w?.config?.plotOptions?.bar?.horizontal);')
        && str_contains($chartRenderer, 'columnWidth: "48%", dataLabels: { position: "top" }')
        && str_contains($chartRenderer, 'padding: { left: 2, right: 8, top: 42, bottom: pdfXAxis ? 16 : 4 }')
        && str_contains($chartRenderer, 'offsetY: -18'),
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
    'xlsx exports branded data tables only, without report chart images or metric cards' => str_contains($exporter, 'const dataTables = [...(section.charts || []), ...(section.tables || [])];')
        && str_contains($exporter, 'dataTables.forEach((table) => addDataTable(table.title || "Transaction detail", table));')
        && substr_count($exporter, 'sheet.addImage(') === 2
        && !str_contains($exporter, 'Diagram summary:'),
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
        && str_contains($chartRenderer, 'borderRadius: pdf ? 0 : 6')
        && str_contains($exporter, 'for (let start = 0; start < chartDetails.length; start += 3)'),
    'xlsx keeps numeric source values without applying one invalid filter across separate tables' => str_contains($exporter, 'const rows = chartRows(chart);')
        && !str_contains($exporter, 'sheet.autoFilter'),
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
    'A4 preview owns the frozen browser export payload' => str_contains($renderer, 'function system_report_browser_export_payload')
        && str_contains($payload, 'system_report_browser_export_payload')
        && str_contains($paperPreview, 'systemReportExportPayload')
        && str_contains($paperPreview, 'form.__systemReportPayload = JSON.parse')
        && str_contains($paperPreview, 'window.print()')
        && str_contains($paperPreview, "printButton.textContent = 'Preparing PDF…';")
        && !str_contains($paperPreview, 'download.php')
        && !is_file($root . '/public/reports/download.php')
        && !is_file($root . '/app/services/SystemReportPdf.php'),
    'XLSX retains only the existing branded logo images' => str_contains($exporter, 'if (logoIds.plp) sheet.addImage')
        && str_contains($exporter, 'if (logoIds.custom) sheet.addImage')
        && substr_count($exporter, 'sheet.addImage(') === 2,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('System report export contract failed: ' . $label);
    }
}

echo "System report export contract passed.\n";
