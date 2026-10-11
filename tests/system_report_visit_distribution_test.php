<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReportRenderer.php';
require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

$visitsStart = strpos($source, "if (in_array('visits', \$modules, true))");
$visitsEnd = strpos($source, "if (in_array('ape', \$modules, true))", $visitsStart ?: 0);
$visitsSource = $visitsStart === false || $visitsEnd === false ? '' : substr($source, $visitsStart, $visitsEnd - $visitsStart);

foreach (['Visit Purpose', 'Visit Status (Current)'] as $title) {
    if (!str_contains($source, "system_report_chart('{$title}'")) {
        throw new RuntimeException("Visits analytics are missing {$title}.");
    }
}

foreach (['Visit Source', 'Visits by Hour'] as $title) {
    if (!str_contains($visitsSource, "system_report_chart('{$title}'")) {
        throw new RuntimeException("Visits analytics are missing {$title}.");
    }
}

foreach (['Student Visitors by Recorded Gender', 'Student Visitors by Year Level'] as $title) {
    if (!str_contains($visitsSource, "system_report_chart('{$title}'")) {
        throw new RuntimeException("Visits analytics are missing {$title}.");
    }
}

if (!str_contains($visitsSource, "system_report_chart('Visitor Affiliation'")
    || !str_contains($visitsSource, "WHEN v.patient_person_id IS NULL THEN 'Guest / walk-in'")
    || !str_contains($visitsSource, "WHEN cs.person_id IS NOT NULL THEN 'Clinic staff'")) {
    throw new RuntimeException('Visits analytics must include visitor affiliation for the selected period.');
}

if (!str_contains($source, 'function system_report_visit_volume')
    || !str_contains($source, "'week' =>")
    || !str_contains($source, "'month' =>")
    || !str_contains($source, "'detail_in_pdf' => \$detailInPdf")
    || !str_contains($source, "'Visits by ' . ucfirst(\$bucket)")
    || !str_contains($source, '[, $rangeEnd] = system_report_range($dateFrom, $dateTo);')) {
    throw new RuntimeException('Visit volume must use zero-filled adaptive buckets and keep its complete detail out of the standard PDF.');
}

if (str_contains($visitsSource, 'Visits by Recorded Sex')
    || str_contains($visitsSource, 'Patient Type Seen')) {
    throw new RuntimeException('Visits analytics must not include demographic breakdowns.');
}

if (!str_contains($visitsSource, "'Registered Patients Served'")
    || !str_contains($visitsSource, "'Clinical Entries Documented During Period'")
    || !str_contains($visitsSource, "'Visits Completed During Period'")
    || !str_contains($visitsSource, "'Visits with Vitals'")
    || !str_contains($visitsSource, "'Median Time to First Action'")
    || !str_contains($visitsSource, "'Median Time to Completion'")
    || !str_contains($visitsSource, 'SELECT COUNT(*) FROM visit_entries WHERE created_at >= ? AND created_at < ?')
    || !str_contains($visitsSource, 'completed_at >= ? AND completed_at < ?')
    || !str_contains($visitsSource, 'EXISTS (SELECT 1 FROM vital_signs vs WHERE vs.visit_id = v.visit_id)')
    || !str_contains($visitsSource, 'COUNT(DISTINCT v.patient_person_id) value FROM visits v JOIN students s')
    || !str_contains($visitsSource, 'SELECT DISTINCT v.patient_person_id, s.year_level FROM visits v JOIN students s')
    || !str_contains($visitsSource, 'Visit Purpose')
    || !str_contains($visitsSource, "'For Approval'")
    || !str_contains($visitsSource, "'Approved'")
    || !str_contains($visitsSource, "'For Completion'")
    || !str_contains($visitsSource, "'Completed'")
    || !str_contains($visitsSource, 'Appointment Status')
    || !str_contains($source, 'function system_report_appointment_status_rows')
    || !str_contains($source, "'Pending' => 'For Approval'")
    || !str_contains($source, "'Scheduled' => 'Approved'")
    || !str_contains($source, "'No Show' => 'No Show'")
    || str_contains($visitsSource, 'APE Clearance Status')
    || str_contains($visitsSource, 'APE Records')
    || str_contains($visitsSource, 'APE Findings')
    || str_contains($visitsSource, 'Common Complaints')
    || str_contains($source, 'system_report_ape_range')) {
    throw new RuntimeException('Visits analytics must include the visit and appointment care journey without APE data.');
}

if (!str_contains($source, 'function system_report_median')) {
    throw new RuntimeException('Visit timing analytics must use a median calculation.');
}

$visitStatusPosition = strpos($visitsSource, "system_report_chart('Visit Status (Current)'");
$visitDayPosition = strpos($visitsSource, '$volume = system_report_visit_volume');
$visitHourPosition = strpos($visitsSource, "system_report_chart('Visits by Hour'");
if ($visitStatusPosition === false || $visitDayPosition === false || $visitHourPosition === false
    || $visitStatusPosition > $visitDayPosition || $visitDayPosition > $visitHourPosition) {
    throw new RuntimeException('Visits by Day and Visits by Hour must be the final paired analytical charts.');
}

