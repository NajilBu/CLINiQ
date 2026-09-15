<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/ape/scheduling.php');
$service = file_get_contents(__DIR__ . '/../app/services/ApeCycleService.php');
$appScript = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$appStyles = file_get_contents(__DIR__ . '/../public/assets/css/app.css');

function check_time(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

check_time(!str_contains($source, '$apeBatchTimeOptions'), 'Minute-by-minute time options must be removed.');
check_time(!str_contains($source, '<select class="clinic-select" id="apeBatchStart"'), 'Start time must not be a select.');
check_time(!str_contains($source, '<select class="clinic-select" id="apeBatchEnd"'), 'End time must not be a select.');
check_time((bool) preg_match('/<input[^>]+id="apeBatchStart"[^>]+type="text"[^>]+placeholder="8:00 AM"[^>]+required>/', $source), 'Start uses a required AM/PM text field.');
check_time((bool) preg_match('/<input[^>]+id="apeBatchEnd"[^>]+type="text"[^>]+placeholder="5:00 PM"[^>]+required>/', $source), 'End uses a required AM/PM text field.');
check_time(str_contains($source, 'parseBatchTime'), 'Client-side AM/PM parsing remains available.');
check_time(str_contains($source, 'startMinutes >= endMinutes'), 'Client-side ordering validation remains available.');
check_time(str_contains($source, 'appointmentConflicts'), 'Client-side appointment conflict validation remains available.');
check_time(str_contains($source, 'unavailableBlocks'), 'Client-side unavailable-time validation remains available.');
check_time(str_contains($source, "confirmAction('Create this APE batch?'"), 'Batch creation uses the custom confirmation modal.');
check_time(str_contains($service, 'The APE batch date cannot be in the past.'), 'Server-side past-date validation is required.');
check_time(str_contains($service, 'LOWER(batch_name) = LOWER(?)'), 'Server-side duplicate-name validation is required.');
check_time(str_contains($service, 'appointment_availability_blocks'), 'Server-side unavailable-time validation is required.');
check_time(str_contains($service, "status IN ('Pending', 'Scheduled')"), 'Server-side appointment conflict validation is required.');
check_time(str_contains($appScript, 'modalContent.scrollTop = 0'), 'Opening a modal resets its internal scroll position.');
check_time(str_contains($appStyles, '#apeBatchModal .modal-content'), 'APE modal has viewport-specific height protection.');

$start = strpos($service, 'function normalize_ape_batch_time(');
$end = strpos($service, 'function ape_schedule_batches(', $start);
eval(substr($service, $start, $end - $start));

$accepted = [
    '8:00 AM' => '08:00:00',
    '08:15 am' => '08:15:00',
    '12:00 PM' => '12:00:00',
    '1:30 PM' => '13:30:00',
    '5:00 PM' => '17:00:00',
    '800am' => '08:00:00',
    '8am' => '08:00:00',
    '530pm' => '17:30:00',
    '08:00' => '08:00:00',
    '17:00' => '17:00:00',
];
foreach ($accepted as $input => $expected) {
    check_time(normalize_ape_batch_time($input, 'time') === $expected, "Accepted time failed: {$input}");
}

foreach (['', '800', '25:00', '1360pm', '12:60 PM'] as $input) {
    $rejected = false;
    try {
        normalize_ape_batch_time($input, 'time');
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    check_time($rejected, "Invalid time value was accepted: {$input}");
}

$schedule = array_fill(1, 7, ['enabled' => false, 'start' => '08:00', 'end' => '17:00']);
$schedule[1] = ['enabled' => true, 'start' => '08:00', 'end' => '17:00'];
check_time(
    ape_validate_batch_working_hours('2026-09-14', '08:00:00', '17:00:00', $schedule)['enabled'],
    'A batch within the selected day working hours was rejected.'
);
foreach ([
    ['2026-09-15', '08:00:00', '09:00:00'],
    ['2026-09-14', '07:59:00', '09:00:00'],
    ['2026-09-14', '16:00:00', '17:01:00'],
] as [$date, $startTime, $endTime]) {
    $rejected = false;
    try {
        ape_validate_batch_working_hours($date, $startTime, $endTime, $schedule);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    check_time($rejected, "Closed-day or outside-hours batch was accepted: {$date} {$startTime}-{$endTime}");
}

echo "PASS: flexible time input, working-day validation, conflict checks, confirmation, and modal scroll reset. No database writes.\n";
