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

function appointment_actionable_statuses(): array
{
    return ['Pending', 'For Confirmation'];
}

function appointment_status_badge_class(string $status): string
{
    return match ($status) {
        'Pending' => 'badge-pending',
        'Scheduled' => 'badge-in-progress',
        'For Confirmation' => 'badge-pending',
        'Completed' => 'badge-completed',
        'Cancelled', 'No Show' => 'badge-cancelled',
        default => 'badge-pending',
    };
}

function appointment_duration_minutes(): int
{
    return 60;
}

/**
 * Combine touching or overlapping unavailable periods when their reasons match.
 * Each item must contain normalized _start_minute and _end_minute values.
 */
function appointment_merge_continuous_unavailable_blocks(array $blocks): array
{
    usort($blocks, static function (array $left, array $right): int {
        return [(int) ($left['_start_minute'] ?? 0), (int) ($left['_end_minute'] ?? 0)]
            <=> [(int) ($right['_start_minute'] ?? 0), (int) ($right['_end_minute'] ?? 0)];
    });

    $merged = [];
    foreach ($blocks as $block) {
        $start = (int) ($block['_start_minute'] ?? 0);
        $end = (int) ($block['_end_minute'] ?? 0);
        if ($end <= $start) {
            continue;
        }

        $reason = trim((string) ($block['reason'] ?? '')) ?: 'Clinic unavailable';
        $block['reason'] = $reason;
        $lastIndex = count($merged) - 1;
        if ($lastIndex >= 0) {
            $lastReason = trim((string) ($merged[$lastIndex]['reason'] ?? '')) ?: 'Clinic unavailable';
            $isContinuous = $start <= (int) $merged[$lastIndex]['_end_minute'];
            if ($isContinuous && strcasecmp($lastReason, $reason) === 0) {
                if ($end > (int) $merged[$lastIndex]['_end_minute']) {
                    $merged[$lastIndex]['_end_minute'] = $end;
                    $merged[$lastIndex]['end_time'] = $block['end_time'] ?? $merged[$lastIndex]['end_time'];
                }
                $merged[$lastIndex]['_is_full_day'] = !empty($merged[$lastIndex]['_is_full_day']) || !empty($block['_is_full_day']);
                continue;
            }
        }
        $merged[] = $block;
    }

    return $merged;
}

function appointment_confirmation_cutoff_sql(): string
{
    return 'DATE_ADD(appointment_datetime, INTERVAL ' . appointment_duration_minutes() . ' MINUTE)';
}

