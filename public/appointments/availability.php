<?php
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $week = trim((string) ($_POST['week'] ?? ''));
    $redirect = 'availability.php' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? '?week=' . rawurlencode($week) : '');
    $user = current_user();
    $userId = (int) (current_user()['person_id'] ?? 0) ?: null;
    try {
        if ($action === 'save_schedule') {
            appointment_save_weekly_schedule(appointment_schedule_from_form($_POST), $userId);
            flash_message('success', 'Regular clinic working hours were saved.');
        } elseif ($action === 'save_month_schedule') {
            appointment_save_monthly_schedule((string) ($_POST['schedule_month'] ?? ''), appointment_schedule_from_form($_POST), $userId);
            flash_message('success', 'Future-month clinic working hours were saved.');
        } elseif ($action === 'delete_month_schedule') {
            appointment_delete_monthly_schedule((string) ($_POST['schedule_month'] ?? ''), $userId);
            flash_message('success', 'The future-month arrangement was removed.');
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                appointment_db()->prepare('DELETE FROM appointment_availability_blocks WHERE availability_block_id = ?')->execute([$id]);
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
            $start = trim((string) ($_POST['start_time'] ?? ''));
            $end = trim((string) ($_POST['end_time'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $selected = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $dateError = !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$selected || $selected->format('Y-m-d') !== $date
                ? 'Choose a valid date.'
                : (!appointment_date_is_clinic_day($date) ? 'Unavailable time can only be set Monday through Friday.' : null);
            $rangeError = !appointment_range_is_open($date, $start, $end);
            if (!$ids || $dateError || $rangeError || $reason === '') {
                flash_message('error', $dateError ?: ($reason === '' ? 'Enter a reason for the unavailable time.' : 'Choose a valid time within the clinic working hours.'));
            } elseif (appointment_active_conflicts_for_range($date, $start, $end) !== []) {
                flash_message('error', 'Cannot update this unavailable period because it overlaps an active appointment. Cancel or reschedule that appointment first.');
            } else {
                $db = appointment_db();
                $db->beginTransaction();
                try {
                    $db->prepare('UPDATE appointment_availability_blocks SET block_date = ?, start_time = ?, end_time = ?, reason = ? WHERE availability_block_id = ?')->execute([$date, $start, $end, $reason, $ids[0]]);
                    if (count($ids) > 1) {
                        $placeholders = implode(',', array_fill(0, count($ids) - 1, '?'));
                        $db->prepare("DELETE FROM appointment_availability_blocks WHERE availability_block_id IN ($placeholders)")->execute(array_slice($ids, 1));
                    }
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                flash_message('success', 'Unavailable block updated.');
            }
        } elseif ($action === 'add') {
            $submittedSlots = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string) $value), (array) ($_POST['block_slots'] ?? [])))));
            $submittedDates = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string) $value), (array) ($_POST['block_dates'] ?? [])))));
            $fallbackDate = trim((string) ($_POST['block_date'] ?? ''));
            if (!$submittedDates && $fallbackDate !== '') {
                $submittedDates[] = $fallbackDate;
            }
            $allDay = isset($_POST['all_day']);
            $start = trim((string) ($_POST['start_time'] ?? ''));
            $end = trim((string) ($_POST['end_time'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $validDates = [];
            $validSlots = [];
            $today = new DateTimeImmutable('today');
            $minimumDate = (int) $today->format('N') >= 6 ? $today->modify('next monday') : $today;
            $validateDate = static function (string $date) use ($minimumDate): ?string {
                $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$selectedDate || $selectedDate->format('Y-m-d') !== $date) return 'Choose valid dates to block.';
                if ($selectedDate < $minimumDate) return 'Past dates can no longer be marked unavailable.';
                if (!appointment_date_is_clinic_day($date)) return 'Clinic availability can only be managed from Monday through Friday.';
                return null;
            };
            foreach ($submittedDates as $date) {
                $dateError = $validateDate($date);
                if ($dateError !== null) {
                    $validDates = [];
                    flash_message('error', $dateError);
                    break;
                }
                $validDates[] = $date;
            }
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
                    if ($dateError !== null || !appointment_range_is_open($slotDate, $slotStart, $slotEnd)) {
                        $validSlots = [];
                        flash_message('error', $dateError ?: 'Choose valid time slots within the clinic working hours.');
                        break;
                    }
                    $validSlots[] = [$slotDate, $slotStart, $slotEnd];
                }
            }
            if ($validSlots) {
                foreach ($validSlots as [$slotDate, $slotStart, $slotEnd]) {
                    if (appointment_active_conflicts_for_range($slotDate, $slotStart, $slotEnd) !== []) {
                        $validSlots = [];
                        flash_message('error', 'Cannot mark this time unavailable because it overlaps an active appointment. Cancel or reschedule that appointment first.');
                        break;
                    }
                }
            }
            if (!$allDay && $submittedSlots && !$validSlots) {
                // Validation message is already set above.
            } elseif (!$allDay && $validSlots && $reason === '') {
                flash_message('error', 'Enter a reason for the unavailable time.');
            } elseif (!$allDay && $validSlots) {
                $stmt = appointment_db()->prepare('INSERT INTO appointment_availability_blocks (block_date, start_time, end_time, reason, created_by_person_id) VALUES (?, ?, ?, ?, ?)');
                $db = appointment_db();
                $db->beginTransaction();
                try {
                    foreach ($validSlots as [$slotDate, $slotStart, $slotEnd]) {
                        $stmt->execute([$slotDate, $slotStart, $slotEnd, $reason, (int) ($user['person_id'] ?? 0) ?: null]);
                    }
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                flash_message('success', 'Hourly unavailable block added for ' . count($validSlots) . ' time slot(s).');
            } elseif (!$validDates) {
                flash_message('error', 'Choose at least one date to block.');
            } elseif (!$allDay && !appointment_range_is_open($validDates[0], $start, $end)) {
                flash_message('error', 'Choose a valid start and end time within the clinic working hours.');
            } elseif ($reason === '') {
                flash_message('error', 'Enter a reason for the unavailable time.');
            } else {
                foreach ($validDates as $date) {
                    $hours = appointment_schedule_for_date($date)[(int) (new DateTimeImmutable($date))->format('N')];
                    $conflictStart = $allDay ? $hours['start'] : $start;
                    $conflictEnd = $allDay ? $hours['end'] : $end;
                    if (appointment_active_conflicts_for_range($date, $conflictStart, $conflictEnd) !== []) {
                        throw new InvalidArgumentException('Cannot mark this period unavailable because it overlaps an active appointment. Cancel or reschedule that appointment first.');
                    }
                }
                $stmt = appointment_db()->prepare('INSERT INTO appointment_availability_blocks (block_date, start_time, end_time, reason, created_by_person_id) VALUES (?, ?, ?, ?, ?)');
                $db = appointment_db();
                $db->beginTransaction();
                try {
                    foreach ($validDates as $date) {
                        $stmt->execute([$date, $allDay ? null : $start, $allDay ? null : $end, $reason, (int) ($user['person_id'] ?? 0) ?: null]);
                    }
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                flash_message('success', ($allDay ? 'Full-day' : 'Hourly') . ' unavailable block added for ' . count($validDates) . ' day(s).');
            }
        } else {
            throw new InvalidArgumentException('Unknown availability action.');
        }
    } catch (Throwable $exception) {
        flash_message($exception instanceof InvalidArgumentException ? 'warning' : 'error', $exception->getMessage());
    }
    header('Location: ' . $redirect);
    exit;
}

render_header('Clinic Availability');
render_clinic_command_header(
    'Scheduling',
    'Clinic Availability',
    'Manage working hours, future schedules, and unavailable appointment periods.',
    '<a href="' . e(app_url('appointments/index.php')) . '" class="btn btn-outline text-decoration-none"><span class="material-symbols-outlined">arrow_back</span> Back to Appointments</a>'
);
require __DIR__ . '/_availability_section.php';
render_footer();
