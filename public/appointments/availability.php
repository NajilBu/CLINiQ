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

$assertScheduleHasNoConflicts = static function (array $candidate, ?string $month = null): void {
    $params = [];
    $configuredMonths = appointment_monthly_schedules();
    $appointmentWhere = "appointment_datetime >= NOW() AND status IN ('Pending', 'Scheduled')";
    $batchWhere = "schedule_date >= CURDATE() AND status = 'Scheduled'";
    if ($month !== null) {
        $monthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        $monthStart = $monthDate->format('Y-m-01');
        $monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');
        $appointmentWhere .= ' AND DATE(appointment_datetime) BETWEEN ? AND ?';
        $batchWhere .= ' AND schedule_date BETWEEN ? AND ?';
        $params = [$monthStart, $monthEnd];
    }

    $appointments = appointment_db()->prepare("SELECT appointment_datetime FROM appointments WHERE {$appointmentWhere}");
    $appointments->execute($params);
    foreach ($appointments->fetchAll(PDO::FETCH_COLUMN) as $datetime) {
        $date = substr((string) $datetime, 0, 10);
        $time = substr((string) $datetime, 11, 8);
        $effectiveCandidate = $month === null && isset($configuredMonths[substr($date, 0, 7)])
            ? $configuredMonths[substr($date, 0, 7)]['days']
            : $candidate;
        if (!appointment_slot_is_open_for_schedule($effectiveCandidate, $date, $time)) {
            throw new InvalidArgumentException('An upcoming appointment falls outside those hours. Resolve or reschedule it before changing this schedule.');
        }
    }

    $batches = appointment_db()->prepare("SELECT schedule_date, start_time, end_time FROM ape_schedule_batches WHERE {$batchWhere}");
    $batches->execute($params);
    foreach ($batches->fetchAll() as $batch) {
        $date = (string) $batch['schedule_date'];
        $effectiveCandidate = $month === null && isset($configuredMonths[substr($date, 0, 7)])
            ? $configuredMonths[substr($date, 0, 7)]['days']
            : $candidate;
        if (!appointment_range_is_open_for_schedule($effectiveCandidate, $date, (string) $batch['start_time'], (string) $batch['end_time'])) {
            throw new InvalidArgumentException('A scheduled APE batch falls outside those hours. Reschedule or cancel it before changing this schedule.');
        }
    }
};

