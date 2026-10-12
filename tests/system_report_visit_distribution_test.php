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

foreach (['Visit Purpose', 'Patients Attended by Doctor'] as $title) {
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
    || !str_contains($visitsSource, "WHEN v.patient_person_id IS NULL THEN 'Guest'")
    || !str_contains($visitsSource, "WHEN cs.person_id IS NOT NULL THEN 'Clinic Staff'")
    || !str_contains($visitsSource, "WHEN s.person_id IS NOT NULL THEN 'Students'")) {
    throw new RuntimeException('Visits analytics must use the shared visitor categories for the selected period.');
}

if (!str_contains($source, 'function system_report_visit_volume')
    || !str_contains($source, "'week' =>")
    || !str_contains($source, "'month' =>")
    || !str_contains($source, "'detail_in_pdf' => \$detailInPdf")
    || !str_contains($source, "'Visits by ' . ucfirst(\$bucket)")
    || !str_contains($source, '[, $rangeEnd] = system_report_range($dateFrom, $dateTo);')) {
    throw new RuntimeException('Visit volume must use zero-filled adaptive buckets and keep its complete detail out of the standard PDF.');
}

if (!str_contains($source, 'function system_report_passport_access_by_day')
    || !str_contains($source, "\$bucket = \$days <= 31 ? 'day' : (\$days <= 180 ? 'week' : 'month');")
    || !str_contains($source, "'visual_rows' => \$passportAccess['visual_rows']")
    || !str_contains($source, "'rows' => \$rows")
    || !str_contains($source, "'Passport Access by ' . ucfirst(\$bucket)")) {
    throw new RuntimeException('Passport access charts must aggregate only their visual series while retaining daily table rows.');
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
    || !str_contains($visitsSource, 'Appointment Follow-through')
    || !str_contains($source, "'Scheduled / awaiting check-in'")
    || !str_contains($source, "'Checked in'")
    || !str_contains($source, "'Clinical visit completed'")
    || !str_contains($source, "'Missed or cancelled'")
    || !str_contains($source, 'function system_report_appointment_follow_through_rows')
    || !str_contains($source, "v.status = 'Completed'")
    || !str_contains($source, "status IN ('No Show', 'Cancelled')")
    || !str_contains($visitsSource, 'Patients Attended by Doctor')
    || !str_contains($visitsSource, "cs.staff_role = 'doctor'")
    || !str_contains($visitsSource, 'COUNT(DISTINCT v.patient_person_id) value FROM visits v INNER JOIN clinic_staff')
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

$doctorAttendancePosition = strpos($visitsSource, "system_report_chart('Patients Attended by Doctor'");
$visitorAffiliationPosition = strpos($visitsSource, "system_report_chart('Visitor Affiliation'");
$visitDayPosition = strpos($visitsSource, '$volume = system_report_visit_volume');
$visitHourPosition = strpos($visitsSource, "system_report_chart('Visits by Hour'");
if ($doctorAttendancePosition === false || $visitorAffiliationPosition === false || $visitDayPosition === false || $visitHourPosition === false
    || $doctorAttendancePosition > $visitorAffiliationPosition || $visitorAffiliationPosition > $visitDayPosition || $visitDayPosition > $visitHourPosition) {
    throw new RuntimeException('Doctor attendance must precede visitor affiliation, followed by the final visit-volume charts.');
}

if (!str_contains($source, "\$sections['ape']")
    || !str_contains($source, "'APE Records'")
    || !str_contains($source, 'Current active APE cycle snapshot')
    || !str_contains($source, 'Students Examined')
    || !str_contains($source, 'Requires Clinic Action')
    || !str_contains($source, 'Requirements Needing Attention')
    || !str_contains($source, 'Current APE Disposition')
    || !str_contains($source, "system_report_chart('APE Requirement Items by Status', system_report_ape_requirement_rows(\$newDb, \$apeCycleId), 'No active APE cycle is currently available.', 0, 'donut')")
    || !str_contains($source, 'APE Requirement Items by Status')
    || !str_contains($source, 'APE Activity During Selected Period')
    || str_contains($source, 'APE Workflow Events')
    || str_contains($source, 'APE Finding Outcome')) {
    throw new RuntimeException('APE records analytics must use current-cycle disposition and requirement data.');
}

$apeSnapshot = system_report_ape_disposition([
    ['exam_date' => '2026-10-01', 'workflow_status' => 'Cleared', 'clearance_status' => 'Cleared'],
    ['exam_date' => '2026-10-02', 'workflow_status' => 'Reviewed', 'clearance_status' => 'Pending'],
    ['exam_date' => '2026-10-03', 'workflow_status' => 'Follow-up Required', 'clearance_status' => 'For Follow-up', 'follow_up_required' => 1],
    ['exam_date' => null, 'workflow_status' => 'Registered', 'clearance_status' => 'Pending'],
    ['exam_date' => '2026-10-04', 'workflow_status' => 'Inactive', 'clearance_status' => 'Pending'],
]);
if ($apeSnapshot['students_examined'] !== 3 || $apeSnapshot['cleared'] !== 1 || $apeSnapshot['requires_clinic_action'] !== 2 || $apeSnapshot['in_progress'] !== 1
    || $apeSnapshot['disposition_rows'] !== [
        ['label' => 'Cleared', 'value' => 1],
        ['label' => 'Requires clinic action', 'value' => 2],
        ['label' => 'In progress', 'value' => 1],
    ]) {
    throw new RuntimeException('APE disposition must classify each active-cycle record once using the staff queue.');
}

$emptyApeSnapshot = system_report_ape_disposition([]);
if ($emptyApeSnapshot['students_examined'] !== 0 || $emptyApeSnapshot['cleared'] !== 0 || $emptyApeSnapshot['requires_clinic_action'] !== 0 || $emptyApeSnapshot['in_progress'] !== 0) {
    throw new RuntimeException('An unavailable APE cycle must produce an empty disposition without inventing activity.');
}

$flat = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 1], ['label' => 'Oct 2', 'value' => 1]], 'No visits.', 0, 'line', false, '2 visits across 2 days.');
$varied = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 0], ['label' => 'Oct 2', 'value' => 1]], 'No visits.', 0, 'line');
$empty = system_report_chart('Visits by Day', [['label' => 'Oct 1', 'value' => 0]], 'No visits.', 0, 'line');
$implicitSummary = system_report_chart('Appointment Follow-through', [['label' => 'Scheduled / awaiting check-in', 'value' => 1], ['label' => 'Checked in', 'value' => 1]], 'No appointments.', 0, 'donut');
$appointmentFollowThroughChart = system_report_chart('Appointment Follow-through', [
    ['label' => 'Scheduled / awaiting check-in', 'value' => 1], ['label' => 'Checked in', 'value' => 0], ['label' => 'Clinical visit completed', 'value' => 0], ['label' => 'Missed or cancelled', 'value' => 0],
], 'No appointments.', 0, 'bar', true, '', ['visual_rows' => [['label' => 'Scheduled / awaiting check-in', 'value' => 1]], 'force_diagram' => true]);
$hourlyChart = system_report_chart('Visits by Hour', array_map(static fn(int $hour): array => ['label' => $hour . ':00', 'value' => $hour === 9 ? 4 : 0], range(0, 23)), 'No visits.', 0, 'column', true, '', ['hide_zero_rows' => true]);
$singleCategoryBreakdown = system_report_chart('Visitor Affiliation', [['label' => 'Students', 'value' => 12]], 'No visits.', 0, 'bar', true, '', ['force_diagram' => true]);
if ($flat['presentation'] !== 'diagram' || $varied['presentation'] !== 'diagram' || $empty['presentation'] !== 'empty' || $flat['detail_in_pdf'] !== false) {
    throw new RuntimeException('Report charts must render every non-empty multi-category distribution.');
}
if (!str_contains(render_system_report_chart($empty), 'No visits.')) {
    throw new RuntimeException('A zero-filled empty chart must render its compact empty message.');
}
if (($implicitSummary['presentation'] ?? '') !== 'diagram') {
    throw new RuntimeException('Equal multi-category status distributions must remain diagrams.');
}
if (($appointmentFollowThroughChart['presentation'] ?? '') !== 'diagram') {
    throw new RuntimeException('Appointment follow-through bars must remain visible when only one stage has records.');
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
