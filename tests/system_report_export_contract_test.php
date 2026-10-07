<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$payload = file_get_contents($root . '/public/reports/export-data.php');
$exporter = file_get_contents($root . '/public/assets/js/system-report-export.js');
$package = json_decode((string) file_get_contents($root . '/package.json'), true);

if ($payload === false || $exporter === false || !is_array($package)) {
    throw new RuntimeException('Unable to read report export sources.');
}

$checks = [
    'only protected POST requests receive report export data' => str_contains($payload, 'require_report_access();')
        && str_contains($payload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($payload, "require_once __DIR__ . '/../../app/helpers/view.php';"),
    'payload uses the report builder rather than direct export queries' => str_contains($payload, 'build_system_report($dateFrom, $dateTo, $modules)')
        && !str_contains($payload, 'SELECT '),
    'all requested libraries are declared' => isset($package['dependencies']['jspdf'], $package['dependencies']['jspdf-autotable'], $package['dependencies']['exceljs'], $package['dependencies']['pdfjs-dist']),
    'pdf contains tables and charts from canonical chart rows' => str_contains($exporter, 'chartImage(chart)')
        && str_contains($exporter, 'chartRows(chart)')
        && str_contains($exporter, 'autoTable(doc'),
    'empty measures stay compact in both export formats' => str_contains($exporter, 'function emptyChartMessage(chart)')
        && str_contains($exporter, 'function emptyChartsMessage(charts)')
        && !str_contains($exporter, 'ctx.fillText(chart.title || "Report chart"')
        && !str_contains($exporter, 'ctx.strokeRect(1, 1, width - 2, height - 2)'),
    'zero-value charts are grouped into one compact section message' => str_contains($exporter, 'function chartHasData(chart)')
        && str_contains($exporter, 'function chartGroups(section)')
        && str_contains($exporter, 'function emptyChartsMessage(charts)')
        && substr_count($exporter, 'const { charts, empty } = chartGroups(section);') === 2,
    'keyed report sections are normalized once for both browser formats' => str_contains($exporter, 'const sectionsFor = (report)')
        && substr_count($exporter, 'const sections = sectionsFor(report);') === 2
        && !str_contains($exporter, 'report.sections.forEach'),
    'remarks retain their module keys after section normalization' => str_contains($exporter, 'data.remarks?.[section.key]'),
    'PDF follows the reference letterhead structure instead of a dashboard banner' => str_contains($exporter, 'doc.line(14, 37, width - 14, 37)')
        && str_contains($exporter, 'System Analytics Report')
        && str_contains($exporter, 'doc.text("System Analytics Report", width / 2, 49, { align: "center" })')
        && !str_contains($exporter, 'doc.line(14, 53, 74, 53)')
        && str_contains($exporter, 'Reporting period:'),
    'browser exports use compact green-headed tables in both formats' => substr_count($exporter, 'FF3E7C45') >= 4
        && str_contains($exporter, 'alternateRowStyles: { fillColor: paleGreen }'),
    'sections start on separate pages and remarks are highlighted in both formats' => str_contains($exporter, 'function chartInsight(chart)')
        && str_contains($exporter, 'const ensureSpace =')
        && str_contains($exporter, 'const gap = { block: 8, caption: 2, note: 7 };')
        && str_contains($exporter, 'if (sectionIndex) y = nextPage(section.title);')
        && str_contains($exporter, 'doc.rect(14, y, 180, remarkHeight, "S")')
        && str_contains($exporter, 'sheet.getCell(row, 1).value = "Remarks"')
        && str_contains($exporter, 'const wrapRemarkParagraph = (paragraph) =>')
        && str_contains($exporter, 'String(remark).split(/\\r?\\n\\s*\\r?\\n/)')
        && str_contains($exporter, 'doc.text(line, lineIndex ? 20 : 25, textY)')
        && str_contains($exporter, 'const outline = { style: "thin", color: { argb: "FF3E7C45" } };')
        && str_contains($exporter, 'Figure: ${chart.title || "Report diagram"}'),
    'xlsx keeps numeric values and filters' => str_contains($exporter, 'Number(metric.value) || 0')
        && str_contains($exporter, 'sheet.autoFilter'),
    'xlsx overview is a compact metric summary rather than duplicate detail' => str_contains($exporter, 'overviewHeader.values = ["Module", "Metric", "Value"]')
        && str_contains($exporter, 'record.values = [section.title, metric.label, Number(metric.value) || 0]')
        && !str_contains($exporter, 'overview.addImage'),
    'custom logo does not replace the fixed PLP logo' => str_contains($exporter, 'plp_logo_url')
        && str_contains($exporter, 'custom_logo_url'),
    'expired CSRF is handled without weakening the shared guard' => str_contains($exporter, 'form has expired|could not be verified')
        && str_contains($exporter, 'Refresh the page and try again.'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('System report export contract failed: ' . $label);
    }
}

echo "System report export contract passed.\n";