function appointment_sync_overdue_confirmations(): int
{
    $stmt = appointment_db()->prepare("
        UPDATE appointments
        SET status = 'For Confirmation'
        WHERE status = 'Scheduled'
          AND " . appointment_confirmation_cutoff_sql() . " <= NOW()
    ");
    $stmt->execute();

    return $stmt->rowCount();
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
    $end = $start->modify('+6 days')->setTime(23, 59, 59);

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function appointment_date_is_clinic_day(string $date): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $date
        && appointment_schedule_for_date($date)[(int) $parsedDate->format('N')]['enabled'];
}

function appointment_weekly_schedule(): array
{
    static $schedule = null;
    if ($schedule !== null) {
        return $schedule;
    }
    $defaults = [];
    for ($day = 1; $day <= 7; $day++) {
        $defaults[$day] = ['enabled' => $day <= 5, 'start' => '08:00', 'end' => '17:00'];
    }
    $stored = cliniq_setting_read('appointment_weekly_schedule', []);
    foreach ($defaults as $day => $default) {
        $saved = $stored['days'][$day] ?? [];
        if (!is_array($saved)) {
            continue;
        }
        $defaults[$day] = [
            'enabled' => (bool) ($saved['enabled'] ?? $default['enabled']),
            'start' => (string) ($saved['start'] ?? $default['start']),
            'end' => (string) ($saved['end'] ?? $default['end']),
        ];
    }
    return $schedule = $defaults;
}

function appointment_normalize_weekly_schedule(array $submitted): array
{
    $days = [];
    for ($day = 1; $day <= 7; $day++) {
        $row = $submitted[$day] ?? [];
        $start = trim((string) ($row['start'] ?? ''));
        $end = trim((string) ($row['end'] ?? ''));
        if (!preg_match('/^(0[7-9]|1[0-9]|20):00$/', $start)
            || !preg_match('/^(0[8-9]|1[0-9]|2[01]):00$/', $end)
            || $start >= $end) {
            throw new InvalidArgumentException('Choose whole-hour opening and closing times between 7:00 AM and 9:00 PM.');
        }
        $days[$day] = ['enabled' => !empty($row['enabled']), 'start' => $start, 'end' => $end];
    }
    if (!array_filter($days, static fn(array $row): bool => $row['enabled'])) {
        throw new InvalidArgumentException('Keep at least one working day open.');
    }
    return $days;
}

function appointment_schedule_from_form(array $form): array
{
    $baseStart = trim((string) ($form['base_start'] ?? ''));
    $baseEnd = trim((string) ($form['base_end'] ?? ''));
    $openDays = array_map('strval', (array) ($form['open_days'] ?? []));
    $days = [];
    for ($day = 1; $day <= 7; $day++) {
        $days[$day] = [
            'enabled' => in_array((string) $day, $openDays, true),
            'start' => $baseStart,
            'end' => $baseEnd,
        ];
    }
    $usedOverrides = [];
    foreach ((array) ($form['overrides'] ?? []) as $override) {
        if (!is_array($override)) {
            throw new InvalidArgumentException('Choose a valid day for each custom time range.');
        }
        $day = (int) ($override['day'] ?? 0);
        if ($day < 1 || $day > 7 || !$days[$day]['enabled'] || isset($usedOverrides[$day])) {
            throw new InvalidArgumentException('Each custom time range needs a different open day.');
        }
        $usedOverrides[$day] = true;
        $days[$day]['start'] = trim((string) ($override['start'] ?? ''));
        $days[$day]['end'] = trim((string) ($override['end'] ?? ''));
    }
    return appointment_normalize_weekly_schedule($days);
}

function appointment_save_weekly_schedule(array $submitted, ?int $updatedBy): void
{
    $days = appointment_normalize_weekly_schedule($submitted);
    cliniq_setting_write('appointment_weekly_schedule', ['days' => $days], $updatedBy);
}

function appointment_normalize_month_key(string $month, bool $futureOnly = false): string
{
    $month = trim($month);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || !$parsed || $parsed->format('Y-m') !== $month) {
        throw new InvalidArgumentException('Choose a valid month.');
    }
    if ($futureOnly && $month <= date('Y-m')) {
        throw new InvalidArgumentException('Choose a future month.');
    }
    return $month;
}

/** @return array<string,array{days:array<int,array{enabled:bool,start:string,end:string}>}> */
function appointment_monthly_schedules(): array
{
    static $months = null;
    if ($months !== null) {
        return $months;
    }
    $stored = cliniq_setting_read('appointment_monthly_schedules', ['months' => []]);
    $months = [];
    foreach ((array) ($stored['months'] ?? []) as $month => $entry) {
        try {
            $key = appointment_normalize_month_key((string) $month);
            $days = appointment_normalize_weekly_schedule((array) ($entry['days'] ?? []));
            $months[$key] = ['days' => $days];
        } catch (InvalidArgumentException $exception) {
            // Ignore malformed legacy settings instead of breaking appointment pages.
        }
    }
    ksort($months);
    return $months;
}

function appointment_schedule_for_month(string $month): array
{
    try {
        $month = appointment_normalize_month_key($month);
    } catch (InvalidArgumentException $exception) {
        return appointment_weekly_schedule();
    }
    return appointment_monthly_schedules()[$month]['days'] ?? appointment_weekly_schedule();
}

function appointment_schedule_for_date(string $date): array
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return appointment_weekly_schedule();
    }
    return appointment_schedule_for_month($parsed->format('Y-m'));
}

function appointment_save_monthly_schedule(string $month, array $submitted, ?int $updatedBy): void
{
    $month = appointment_normalize_month_key($month, true);
    $months = appointment_monthly_schedules();
    $months[$month] = ['days' => appointment_normalize_weekly_schedule($submitted)];
    ksort($months);
    cliniq_setting_write('appointment_monthly_schedules', ['months' => $months], $updatedBy);
}

