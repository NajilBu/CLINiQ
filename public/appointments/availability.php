<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

$user = current_user();
$week = appointment_week_from_request($_POST['week'] ?? $_GET['week'] ?? null);
$weekParam = $week->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?week=' . urlencode($weekParam) . '#clinic-availability');
    exit;
}

$action = $_POST['action'] ?? 'add';

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = appointment_db()->prepare('DELETE FROM appointment_availability_blocks WHERE availability_block_id = ?');
        $stmt->execute([$id]);
        flash_message('success', 'Availability block removed.');
    }
} else {
    $submittedSlots = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string) $value),
        (array) ($_POST['block_slots'] ?? [])
    ))));
    $submittedDates = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string) $value),
        (array) ($_POST['block_dates'] ?? [])
    ))));
    $fallbackDate = trim((string) ($_POST['block_date'] ?? ''));
    if (!$submittedDates && $fallbackDate !== '') {
        $submittedDates[] = $fallbackDate;
    }
    $allDay = isset($_POST['all_day']);
    $start = trim($_POST['start_time'] ?? '');
    $end = trim($_POST['end_time'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $validDates = [];
    $validSlots = [];
    $today = new DateTimeImmutable('today');
    $minimumDate = (int) $today->format('N') >= 6
        ? $today->modify('next monday')
        : $today;

    $validateDate = static function (string $date) use ($minimumDate): ?string {
        $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$selectedDate || $selectedDate->format('Y-m-d') !== $date) {
            return 'Choose valid dates to block.';
        }
        if ($selectedDate < $minimumDate) {
            return 'Past dates can no longer be marked unavailable.';
        }
        if (!appointment_date_is_clinic_day($date)) {
            return 'Clinic availability can only be managed from Monday through Friday.';
        }
        return null;
    };

    if (!$allDay && $submittedSlots) {
        foreach ($submittedSlots as $slot) {
            $parts = explode('|', $slot);
            if (count($parts) !== 3) {
                $validSlots = [];
                flash_message('error', 'Choose valid time slots to block.');
                break;
            }
            [$slotDate, $slotStart, $slotEnd] = array_map('trim', $parts);
            $dateError = $validateDate($slotDate);
            if ($dateError !== null) {
                $validSlots = [];
                flash_message('error', $dateError);
                break;
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $slotStart) || !preg_match('/^\d{2}:\d{2}$/', $slotEnd) || $slotStart >= $slotEnd || $slotStart < '08:00' || $slotEnd > '17:00') {
                $validSlots = [];
                flash_message('error', 'Choose valid time slots between 8:00 AM and 5:00 PM.');
                break;
            }
            $validSlots[] = [$slotDate, $slotStart, $slotEnd];
        }
    }

    foreach ($submittedDates as $date) {
        $dateError = $validateDate($date);
        if ($dateError !== null) {
            $validDates = [];
            flash_message('error', $dateError);
            break;
        }
        $validDates[] = $date;
    }

    if (!$allDay && $submittedSlots && !$validSlots) {
        // Validation message is already set above.
    } elseif (!$allDay && $validSlots) {
        $stmt = appointment_db()->prepare("
            INSERT INTO appointment_availability_blocks (block_date, start_time, end_time, reason, created_by_person_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($validSlots as [$slotDate, $slotStart, $slotEnd]) {
            $stmt->execute([
                $slotDate,
                $slotStart,
                $slotEnd,
                $reason !== '' ? $reason : null,
                (int) ($user['person_id'] ?? 0) ?: null,
            ]);
        }
        flash_message('success', 'Hourly unavailable block added for ' . count($validSlots) . ' time slot(s).');
    } elseif (!$validDates) {
        flash_message('error', 'Choose at least one date to block.');
    } elseif (!$allDay && ($start === '' || $end === '' || $start >= $end || $start < '08:00' || $end > '17:00')) {
        flash_message('error', 'Choose a valid start and end time between 8:00 AM and 5:00 PM.');
    } else {
        $stmt = appointment_db()->prepare("
            INSERT INTO appointment_availability_blocks (block_date, start_time, end_time, reason, created_by_person_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($validDates as $date) {
            $stmt->execute([
                $date,
                $allDay ? null : $start,
                $allDay ? null : $end,
                $reason !== '' ? $reason : null,
                (int) ($user['person_id'] ?? 0) ?: null,
            ]);
        }
        $dateCount = count($validDates);
        flash_message('success', ($allDay ? 'Full-day' : 'Hourly') . ' unavailable block added for ' . $dateCount . ' day(s).');
    }
}

header('Location: index.php?week=' . urlencode($weekParam) . '#clinic-availability');
exit;
