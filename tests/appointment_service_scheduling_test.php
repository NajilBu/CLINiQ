<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/AppointmentWorkflow.php';

function appointment_service_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

appointment_service_expect(appointment_closure_scopes() === ['Both', 'Medical Consult', 'Dental'], 'Closure scopes must cover both services and each individual service.');
appointment_service_expect(appointment_block_applies_to(['applies_to' => 'Both'], 'Medical Consult'), 'A both-service closure must block Medical Consult.');
appointment_service_expect(appointment_block_applies_to(['applies_to' => 'Dental'], 'Dental'), 'A Dental closure must block Dental.');
appointment_service_expect(!appointment_block_applies_to(['applies_to' => 'Dental'], 'Medical Consult'), 'A Dental closure must not block Medical Consult.');

$workflow = file_get_contents(dirname(__DIR__) . '/app/services/AppointmentWorkflow.php');
$portal = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-appointment.php');
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260930_scope_appointment_availability_blocks.sql');
appointment_service_expect($workflow !== false && str_contains($workflow, 'function appointment_patient_has_overlap') && str_contains($workflow, 'AND purpose = ?') && str_contains($workflow, "['services'][\$purpose]['days'] ?? \$legacy"), 'Booking rules must reserve capacity per service, protect the student from overlaps, and preserve legacy schedules until each service is saved.');
appointment_service_expect($portal !== false && str_contains($portal, 'appointment_patient_has_overlap($patientId, $datetimeStr)') && str_contains($portal, 'serviceSchedule') && str_contains($portal, 'blockedTimesByPurpose'), 'The patient portal must use service-specific availability and overlap checks.');
appointment_service_expect($portal !== false
    && !str_contains($portal, '$unavailableTimes')
    && !str_contains($portal, '$allTimesUnavailable')
    && !str_contains($portal, 'let weeklySchedule')
    && str_contains($portal, "const purpose = document.getElementById('appt-type')?.value || '';\n        const blockedTimes")
    && str_contains($portal, "patientTimes.includes(time)\n                || apeTimes.includes(time)"), 'The patient calendar must not retain shared-schedule state or offer a conflicting service after a time is selected.');
appointment_service_expect($portal !== false && str_contains($portal, 'Showing ${purpose} availability only') && str_contains($portal, 'student-date-state') && str_contains($portal, 'openSlotCount < serviceSlots.length'), 'The patient calendar must identify the selected service and visibly mark its limited or unavailable dates.');
appointment_service_expect($portal !== false && str_contains($portal, 'id="appointment-submit" disabled') && str_contains($portal, 'function syncAppointmentSubmit()') && str_contains($portal, '!(purposeSelect?.value && dateInput.value && timeInput.value)'), 'The patient submit button must remain unavailable until purpose, date, and time are selected.');
$staffUpdate = file_get_contents(dirname(__DIR__) . '/public/appointments/update.php');
appointment_service_expect($staffUpdate !== false && str_contains($staffUpdate, "appointment_slot_is_open(\$appointmentDate, substr(\$appointmentDatetime, 11, 8), (string) \$appointment['purpose'])") && str_contains($staffUpdate, "appointment_time_is_blocked(\$appointmentDate, substr(\$appointmentDatetime, 11, 8), \$blocks, (string) \$appointment['purpose'])"), 'Staff approval must validate the same service-specific schedule and closure scope as patient booking.');
$availabilityPage = file_get_contents(dirname(__DIR__) . '/public/appointments/_availability_section.php');
appointment_service_expect($availabilityPage !== false && str_contains($availabilityPage, 'Choose a service to manage its hours and unavailable appointment periods.') && str_contains($availabilityPage, 'Block applies to') && str_contains($availabilityPage, 'Both services:</strong> this prevents Medical Consult and Dental appointments'), 'Availability management must clearly distinguish a service schedule from a block that affects both services.');
$availabilityCss = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
appointment_service_expect($availabilityCss !== false && str_contains($availabilityCss, '.appointment-week-day-column.is-past') && str_contains($availabilityCss, 'repeating-linear-gradient(-45deg, #f8fafc, #f8fafc 8px, #f1f5f9 8px, #f1f5f9 16px)'), 'Closed calendar days must use the unavailable hatch even when they contain no hourly slots.');
appointment_service_expect($availabilityPage !== false && str_contains($availabilityPage, '$unavailableRanges = empty($dayHours[\'enabled\'])') && str_contains($availabilityPage, 'appointment-week-unavailable-area') && $availabilityCss !== false && str_contains($availabilityCss, '.appointment-week-unavailable-area'), 'Calendar columns must visibly hatch every hour outside the selected service schedule.');
appointment_service_expect($availabilityPage !== false && str_contains($availabilityPage, '$availabilityWeekDays[] = $day;') && str_contains($availabilityPage, '$availabilityOpenWeekDays[] = $day;') && str_contains($availabilityPage, '($availabilityOpenWeekDays[0] ?? $availabilityWeek)'), 'The weekly calendar must render all seven days while choosing default block dates only from open days.');
appointment_service_expect($availabilityPage !== false && str_contains($availabilityPage, 'grid grid-cols-2 gap-2 px-5 pt-4 sm:px-6') && str_contains($availabilityPage, 'btn btn-sm w-full justify-center'), 'Medical Consult and Dental selectors must each occupy half of the full available row.');
appointment_service_expect($migration !== false && str_contains($migration, "applies_to VARCHAR(32) NOT NULL DEFAULT 'Both'"), 'The availability migration must preserve legacy closures as both-service closures.');

echo "Appointment service scheduling checks passed. No database writes.\n";