function appointment_delete_monthly_schedule(string $month, ?int $updatedBy): void
{
    $month = appointment_normalize_month_key($month, true);
    $months = appointment_monthly_schedules();
    unset($months[$month]);
    cliniq_setting_write('appointment_monthly_schedules', ['months' => $months], $updatedBy);
}

function appointment_slot_is_open(string $date, string $time): bool
{
    return appointment_slot_is_open_for_schedule(appointment_schedule_for_date($date), $date, $time);
}

function appointment_slot_is_open_for_schedule(array $schedule, string $date, string $time): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date
        || !preg_match('/^((?:[01][0-9]|20)):00(?::00)?$/', $time, $matches)) {
        return false;
    }
    $hours = $schedule[(int) $parsedDate->format('N')] ?? null;
    if (!$hours || !$hours['enabled']) {
        return false;
    }
    $start = sprintf('%02d:00', (int) $matches[1]);
    $end = sprintf('%02d:00', (int) $matches[1] + 1);
    return $start >= $hours['start'] && $end <= $hours['end'];
}

function appointment_range_is_open_for_schedule(array $schedule, string $date, string $startTime, string $endTime): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $start = substr($startTime, 0, 5);
    $end = substr($endTime, 0, 5);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)
        || $start >= $end) {
        return false;
    }
    $hours = $schedule[(int) $parsedDate->format('N')] ?? null;
    return !empty($hours['enabled']) && $start >= $hours['start'] && $end <= $hours['end'];
}

function appointment_range_is_open(string $date, string $startTime, string $endTime): bool
{
    return appointment_range_is_open_for_schedule(
        appointment_schedule_for_date($date),
        $date,
        $startTime,
        $endTime
    );
}

