<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReportRenderer.php';
require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

foreach (['Visit Purpose', 'Common Complaints', 'APE Clearance Status'] as $title) {
    if (!str_contains($source, "system_report_chart('{$title}'")) {
        throw new RuntimeException("Patient care transactions are missing {$title}.");
    }
}

if (!str_contains($source, 'function system_report_visit_volume')
    || !str_contains($source, "'week' =>")
    || !str_contains($source, "'month' =>")
    || !str_contains($source, "'detail_in_pdf' => \$detailInPdf")
    || !str_contains($source, "'Visits by ' . ucfirst(\$bucket)")
    || !str_contains($source, '[, $rangeEnd] = system_report_range($dateFrom, $dateTo);')) {
    throw new RuntimeException('Visit volume must use zero-filled adaptive buckets and keep its complete detail out of the standard PDF.');
}

if (array_key_exists('demographics', system_report_module_labels())
    || str_contains($source, 'Visits by Recorded Sex')
    || str_contains($source, 'Patient Type Seen')) {
    throw new RuntimeException('Patient care transactions must not include demographic breakdowns.');
}

if (!str_contains($source, 'APE Records')
    || !str_contains($source, 'APE Findings')
    || !str_contains($source, 'Visit Purpose')
    || !str_contains($source, 'created_at >= ? AND created_at < ?')) {
    throw new RuntimeException('Patient care transaction totals are incomplete.');
}

$flat = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 1], ['label' => 'Oct 2', 'value' => 1]], 'No visits.', 0, 'line', false, '2 visits across 2 days.');
$varied = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 0], ['label' => 'Oct 2', 'value' => 1]], 'No visits.', 0, 'line');
$empty = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 0]], 'No visits.', 0, 'line');
$implicitSummary = system_report_chart('Appointment Status', [['label' => 'Pending', 'value' => 1], ['label' => 'Completed', 'value' => 1]], 'No appointments.', 0, 'donut');
if ($flat['presentation'] !== 'diagram' || $varied['presentation'] !== 'diagram' || $empty['presentation'] !== 'empty' || $flat['detail_in_pdf'] !== false) {
    throw new RuntimeException('Report charts must render every non-empty multi-category distribution.');
}
if (!str_contains(render_system_report_chart($empty), 'No visits.')) {
    throw new RuntimeException('A zero-filled empty chart must render its compact empty message.');
}
if (($implicitSummary['presentation'] ?? '') !== 'diagram') {
    throw new RuntimeException('Equal multi-category status distributions must remain diagrams.');
}

echo "Patient care transaction checks passed. No database writes.\n";
