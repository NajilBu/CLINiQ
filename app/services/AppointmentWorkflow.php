<?php

require_once __DIR__ . '/SystemSettings.php';

function ensure_appointment_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = appointment_db();
    foreach (['appointments', 'appointment_availability_blocks'] as $table) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Required Cliniq_db table {$table} is missing. Run the appointment migration first.");
        }
    }

    $ready = true;
}

function appointment_status_badge_class(string $status): string
{
    return match ($status) {
        'Pending' => 'badge-pending',
        'Scheduled' => 'badge-in-progress',
        'Completed' => 'badge-completed',
        'Cancelled', 'No Show' => 'badge-cancelled',
        default => 'badge-pending',
    };
}

function appointment_month_from_request(?string $value = null): DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        $month = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
        if ($month && $month->format('Y-m') === $value) {
            return $month;
        }
    }

    return new DateTimeImmutable(date('Y-m-01'));
}

function appointment_month_bounds(DateTimeImmutable $month): array
{
    $start = $month->modify('first day of this month')->setTime(0, 0);
    $end = $month->modify('last day of this month')->setTime(23, 59, 59);

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function appointment_blocks_for_month(DateTimeImmutable $month): array
{
    [$start, $end] = appointment_month_bounds($month);
    $stmt = appointment_db()->prepare("
        SELECT b.*, TRIM(CONCAT_WS(' ', creator.first_name, creator.middle_name, creator.last_name)) AS created_by_name
        FROM appointment_availability_blocks b
        LEFT JOIN people creator ON creator.id = b.created_by_person_id
        WHERE b.block_date BETWEEN ? AND ?
        ORDER BY b.block_date ASC, b.start_time IS NULL DESC, b.start_time ASC
    ");
    $stmt->execute([$start, $end]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $block) {
        $byDate[$block['block_date']][] = $block;
    }

    return $byDate;
}

function appointment_week_from_request(?string $value = null): DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date && $date->format('Y-m-d') === $value) {
            return $date->modify('monday this week')->setTime(0, 0);
        }
    }

    return (new DateTimeImmutable('today'))->modify('monday this week')->setTime(0, 0);
}

function appointment_week_bounds(DateTimeImmutable $week): array
{
    $start = $week->modify('monday this week')->setTime(0, 0);
    $end = $start->modify('+5 days')->setTime(23, 59, 59);

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function appointment_blocks_for_week(DateTimeImmutable $week): array
{
    [$start, $end] = appointment_week_bounds($week);
    $stmt = appointment_db()->prepare("
        SELECT b.*, TRIM(CONCAT_WS(' ', creator.first_name, creator.middle_name, creator.last_name)) AS created_by_name
        FROM appointment_availability_blocks b
        LEFT JOIN people creator ON creator.id = b.created_by_person_id
        WHERE b.block_date BETWEEN ? AND ?
        ORDER BY b.block_date ASC, b.start_time IS NULL DESC, b.start_time ASC
    ");
    $stmt->execute([$start, $end]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $block) {
        $byDate[$block['block_date']][] = $block;
    }

    return $byDate;
}

function appointment_patient_dates_for_month(int $patientId, DateTimeImmutable $month): array
{
    [$start, $end] = appointment_month_bounds($month);
    $stmt = appointment_db()->prepare("
        SELECT DATE(appointment_datetime) AS appointment_date, status, COUNT(*) AS total
        FROM appointments
        WHERE patient_id = ?
          AND appointment_datetime BETWEEN ? AND ?
          AND status IN ('Pending', 'Scheduled')
        GROUP BY DATE(appointment_datetime), status
    ");
    $stmt->execute([$patientId, $start . ' 00:00:00', $end . ' 23:59:59']);

    $dates = [];
    foreach ($stmt->fetchAll() as $row) {
        $dates[$row['appointment_date']][] = $row;
    }

    return $dates;
}

function appointment_is_full_day_blocked(array $blocks): bool
{
    foreach ($blocks as $block) {
        if (empty($block['start_time']) || empty($block['end_time'])) {
            return true;
        }
    }

    return false;
}

function appointment_time_is_blocked(string $date, string $time, array $blocksByDate): bool
{
    $blocks = $blocksByDate[$date] ?? [];
    if (appointment_is_full_day_blocked($blocks)) {
        return true;
    }

    $slot = strtotime($date . ' ' . $time);
    foreach ($blocks as $block) {
        if (empty($block['start_time']) || empty($block['end_time'])) {
            return true;
        }

        $start = strtotime($date . ' ' . $block['start_time']);
        $end = strtotime($date . ' ' . $block['end_time']);
        if ($slot >= $start && $slot < $end) {
            return true;
        }
    }

    return false;
}

function appointment_format_block_time(array $block): string
{
    if (empty($block['start_time']) || empty($block['end_time'])) {
        return 'Whole day';
    }

    return date('g:i A', strtotime($block['start_time'])) . ' - ' . date('g:i A', strtotime($block['end_time']));
}
