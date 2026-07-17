<?php

require_once __DIR__ . '/SystemSettings.php';

function ensure_appointment_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'status'");
    $statusColumn = $stmt->fetch();
    $type = $statusColumn['Type'] ?? '';

    if (str_starts_with(strtolower((string) $type), 'enum(') && !str_contains($type, "'Pending'")) {
        $db->exec("ALTER TABLE appointments MODIFY status ENUM('Pending', 'Scheduled', 'Completed', 'Cancelled', 'No Show') NOT NULL DEFAULT 'Pending'");
    }

    $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'cancellation_reason'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE appointments ADD cancellation_reason TEXT NULL AFTER notes");
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS appointment_availability_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            block_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            reason VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_appointment_blocks_date (block_date),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");

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
    $stmt = db()->prepare("
        SELECT b.*, u.name AS created_by_name
        FROM appointment_availability_blocks b
        LEFT JOIN users u ON u.id = b.created_by
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
    $stmt = db()->prepare("
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