if (!str_contains($source, "\$sections['ape']")
    || !str_contains($source, "'APE Workflow'")
    || !str_contains($source, 'APE Clearances Approved')
    || !str_contains($source, 'APE Follow-ups Required')
    || !str_contains($source, 'APE Workflow Events')
    || !str_contains($source, "'No APE workflow events were recorded during this period.', 0, 'donut'),")
    || !str_contains($source, 'APE Activity')) {
    throw new RuntimeException('APE workflow analytics must be its own explicit section.');
}

$flat = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 1], ['label' => 'Oct 2', 'value' => 1]], 'No visits.', 0, 'line', false, '2 visits across 2 days.');
$varied = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 0], ['label' => 'Oct 2', 'value' => 1]], 'No visits.', 0, 'line');
$empty = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 0]], 'No visits.', 0, 'line');
$implicitSummary = system_report_chart('Appointment Status', [['label' => 'Pending', 'value' => 1], ['label' => 'Completed', 'value' => 1]], 'No appointments.', 0, 'donut');
$appointmentStatusChart = system_report_chart('Appointment Status', [
    ['label' => 'For Approval', 'value' => 1], ['label' => 'Approved', 'value' => 0], ['label' => 'For Completion', 'value' => 0], ['label' => 'Completed', 'value' => 0], ['label' => 'Declined', 'value' => 0], ['label' => 'Cancelled', 'value' => 0], ['label' => 'No Show', 'value' => 0],
], 'No appointments.', 0, 'bar', true, '', ['visual_rows' => [['label' => 'For Approval', 'value' => 1]], 'force_diagram' => true]);
$hourlyChart = system_report_chart('Visits by Hour', array_map(static fn(int $hour): array => ['label' => $hour . ':00', 'value' => $hour === 9 ? 4 : 0], range(0, 23)), 'No visits.', 0, 'column', true, '', ['hide_zero_rows' => true]);
$singleCategoryBreakdown = system_report_chart('Visitor Affiliation', [['label' => 'Student', 'value' => 12]], 'No visits.', 0, 'bar', true, '', ['force_diagram' => true]);
if ($flat['presentation'] !== 'diagram' || $varied['presentation'] !== 'diagram' || $empty['presentation'] !== 'empty' || $flat['detail_in_pdf'] !== false) {
    throw new RuntimeException('Report charts must render every non-empty multi-category distribution.');
}
if (!str_contains(render_system_report_chart($empty), 'No visits.')) {
    throw new RuntimeException('A zero-filled empty chart must render its compact empty message.');
}
if (($implicitSummary['presentation'] ?? '') !== 'diagram') {
    throw new RuntimeException('Equal multi-category status distributions must remain diagrams.');
}
if (($appointmentStatusChart['presentation'] ?? '') !== 'diagram') {
    throw new RuntimeException('Appointment status bars must remain visible when only one status has records.');
}
if (!str_contains(render_system_report_chart(system_report_chart('Visit Source', [['label' => 'Clinic Staff', 'value' => 44]])), 'Clinic Staff')
    || !str_contains(render_system_report_chart(system_report_chart('Visit Source', [['label' => 'Clinic Staff', 'value' => 44]])), '>44<')) {
    throw new RuntimeException('Single-category summaries must visibly render both their label and value.');
}
if (($hourlyChart['presentation'] ?? '') !== 'diagram' || count($hourlyChart['rows']) !== 24 || count($hourlyChart['visual_rows']) !== 1 || ($hourlyChart['visual_rows'][0]['label'] ?? '') !== '9:00' || ($singleCategoryBreakdown['presentation'] ?? '') !== 'summary') {
    throw new RuntimeException('Hourly charts must remain diagrams while single-category visitor breakdowns remain readable summaries.');
}
if (!str_contains($source, "in_array(\$renderType, ['line', 'column'], true)")
    || !str_contains(file_get_contents(dirname(__DIR__) . '/public/reports/index.php') ?: '', "Cache-Control: private, no-store, max-age=0")) {
    throw new RuntimeException('Analytics must keep hourly charts visual and prevent stale report responses.');
}

$manyCategories = system_report_chart('Referred To', array_map(static fn(int $index): array => ['label' => 'Destination ' . $index, 'value' => 1], range(1, 12)));
$fixedScale = system_report_chart('Average SERVPERF Scores', [['label' => 'Overall', 'value' => 4.25]], 'No feedback.', 2, 'bar', true, '', ['axis_max' => 7]);
if (count($manyCategories['rows']) !== 12 || count($manyCategories['visual_rows']) !== 11 || ($manyCategories['visual_rows'][10]['label'] ?? '') !== 'Other' || ($fixedScale['axis_max'] ?? null) !== 7.0) {
    throw new RuntimeException('Analytics charts must bound only visual categories and preserve fixed score scales.');
}

echo "Patient care transaction checks passed. No database writes.\n";