function appointment_weekly_hour_bounds(array $schedule): array
{
    $starts = [];
    $ends = [];
    foreach ($schedule as $hours) {
        if (empty($hours['enabled'])) {
            continue;
        }
        $starts[] = (int) substr((string) $hours['start'], 0, 2);
        $ends[] = (int) substr((string) $hours['end'], 0, 2);
    }
    return $starts ? [min($starts), max($ends)] : [8, 17];
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

function appointment_reserved_times_for_month(DateTimeImmutable $month): array
{
    [$start, $end] = appointment_month_bounds($month);
    $stmt = appointment_db()->prepare("
        SELECT DATE_FORMAT(appointment_datetime, '%Y-%m-%d') AS appointment_date,
               TIME_FORMAT(appointment_datetime, '%H:%i:%s') AS appointment_time,
               purpose
        FROM appointments
        WHERE appointment_datetime BETWEEN ? AND ?
          AND status IN ('Pending', 'Scheduled', 'For Confirmation')
        ORDER BY appointment_datetime ASC
    ");
    $stmt->execute([$start . ' 00:00:00', $end . ' 23:59:59']);

    $timesByDate = [];
    foreach ($stmt->fetchAll() as $row) {
        $timesByDate[$row['appointment_date']][(string) $row['purpose']][] = $row['appointment_time'];
    }

    return $timesByDate;
}

function appointment_slot_is_reserved(string $appointmentDatetime, string $purpose): bool
{
    $stmt = appointment_db()->prepare("
        SELECT appointment_id
        FROM appointments
        WHERE appointment_datetime = ?
          AND purpose = ?
          AND status IN ('Pending', 'Scheduled', 'For Confirmation')
        LIMIT 1
    ");
    $stmt->execute([$appointmentDatetime, $purpose]);

    return (bool) $stmt->fetchColumn();
}

function appointment_active_conflicts_for_range(string $date, string $startTime, string $endTime): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($startTime, 0, 5));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($endTime, 0, 5));
    if (!$start || !$end || $start >= $end) {
        return [];
    }

    $stmt = appointment_db()->prepare("\n        SELECT appointment_id, patient_id, appointment_datetime, purpose, status\n        FROM appointments\n        WHERE status IN ('Pending', 'Scheduled', 'For Confirmation')\n          AND appointment_datetime < ?\n          AND DATE_ADD(appointment_datetime, INTERVAL " . appointment_duration_minutes() . " MINUTE) > ?\n        ORDER BY appointment_datetime ASC, appointment_id ASC\n    ");
    $stmt->execute([$end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);

    return $stmt->fetchAll();
}

function appointment_status_transition_is_allowed(string $currentStatus, string $nextStatus): bool
{
    return in_array($nextStatus, match ($currentStatus) {
        'Pending' => ['Scheduled', 'Cancelled'],
        'Scheduled' => ['For Confirmation', 'Cancelled'],
        'For Confirmation' => ['Completed', 'No Show'],
        default => [],
    }, true);
}

function appointment_ape_batches_for_range(string $startDate, string $endDate): array
{
    $stmt = appointment_db()->prepare("
        SELECT b.*,
               COUNT(ar.ape_id) AS assigned_count
        FROM ape_schedule_batches b
        LEFT JOIN ape_records ar ON ar.schedule_batch_id = b.batch_id
        WHERE b.status = 'Scheduled'
          AND b.schedule_date BETWEEN ? AND ?
        GROUP BY b.batch_id
        ORDER BY b.schedule_date ASC, b.start_time ASC, b.batch_id ASC
    ");
    $stmt->execute([$startDate, $endDate]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $batch) {
        $byDate[$batch['schedule_date']][] = $batch;
    }

    return $byDate;
}

function appointment_time_overlaps_ape_batches(string $date, string $time, array $batchesByDate): bool
{
    $slotStart = strtotime($date . ' ' . $time);
    if ($slotStart === false) {
        return false;
    }
    $slotEnd = $slotStart + (appointment_duration_minutes() * 60);

    foreach ($batchesByDate[$date] ?? [] as $batch) {
        $batchStart = strtotime($date . ' ' . (string) $batch['start_time']);
        $batchEnd = strtotime($date . ' ' . (string) $batch['end_time']);
        if ($batchStart !== false && $batchEnd !== false && $slotStart < $batchEnd && $slotEnd > $batchStart) {
            return true;
        }
    }

    return false;
}

function appointment_ape_batch_conflict(string $appointmentDatetime): ?array
{
    $slotStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $appointmentDatetime);
    if (!$slotStart || $slotStart->format('Y-m-d H:i:s') !== $appointmentDatetime) {
        return null;
    }
    $slotEnd = $slotStart->modify('+' . appointment_duration_minutes() . ' minutes');
    $stmt = appointment_db()->prepare("
        SELECT b.*
        FROM ape_schedule_batches b
        WHERE b.status = 'Scheduled'
          AND b.schedule_date = ?
          AND TIMESTAMP(b.schedule_date, b.start_time) < ?
          AND TIMESTAMP(b.schedule_date, b.end_time) > ?
        ORDER BY b.start_time ASC, b.batch_id ASC
        LIMIT 1
    ");
    $stmt->execute([
        $slotStart->format('Y-m-d'),
        $slotEnd->format('Y-m-d H:i:s'),
        $slotStart->format('Y-m-d H:i:s'),
    ]);

    return $stmt->fetch() ?: null;
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
    $slotStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time);
    if (!$slotStart || $slotStart->format('Y-m-d') !== $date) {
        return false;
    }

    return appointment_range_is_blocked(
        $date,
        $slotStart->format('H:i:s'),
        $slotStart->modify('+' . appointment_duration_minutes() . ' minutes')->format('H:i:s'),
        $blocksByDate
    );
}

function appointment_range_is_blocked(string $date, string $startTime, string $endTime, array $blocksByDate): bool
{
    $blocks = $blocksByDate[$date] ?? [];
    if (appointment_is_full_day_blocked($blocks)) {
        return true;
    }

    $start = strtotime($date . ' ' . $startTime);
    $end = strtotime($date . ' ' . $endTime);
    if ($start === false || $end === false || $end <= $start) {
        return false;
    }
    foreach ($blocks as $block) {
        if (empty($block['start_time']) || empty($block['end_time'])) {
            return true;
        }

        $blockStart = strtotime($date . ' ' . $block['start_time']);
        $blockEnd = strtotime($date . ' ' . $block['end_time']);
        if ($blockStart !== false && $blockEnd !== false && $start < $blockEnd && $end > $blockStart) {
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
