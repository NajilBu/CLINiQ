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
    $date = trim($_POST['block_date'] ?? '');
    $allDay = isset($_POST['all_day']);
    $start = trim($_POST['start_time'] ?? '');
    $end = trim($_POST['end_time'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$selectedDate || $selectedDate->format('Y-m-d') !== $date) {
        flash_message('error', 'Choose a valid date to block.');
    } elseif ($selectedDate->format('N') === '7') {
        flash_message('error', 'Clinic availability can only be managed from Monday through Saturday.');
    } elseif (!$allDay && ($start === '' || $end === '' || $start >= $end || $start < '08:00' || $end > '17:00')) {
        flash_message('error', 'Choose a valid start and end time between 8:00 AM and 5:00 PM.');
    } else {
        $stmt = appointment_db()->prepare("
            INSERT INTO appointment_availability_blocks (block_date, start_time, end_time, reason, created_by_person_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $date,
            $allDay ? null : $start,
            $allDay ? null : $end,
            $reason !== '' ? $reason : null,
            (int) ($user['person_id'] ?? 0) ?: null,
        ]);
        flash_message('success', $allDay ? 'Full-day unavailable block added.' : 'Hourly unavailable block added.');
        $weekParam = $selectedDate->modify('monday this week')->format('Y-m-d');
    }
}

header('Location: index.php?week=' . urlencode($weekParam) . '#clinic-availability');
exit;
