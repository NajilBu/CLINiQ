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

function appointment_status_display_label(string $status): string
{
    return $status === 'For Confirmation' ? 'For Completion' : $status;
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

function appointment_consult_purposes(): array
{
    return ['Medical Consult', 'Dental'];
}

function appointment_closure_scopes(): array
{
    return ['Both', ...appointment_consult_purposes()];
}

function appointment_normalize_closure_scope(string $scope): string
{
    return in_array($scope, appointment_closure_scopes(), true) ? $scope : 'Both';
}

function appointment_block_applies_to(array $block, string $purpose): bool
{
    return appointment_normalize_closure_scope((string) ($block['applies_to'] ?? 'Both')) === 'Both'
        || (string) ($block['applies_to'] ?? '') === $purpose;
}

function appointment_active_doctors(): array
{
    return auth_db()->query("SELECT cs.person_id AS id, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS name
        FROM clinic_staff cs JOIN people p ON p.id = cs.person_id
        JOIN accounts a ON a.person_id = cs.person_id
        WHERE cs.staff_role = 'doctor' AND a.account_status = 'active'
        ORDER BY p.last_name, p.first_name, p.id_number")->fetchAll();
}

function appointment_doctor_schedule(): array
{
    $stored = cliniq_setting_read('appointment_doctor_schedule', []);
    $schedule = [];
    foreach (appointment_consult_purposes() as $purpose) {
        $entry = (array) ($stored[$purpose] ?? []);
        $assignedDoctors = [];
        foreach (array_keys((array) ($entry['doctors'] ?? [])) as $doctorId) {
            if ((int) $doctorId <= 0) {
                continue;
            }
            $assignedDoctors[(int) $doctorId] = true;
        }
        $schedule[$purpose] = ['configured' => !empty($entry['configured']), 'doctors' => $assignedDoctors];
    }
    return $schedule;
}

function appointment_doctor_ids_for_service(array $schedule, string $purpose, array $activeDoctorIds): array
{
    $ids = [];
    foreach (array_keys((array) ($schedule[$purpose]['doctors'] ?? [])) as $doctorId) {
        if (in_array((int) $doctorId, $activeDoctorIds, true)) {
            $ids[] = (int) $doctorId;
        }
    }
    return $ids;
}

function appointment_purpose_has_doctor_on_date(string $purpose, string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return false;
    }
    $schedule = appointment_doctor_schedule();
    $activeDoctorIds = array_map(static fn (array $doctor): int => (int) $doctor['id'], appointment_active_doctors());
    return empty($schedule[$purpose]['configured']) || appointment_doctor_ids_for_service($schedule, $purpose, $activeDoctorIds) !== [];
}

function appointment_save_doctor_roles(int $doctorId, array $coverage, ?int $updatedBy, bool $remove = false): void
{
    $doctors = appointment_active_doctors();
    if (!in_array($doctorId, array_map(static fn (array $doctor): int => (int) $doctor['id'], $doctors), true)) {
        throw new InvalidArgumentException('Choose an active doctor.');
    }
    $schedule = appointment_doctor_schedule();
    foreach (appointment_consult_purposes() as $purpose) {
        $entry = (array) ($coverage[$purpose] ?? []);
        $enabled = !$remove && !empty($entry['enabled']);
        if ($enabled) {
            $schedule[$purpose]['doctors'][$doctorId] = true;
            $schedule[$purpose]['configured'] = true;
        } else {
            unset($schedule[$purpose]['doctors'][$doctorId]);
        }
        ksort($schedule[$purpose]['doctors']);
    }
    $upcoming = appointment_db()->query("SELECT appointment_datetime, purpose FROM appointments
        WHERE status IN ('Pending', 'Scheduled') AND appointment_datetime >= NOW()")->fetchAll();
    $activeDoctorIds = array_map(static fn (array $doctor): int => (int) $doctor['id'], $doctors);
    foreach ($upcoming as $appointment) {
        $purpose = (string) $appointment['purpose'];
        if (empty($schedule[$purpose]['configured'])) {
            continue;
        }
        $date = substr((string) $appointment['appointment_datetime'], 0, 10);
        if (!appointment_doctor_ids_for_service($schedule, $purpose, $activeDoctorIds)) {
            throw new InvalidArgumentException('An upcoming ' . $purpose . ' appointment on ' . $date . ' would have no assigned doctor. Assign another doctor before saving.');
        }
    }
    cliniq_setting_write('appointment_doctor_schedule', $schedule, $updatedBy);
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

function appointment_date_is_clinic_day(string $date, ?string $purpose = null): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $date
        && appointment_schedule_for_date($date, $purpose)[(int) $parsedDate->format('N')]['enabled'];
}

function appointment_weekly_schedule(?string $purpose = null): array
{
    static $schedules = [];
    $cacheKey = $purpose ?? '__legacy__';
    if (isset($schedules[$cacheKey])) {
        return $schedules[$cacheKey];
    }
    $defaults = [];
    for ($day = 1; $day <= 7; $day++) {
        $defaults[$day] = ['enabled' => $day <= 5, 'start' => '08:00', 'end' => '17:00'];
    }
    $legacy = (array) (cliniq_setting_read('appointment_weekly_schedule', [])['days'] ?? []);
    $stored = $purpose !== null
        ? (array) (cliniq_setting_read('appointment_service_weekly_schedules', [])['services'][$purpose]['days'] ?? $legacy)
        : $legacy;
    foreach ($defaults as $day => $default) {
        $saved = $stored[$day] ?? [];
        if (!is_array($saved)) {
            continue;
        }
        $defaults[$day] = [
            'enabled' => (bool) ($saved['enabled'] ?? $default['enabled']),
            'start' => (string) ($saved['start'] ?? $default['start']),
            'end' => (string) ($saved['end'] ?? $default['end']),
        ];
    }
    return $schedules[$cacheKey] = $defaults;
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

function appointment_save_weekly_schedule(array $submitted, ?int $updatedBy, ?string $purpose = null): void
{
    $days = appointment_normalize_weekly_schedule($submitted);
    if ($purpose === null) {
        cliniq_setting_write('appointment_weekly_schedule', ['days' => $days], $updatedBy);
        return;
    }
    if (!in_array($purpose, appointment_consult_purposes(), true)) {
        throw new InvalidArgumentException('Choose a valid clinic service.');
    }
    $services = (array) (cliniq_setting_read('appointment_service_weekly_schedules', [])['services'] ?? []);
    $services[$purpose] = ['days' => $days];
    cliniq_setting_write('appointment_service_weekly_schedules', ['services' => $services], $updatedBy);
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
function appointment_monthly_schedules(?string $purpose = null): array
{
    static $schedules = [];
    $cacheKey = $purpose ?? '__legacy__';
    if (isset($schedules[$cacheKey])) {
        return $schedules[$cacheKey];
    }
    $legacy = (array) cliniq_setting_read('appointment_monthly_schedules', ['months' => []]);
    $stored = $purpose !== null
        ? (array) (cliniq_setting_read('appointment_service_monthly_schedules', [])['services'][$purpose] ?? $legacy)
        : $legacy;
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
    return $schedules[$cacheKey] = $months;
}

function appointment_schedule_for_month(string $month, ?string $purpose = null): array
{
    try {
        $month = appointment_normalize_month_key($month);
    } catch (InvalidArgumentException $exception) {
        return appointment_weekly_schedule($purpose);
    }
    return appointment_monthly_schedules($purpose)[$month]['days'] ?? appointment_weekly_schedule($purpose);
}

function appointment_schedule_for_date(string $date, ?string $purpose = null): array
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return appointment_weekly_schedule($purpose);
    }
    return appointment_schedule_for_month($parsed->format('Y-m'), $purpose);
}

function appointment_save_monthly_schedule(string $month, array $submitted, ?int $updatedBy, ?string $purpose = null): void
{
    $month = appointment_normalize_month_key($month, true);
    $months = appointment_monthly_schedules($purpose);
    $months[$month] = ['days' => appointment_normalize_weekly_schedule($submitted)];
    ksort($months);
    if ($purpose === null) {
        cliniq_setting_write('appointment_monthly_schedules', ['months' => $months], $updatedBy);
        return;
    }
    $services = (array) (cliniq_setting_read('appointment_service_monthly_schedules', [])['services'] ?? []);
    $services[$purpose] = ['months' => $months];
    cliniq_setting_write('appointment_service_monthly_schedules', ['services' => $services], $updatedBy);
}

function appointment_delete_monthly_schedule(string $month, ?int $updatedBy, ?string $purpose = null): void
{
    $month = appointment_normalize_month_key($month, true);
    $months = appointment_monthly_schedules($purpose);
    unset($months[$month]);
    if ($purpose === null) {
        cliniq_setting_write('appointment_monthly_schedules', ['months' => $months], $updatedBy);
        return;
    }
    $services = (array) (cliniq_setting_read('appointment_service_monthly_schedules', [])['services'] ?? []);
    $services[$purpose] = ['months' => $months];
    cliniq_setting_write('appointment_service_monthly_schedules', ['services' => $services], $updatedBy);
}

function appointment_slot_is_open(string $date, string $time, ?string $purpose = null): bool
{
    return appointment_slot_is_open_for_schedule(appointment_schedule_for_date($date, $purpose), $date, $time);
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

function appointment_range_is_open(string $date, string $startTime, string $endTime, ?string $purpose = null): bool
{
    return appointment_range_is_open_for_schedule(
        appointment_schedule_for_date($date, $purpose),
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
        $date = (string) $row['appointment_date'];
        $purpose = (string) $row['purpose'];
        $timesByDate[$date][$purpose][] = $row['appointment_time'];
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

function appointment_patient_has_overlap(int $patientId, string $appointmentDatetime): bool
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $appointmentDatetime);
    if (!$start || $start->format('Y-m-d H:i:s') !== $appointmentDatetime) {
        return true;
    }
    $end = $start->modify('+' . appointment_duration_minutes() . ' minutes');
    $stmt = appointment_db()->prepare("SELECT appointment_id FROM appointments
        WHERE patient_id = ?
          AND status IN ('Pending', 'Scheduled', 'For Confirmation')
          AND appointment_datetime < ?
          AND DATE_ADD(appointment_datetime, INTERVAL " . appointment_duration_minutes() . " MINUTE) > ?
        LIMIT 1");
    $stmt->execute([$patientId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
    return (bool) $stmt->fetchColumn();
}

function appointment_active_conflicts_for_range(string $date, string $startTime, string $endTime, ?string $purpose = null): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($startTime, 0, 5));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($endTime, 0, 5));
    if (!$start || !$end || $start >= $end) {
        return [];
    }

    $stmt = appointment_db()->prepare("\n        SELECT appointment_id, patient_id, appointment_datetime, purpose, status\n        FROM appointments\n        WHERE status IN ('Pending', 'Scheduled', 'For Confirmation')\n          AND appointment_datetime < ?\n          AND DATE_ADD(appointment_datetime, INTERVAL " . appointment_duration_minutes() . " MINUTE) > ?\n          AND (? IS NULL OR purpose = ?)\n        ORDER BY appointment_datetime ASC, appointment_id ASC\n    ");
    $stmt->execute([$end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'), $purpose, $purpose]);

    return $stmt->fetchAll();
}

function appointment_status_transition_is_allowed(string $currentStatus, string $nextStatus): bool
{
    return in_array($nextStatus, match ($currentStatus) {
        'Pending' => ['Scheduled', 'Cancelled'],
        'Scheduled' => ['For Confirmation', 'No Show', 'Cancelled'],
        'For Confirmation' => ['Completed', 'No Show'],
        default => [],
    }, true);
}

function appointment_user_can_mark_no_show(?array $user = null): bool
{
    $role = (string) (($user ?? current_user())['role'] ?? '');
    return in_array($role, ['nurse', 'doctor', 'admin'], true);
}

function appointment_can_mark_no_show(array $appointment, ?DateTimeImmutable $now = null): bool
{
    $status = (string) ($appointment['status'] ?? '');
    if ($status === 'For Confirmation') {
        return true;
    }
    if ($status !== 'Scheduled') {
        return false;
    }
    $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($appointment['appointment_datetime'] ?? ''));
    return $scheduledAt !== false && $scheduledAt <= ($now ?? new DateTimeImmutable('now'));
}

/** @return array<string,array{state:string,message:string,icon:string}> */
function appointment_live_service_statuses(?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $date = $now->format('Y-m-d');
    $time = $now->format('H:i:s');
    $nextMinute = $now->modify('+1 minute')->format('H:i:s');
    $blocks = appointment_blocks_for_month(appointment_month_from_request($now->format('Y-m')));
    $apeBatches = appointment_ape_batches_for_range($date, $date);
    $db = appointment_db();
    $appointmentStmt = $db->prepare("SELECT 1 FROM appointments WHERE purpose = ? AND status IN ('Scheduled', 'For Confirmation') AND appointment_datetime <= ? LIMIT 1");
    $visitStmt = $db->prepare("SELECT 1 FROM visits WHERE status = 'Active' AND ((? = 'Dental' AND visit_purpose = 'Dental Consult') OR (? = 'Medical Consult' AND (visit_purpose IS NULL OR visit_purpose <> 'Dental Consult'))) LIMIT 1");
    $statuses = [];

    foreach (appointment_consult_purposes() as $purpose) {
        $appointmentStmt->execute([$purpose, $now->format('Y-m-d H:i:s')]);
        $visitStmt->execute([$purpose, $purpose]);
        $state = appointment_live_service_state(
            (bool) $appointmentStmt->fetchColumn() || (bool) $visitStmt->fetchColumn(),
            appointment_range_is_open($date, $time, $nextMinute, $purpose)
                && !appointment_range_is_blocked($date, $time, $nextMinute, $blocks, $purpose)
                && !appointment_live_time_overlaps_ape_batch($now, $apeBatches[$date] ?? [])
        );
        if ($state === 'busy') {
            $statuses[$purpose] = ['state' => 'busy', 'icon' => 'person', 'message' => $purpose . ' is currently assisting a patient.'];
            continue;
        }
        if ($state === 'closed') {
            $statuses[$purpose] = ['state' => 'closed', 'icon' => 'event_busy', 'message' => $purpose . ' is not accepting walk-ins right now.'];
            continue;
        }
        $statuses[$purpose] = ['state' => 'walk_in', 'icon' => 'directions_walk', 'message' => $purpose . ' may accept a walk-in now—please check with reception.'];
    }

    return $statuses;
}

function appointment_live_service_state(bool $busy, bool $openForWalkIns): string
{
    return $busy ? 'busy' : ($openForWalkIns ? 'walk_in' : 'closed');
}

function appointment_live_time_overlaps_ape_batch(DateTimeImmutable $now, array $batches): bool
{
    foreach ($batches as $batch) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $batch['schedule_date'] . ' ' . (string) $batch['start_time']);
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $batch['schedule_date'] . ' ' . (string) $batch['end_time']);
        if ($start !== false && $end !== false && $now >= $start && $now < $end) {
            return true;
        }
    }
    return false;
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

function appointment_time_is_blocked(string $date, string $time, array $blocksByDate, ?string $purpose = null): bool
{
    $slotStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time);
    if (!$slotStart || $slotStart->format('Y-m-d') !== $date) {
        return false;
    }

    return appointment_range_is_blocked(
        $date,
        $slotStart->format('H:i:s'),
        $slotStart->modify('+' . appointment_duration_minutes() . ' minutes')->format('H:i:s'),
        $blocksByDate,
        $purpose
    );
}

function appointment_range_is_blocked(string $date, string $startTime, string $endTime, array $blocksByDate, ?string $purpose = null): bool
{
    $blocks = array_values(array_filter($blocksByDate[$date] ?? [], static fn(array $block): bool => $purpose === null || appointment_block_applies_to($block, $purpose)));
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