if ($action === 'save_schedule') {
    try {
        $candidate = appointment_schedule_from_form($_POST);
        $assertScheduleHasNoConflicts($candidate);
        appointment_save_weekly_schedule($candidate, (int) ($user['person_id'] ?? 0) ?: null);
        flash_message('success', 'Working days and hours saved. New appointment requests will follow this schedule.');
    } catch (InvalidArgumentException $exception) {
        flash_message('error', $exception->getMessage());
    }
} elseif ($action === 'save_month_schedule') {
    try {
        $month = appointment_normalize_month_key((string) ($_POST['schedule_month'] ?? ''), true);
        $candidate = appointment_schedule_from_form($_POST);
        $assertScheduleHasNoConflicts($candidate, $month);
        appointment_save_monthly_schedule($month, $candidate, (int) ($user['person_id'] ?? 0) ?: null);
        flash_message('success', date('F Y', strtotime($month . '-01')) . ' working days and hours saved.');
    } catch (InvalidArgumentException $exception) {
        flash_message('error', $exception->getMessage());
    }
} elseif ($action === 'delete_month_schedule') {
    try {
        $month = appointment_normalize_month_key((string) ($_POST['schedule_month'] ?? ''), true);
        $regularSchedule = appointment_weekly_schedule();
        $assertScheduleHasNoConflicts($regularSchedule, $month);
        appointment_delete_monthly_schedule($month, (int) ($user['person_id'] ?? 0) ?: null);
        flash_message('success', date('F Y', strtotime($month . '-01')) . ' will use the regular weekly schedule.');
    } catch (InvalidArgumentException $exception) {
        flash_message('error', $exception->getMessage());
    }
} elseif ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = appointment_db()->prepare('DELETE FROM appointment_availability_blocks WHERE availability_block_id = ?');
        $stmt->execute([$id]);
        flash_message('success', 'Availability block removed.');
    }
} elseif ($action === 'delete_group') {
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        appointment_db()->prepare("DELETE FROM appointment_availability_blocks WHERE availability_block_id IN ($placeholders)")->execute($ids);
        flash_message('success', 'Unavailable block removed.');
    }
} elseif ($action === 'update') {
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $date = trim((string) ($_POST['block_date'] ?? ''));
    $allDay = false;
    $start = trim((string) ($_POST['start_time'] ?? ''));
    $end = trim((string) ($_POST['end_time'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $dateError = (function () use ($date) {
        $selected = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$selected || $selected->format('Y-m-d') !== $date) return 'Choose a valid date.';
        if (!appointment_date_is_clinic_day($date)) return 'Choose a day when the clinic is open.';
        return null;
    })();
    $originalStart = trim((string) ($_POST['original_start'] ?? ''));
    $originalEnd = trim((string) ($_POST['original_end'] ?? ''));
    $hours = appointment_schedule_for_date($date)[(int) (DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable('today'))->format('N')];
    $rangeError = !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end || $start < $hours['start'] || $end > $hours['end'] || ($originalStart !== '' && $start < $originalStart) || ($originalEnd !== '' && $end > $originalEnd);
    if (!$ids || $dateError || $rangeError || $reason === '') {
        flash_message('error', $dateError ?: ($reason === '' ? 'Enter a reason for the unavailable time.' : 'Choose a time within the clinic working hours for that day.'));
    } else {
        $db = appointment_db();
        $db->beginTransaction();
        $db->prepare('UPDATE appointment_availability_blocks SET block_date = ?, start_time = ?, end_time = ?, reason = ? WHERE availability_block_id = ?')->execute([$date, $allDay ? null : $start, $allDay ? null : $end, $reason !== '' ? $reason : null, $ids[0]]);
        if (count($ids) > 1) {
            $placeholders = implode(',', array_fill(0, count($ids) - 1, '?'));
            $db->prepare("DELETE FROM appointment_availability_blocks WHERE availability_block_id IN ($placeholders)")->execute(array_slice($ids, 1));
        }
        $db->commit();
        flash_message('success', 'Unavailable block updated.');
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
    $minimumDate = $today;

    $validateDate = static function (string $date) use ($minimumDate): ?string {
        $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$selectedDate || $selectedDate->format('Y-m-d') !== $date) {
            return 'Choose valid dates to block.';
        }
        if ($selectedDate < $minimumDate) {
            return 'Past dates can no longer be marked unavailable.';
        }
        if (!appointment_date_is_clinic_day($date)) {
            return 'Choose a day when the clinic is open.';
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
            $hours = appointment_schedule_for_date($slotDate)[(int) (new DateTimeImmutable($slotDate))->format('N')];
            if (!preg_match('/^\d{2}:\d{2}$/', $slotStart) || !preg_match('/^\d{2}:\d{2}$/', $slotEnd) || $slotStart >= $slotEnd || $slotStart < $hours['start'] || $slotEnd > $hours['end']) {
                $validSlots = [];
                flash_message('error', 'Choose time slots within clinic working hours.');
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
    } elseif (!$allDay && $validSlots && $reason === '') {
        flash_message('error', 'Enter a reason for the unavailable time.');
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
    } elseif (!$allDay && ($start === '' || $end === '' || $start >= $end || count(array_filter($validDates, static function (string $date) use ($start, $end): bool {
        $hours = appointment_schedule_for_date($date)[(int) (new DateTimeImmutable($date))->format('N')];
        return $start < $hours['start'] || $end > $hours['end'];
    })) > 0)) {
        flash_message('error', 'Choose start and end times within clinic working hours.');
    } elseif ($reason === '') {
        flash_message('error', 'Enter a reason for the unavailable time.');
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
