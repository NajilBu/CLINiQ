<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/AppointmentWorkflow.php';

$submitted = [];
for ($day = 1; $day <= 7; $day++) {
    $submitted[$day] = ['start' => '09:00', 'end' => '15:00'];
}
$submitted[2]['enabled'] = '1';
$submitted[6]['enabled'] = '1';
$schedule = appointment_normalize_weekly_schedule($submitted);

$standard = appointment_schedule_from_form([
    'base_start' => '08:00', 'base_end' => '17:00',
    'open_days' => ['1', '2', '3', '4', '5'],
    'overrides' => [['day' => '3', 'start' => '10:00', 'end' => '16:00']],
]);
if (!$standard[1]['enabled'] || $standard[6]['enabled']
    || $standard[1]['start'] !== '08:00' || $standard[3]['start'] !== '10:00'
    || appointment_slot_is_open_for_schedule($standard, '2026-09-16', '09:00:00')
    || !appointment_slot_is_open_for_schedule($standard, '2026-09-16', '10:00:00')) {
    throw new RuntimeException('Standard hours or a day-specific override was not applied.');
}
if (appointment_normalize_weekly_schedule($standard)[6]['enabled']) {
    throw new RuntimeException('A closed day was reopened while saving the normalized schedule.');
}
$extendedHours = appointment_schedule_from_form([
    'base_start' => '07:00', 'base_end' => '21:00', 'open_days' => ['1'], 'overrides' => [],
]);
if (!appointment_slot_is_open_for_schedule($extendedHours, '2026-09-14', '07:00:00')
    || !appointment_slot_is_open_for_schedule($extendedHours, '2026-09-14', '20:00:00')) {
    throw new RuntimeException('The 7:00 AM to 9:00 PM working-hours range was not accepted.');
}
foreach ([['06:00', '17:00'], ['07:00', '22:00']] as [$invalidStart, $invalidEnd]) {
    try {
        appointment_schedule_from_form(['base_start' => $invalidStart, 'base_end' => $invalidEnd, 'open_days' => ['1']]);
        throw new RuntimeException('Working hours outside 7:00 AM to 9:00 PM were accepted.');
    } catch (InvalidArgumentException $expected) {
        // Expected.
    }
}
if (appointment_weekly_hour_bounds($standard) !== [8, 17]) {
    throw new RuntimeException('Calendar hour bounds should follow open-day hours.');
}
$varied = $standard;
$varied[2]['start'] = '07:00';
$varied[2]['end'] = '18:00';
$varied[6]['start'] = '07:00';
$varied[6]['end'] = '21:00';
if (appointment_weekly_hour_bounds($varied) !== [7, 18]) {
    throw new RuntimeException('Calendar bounds should span open days only.');
}
if (!appointment_range_is_open_for_schedule($standard, '2026-09-16', '10:00:00', '16:00:00')
    || appointment_range_is_open_for_schedule($standard, '2026-09-16', '09:00:00', '16:00:00')
    || appointment_range_is_open_for_schedule($standard, '2026-09-20', '10:00:00', '11:00:00')) {
    throw new RuntimeException('Range validation did not respect monthly-style working hours.');
}
if (appointment_normalize_month_key('2027-02') !== '2027-02') {
    throw new RuntimeException('A valid future schedule month was not normalized.');
}
try {
    appointment_normalize_month_key('2027-13');
    throw new RuntimeException('An invalid schedule month was accepted.');
} catch (InvalidArgumentException $expected) {
    // Expected.
}

$mergedUnavailable = appointment_merge_continuous_unavailable_blocks([
    ['_start_minute' => 600, '_end_minute' => 660, 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'reason' => null],
    ['_start_minute' => 660, '_end_minute' => 720, 'start_time' => '11:00:00', 'end_time' => '12:00:00', 'reason' => 'Clinic unavailable'],
    ['_start_minute' => 780, '_end_minute' => 840, 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'reason' => 'Staff meeting'],
    ['_start_minute' => 840, '_end_minute' => 900, 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'reason' => 'Equipment maintenance'],
]);
if (count($mergedUnavailable) !== 3
    || $mergedUnavailable[0]['_start_minute'] !== 600
    || $mergedUnavailable[0]['_end_minute'] !== 720
    || $mergedUnavailable[0]['end_time'] !== '12:00:00') {
    throw new RuntimeException('Continuous unavailable periods with the same reason were not merged correctly.');
}
try {
    appointment_schedule_from_form([
        'base_start' => '08:00', 'base_end' => '17:00', 'open_days' => ['1'],
        'overrides' => [['day' => '6', 'start' => '09:00', 'end' => '15:00']],
    ]);
    throw new RuntimeException('An override for a closed day was accepted.');
} catch (InvalidArgumentException $expected) {
    // Expected.
}

if ($schedule[1]['enabled'] || !$schedule[2]['enabled'] || !$schedule[6]['enabled']) {
    throw new RuntimeException('Per-day opening switches were not preserved.');
}
if (!appointment_slot_is_open_for_schedule($schedule, '2026-09-15', '09:00:00')
    || !appointment_slot_is_open_for_schedule($schedule, '2026-09-19', '14:00:00')
    || appointment_slot_is_open_for_schedule($schedule, '2026-09-15', '15:00:00')
    || appointment_slot_is_open_for_schedule($schedule, '2026-09-14', '09:00:00')
    || appointment_slot_is_open_for_schedule($schedule, '2026-09-15', '09:30:00')) {
    throw new RuntimeException('Appointment slot validation did not respect the weekly schedule.');
}

$patientAppointmentSource = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-appointment.php');
if (!str_contains($patientAppointmentSource, 'slot.hidden = !isWithinHours;')
    || !str_contains($patientAppointmentSource, 'start >= hours.start && end <= hours.end')
    || !str_contains($patientAppointmentSource, 'appointment_schedule_for_month')
    || !str_contains($patientAppointmentSource, 'data-appointment-month-link')
    || !str_contains($patientAppointmentSource, "fetch(link.href")
    || !str_contains($patientAppointmentSource, "currentPanel.replaceWith(replacement)")
    || !str_contains($patientAppointmentSource, "history.pushState")
    || str_contains($patientAppointmentSource, "isClosed ? 'Closed'")) {
    throw new RuntimeException('Patient calendar must change months without refreshing and hide slots outside clinic hours.');
}

$availabilitySource = file_get_contents(dirname(__DIR__) . '/public/appointments/_availability_section.php');
$availabilityActionSource = file_get_contents(dirname(__DIR__) . '/public/appointments/availability.php');
if (!str_contains($availabilitySource, 'data-working-hours-mode="future"')
    || !str_contains($availabilitySource, 'workingHoursMonthPreview')
    || !str_contains($availabilitySource, 'monthlySchedules')
    || !str_contains($availabilityActionSource, "save_month_schedule")
    || !str_contains($availabilityActionSource, 'appointment_save_monthly_schedule')) {
    throw new RuntimeException('Future-month working-hours controls are not connected to appointment scheduling.');
}

[$start, $end] = appointment_week_bounds(new DateTimeImmutable('2026-09-16'));
if ($start !== '2026-09-14' || $end !== '2026-09-20') {
    throw new RuntimeException('The staff calendar must include all seven days.');
}

$invalid = $submitted;
$invalid[2]['end'] = '08:00';
try {
    appointment_normalize_weekly_schedule($invalid);
    throw new RuntimeException('Invalid working hours were accepted.');
} catch (InvalidArgumentException $expected) {
    // Expected.
}

echo "Appointment schedule checks passed. No database writes.\n";
