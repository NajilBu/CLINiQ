<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';
require_once __DIR__ . '/includes/patient-layout.php';

ensure_appointment_schema();
appointment_sync_overdue_confirmations();

$profile = student_require_official_access('Appointment booking');
$patientId = (int) $profile['person_id'];
$db = appointment_db();
$patientProfileStmt = $db->prepare('SELECT COUNT(*) FROM patients WHERE person_id = ?');
$patientProfileStmt->execute([$patientId]);
$hasAppointmentPatientProfile = (int) $patientProfileStmt->fetchColumn() === 1;
$allowedAppointmentPurposes = ['Medical Consult', 'Dental'];
$activeAppointmentStmt = $db->prepare("\n    SELECT appointment_id, appointment_datetime, purpose, status\n    FROM appointments\n    WHERE patient_id = ?\n      AND status IN ('Pending', 'Scheduled', 'For Confirmation')\n      AND purpose IN ('Medical Consult', 'Dental')\n    ORDER BY appointment_datetime DESC, created_at DESC\n");
$activeAppointmentStmt->execute([$patientId]);
$activeAppointments = $activeAppointmentStmt->fetchAll();
$activeAppointmentsByPurpose = [];
foreach ($activeAppointments as $activeRow) {
    $activeAppointmentsByPurpose[(string) $activeRow['purpose']] = $activeRow;
}
$activeAppointment = $activeAppointments[0] ?? null;
$availableAppointmentPurposes = array_values(array_diff($allowedAppointmentPurposes, array_keys($activeAppointmentsByPurpose)));
$patientActiveTimes = array_values(array_unique(array_map(
    static fn(array $row): string => date('H:i:s', strtotime((string) $row['appointment_datetime'])),
    $activeAppointments
)));
$pendingFeedbackVisits = clinic_feedback_pending_completed_visits($db, $patientId);
$feedbackRequired = $pendingFeedbackVisits !== [];
$feedbackPortalUrl = 'patient-feedback.php';

$timeSlots = [];
for ($hour = 7; $hour < 21; $hour++) {
    $timeSlots[] = ['value' => sprintf('%02d:00:00', $hour), 'label' => date('g:i A', mktime($hour, 0))];
}
$allowedTimes = array_column($timeSlots, 'value');
$month = appointment_month_from_request($_GET['month'] ?? null);
$weeklySchedule = appointment_schedule_for_month($month->format('Y-m'));
$success = false;
$successMessage = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$hasAppointmentPatientProfile || $patientId <= 0)) {
    $error = 'A clinical patient record is required before you can manage appointments.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_appointment') {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $cancellationReason = trim($_POST['cancellation_reason'] ?? '');

    if ($appointmentId <= 0) {
        $error = 'Please choose a valid appointment to cancel.';
    } elseif ($cancellationReason === '') {
        $error = 'Please provide a reason for cancelling the appointment.';
    } else {
        $stmt = $db->prepare("
            UPDATE appointments
            SET status = 'Cancelled', cancellation_reason = ?, cancelled_by = 'Patient'
            WHERE appointment_id = ?
              AND patient_id = ?
              AND status IN ('Pending', 'Scheduled')
        ");
        $stmt->execute([$cancellationReason, $appointmentId, $patientId]);

        if ($stmt->rowCount() > 0) {
            $success = true;
            $successMessage = 'Appointment cancelled. The clinic can now see your reason.';
        } else {
            $error = 'Only pending or scheduled appointments can be cancelled.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appt_type'], $_POST['appt_date'], $_POST['appt_time'])) {
    $type = trim($_POST['appt_type']);
    $dateStr = trim($_POST['appt_date']);
    $timeStr = trim($_POST['appt_time']);
    $note = trim($_POST['appt_note'] ?? '');
    $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr) ?: null;
    $datetimeStr = $dateStr . ' ' . $timeStr;
    $apeConflict = appointment_ape_batch_conflict($datetimeStr);

    if ($selectedDate) {
        $month = appointment_month_from_request($selectedDate->format('Y-m'));
    }

    $blocksForPostMonth = appointment_blocks_for_month($month);

    $purposeAppointment = $activeAppointmentsByPurpose[$type] ?? null;
    $sameTimeStmt = $db->prepare("SELECT appointment_id FROM appointments WHERE patient_id = ? AND appointment_datetime = ? AND status IN ('Pending', 'Scheduled', 'For Confirmation') LIMIT 1");
    $sameTimeStmt->execute([$patientId, $datetimeStr]);
    if ($type === '' || !in_array($type, $allowedAppointmentPurposes, true)) {
        $error = 'Please choose an appointment purpose.';
    } elseif ($feedbackRequired) {
        $error = 'Complete all required feedback for your completed clinic visits before requesting another appointment.';
    } elseif ($purposeAppointment !== null) {
        $error = 'You already have an active ' . $type . ' appointment. Complete or cancel it before booking another one.';
    } elseif ($sameTimeStmt->fetchColumn()) {
        $error = 'You already have another active appointment at that date and time. Choose a different time.';
    } elseif (!$selectedDate || $selectedDate->format('Y-m-d') !== $dateStr) {
        $error = 'Please choose a valid appointment date.';
    } elseif ($dateStr < date('Y-m-d')) {
        $error = 'Please choose a future clinic date.';
    } elseif (!appointment_date_is_clinic_day($dateStr)) {
        $error = 'The clinic is closed on the selected day.';
    } elseif (!in_array($timeStr, $allowedTimes, true) || !appointment_slot_is_open($dateStr, $timeStr)) {
        $error = 'Please choose one of the available appointment times.';
    } elseif (appointment_time_is_blocked($dateStr, $timeStr, $blocksForPostMonth)) {
        $error = 'That date or time is unavailable. Please choose another schedule.';
    } elseif ($apeConflict !== null) {
        $error = 'That time is reserved for an APE examination batch. Please choose another hour.';
    } elseif (appointment_slot_is_reserved($datetimeStr, $type)) {
        $error = 'That appointment time was already requested by another patient. Please choose another hour.';
    } else {
        $notes = 'Patient requested this appointment through the patient portal. Awaiting clinic approval.';
        if ($note !== '') {
            $notes .= ' Patient note: ' . $note;
        }

        try {
            $stmt = $db->prepare("INSERT INTO appointments (patient_id, appointment_datetime, purpose, status, request_source, notes) VALUES (?, ?, ?, 'Pending', 'Patient Portal', ?)");
            $stmt->execute([$patientId, $datetimeStr, $type, $notes]);
            $success = true;
            $successMessage = 'Appointment request sent. Please wait for clinic approval before going to the clinic.';
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $error = 'That appointment time was just taken. Please choose another hour.';
            } else {
                throw $exception;
            }
        }
    }
}

if ($success) {
    student_start_session();
    $_SESSION['student_flash_success'] = 'Appointment updated. ' . $successMessage;
    header('Location: patient-appointment.php?month=' . urlencode($month->format('Y-m')));
    exit;
}

$blocksByDate = appointment_blocks_for_month($month);
$studentAppointmentDates = appointment_patient_dates_for_month($patientId, $month);
$reservedTimesByDate = appointment_reserved_times_for_month($month);
$monthStart = $month->modify('first day of this month')->format('Y-m-d');
$monthEnd = $month->modify('last day of this month')->format('Y-m-d');
$apeBatchesByDate = appointment_ape_batches_for_range($monthStart, $monthEnd);

$availabilityPayload = [];
$availabilityDates = array_values(array_unique(array_merge(array_keys($blocksByDate), array_keys($reservedTimesByDate), array_keys($apeBatchesByDate))));
foreach ($availabilityDates as $date) {
    $blocks = $blocksByDate[$date] ?? [];
    $blockedTimes = [];
    $apeTimes = [];
    foreach ($timeSlots as $slot) {
        if (appointment_time_is_blocked($date, $slot['value'], $blocksByDate)) {
            $blockedTimes[] = $slot['value'];
        }
        if (appointment_time_overlaps_ape_batches($date, $slot['value'], $apeBatchesByDate)) {
            $apeTimes[] = $slot['value'];
        }
    }

    $availabilityPayload[$date] = [
        'fullDay' => appointment_is_full_day_blocked($blocks),
        'blockedTimes' => $blockedTimes,
        'reservedTimesByPurpose' => $reservedTimesByDate[$date] ?? [],
        'patientTimes' => $patientActiveTimes,
        'apeTimes' => $apeTimes,
    ];
}

$historyStmt = $db->prepare("
    SELECT *
    FROM appointments
    WHERE patient_id = ?
    ORDER BY appointment_datetime DESC, created_at DESC
    LIMIT 5
");
$historyStmt->execute([$patientId]);
$appointments = $historyStmt->fetchAll();

$firstDay = $month->modify('first day of this month');
$daysInMonth = (int) $month->format('t');
$leadingBlanks = (int) $firstDay->format('w');
$prevMonth = $month->modify('-1 month')->format('Y-m');
$nextMonth = $month->modify('+1 month')->format('Y-m');
$today = date('Y-m-d');

render_student_header('Appointments', 'appointment');
?>

<section class="student-page-header appointment-page-header">
    <div>
        <h1 class="student-title">Request Appointment</h1>
        <p class="student-subtitle appointment-page-subtitle">Requests need clinic approval before your visit.</p>
    </div>
</section>

<?php if ($success): ?>
    <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
        <span class="material-symbols-outlined">check_circle</span>
        <div>
            <strong>Appointment updated.</strong>
            <?= student_e($successMessage) ?>
        </div>
        <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </div>
<?php elseif ($error !== ''): ?>
    <div class="student-note student-note-danger mb-4">
        <span class="material-symbols-outlined">error</span>
        <div>
            <strong>Appointment not sent.</strong>
            <?= student_e($error) ?>
        </div>
    </div>
<?php endif; ?>

<div class="student-grid student-appointment-layout">
    <section class="student-card student-span-7">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Preferred Schedule</h2>
                <p class="student-card-copy">Choose an available date and time.</p>
            </div>
            <span class="student-badge student-badge-warning">Pending First</span>
        </div>
        <div class="student-card-pad">
            <?php if ($feedbackRequired): ?>
                <div class="student-note student-note-danger student-appointment-feedback-note">
                    <span class="material-symbols-outlined">rate_review</span>
                    <div>
                        <strong>Feedback required before another appointment.</strong><br>
                        <?= count($pendingFeedbackVisits) === 1 ? 'One active visit needs your feedback.' : count($pendingFeedbackVisits) . ' active visits need your feedback.' ?> You cannot request another clinic appointment until this required feedback is completed.
                        <p class="mt-3 mb-0"><a href="<?= student_e($feedbackPortalUrl) ?>" class="student-button-danger text-decoration-none">Complete Required Feedback <span class="material-symbols-outlined">arrow_forward</span></a></p>
                    </div>
                </div>
            <?php else: ?>
            <form id="booking-form" method="POST" action="?month=<?= student_e($month->format('Y-m')) ?>">
                <input type="hidden" name="appt_date" id="appt-date-input" value="">
                <input type="hidden" name="appt_time" id="appt-time-input" value="">
                <p class="appointment-privacy-note"><span class="material-symbols-outlined" aria-hidden="true">privacy_tip</span><span>Your appointment details are added to your clinic record. <a href="<?= student_e(student_legal_url('privacy')) ?>" target="_blank" rel="noopener" class="student-auth-link">Privacy Notice</a></span></p>
                <p class="student-card-copy mt-2"><span class="material-symbols-outlined" aria-hidden="true">schedule</span> Appointments may begin up to 15 minutes late while the clinic prepares for the next patient.</p>

                <div class="student-field mb-5">
                    <label class="student-label" for="appt-type">1. Appointment Purpose</label>
                    <select id="appt-type" name="appt_type" class="student-select" required>
                        <option value="" disabled selected>Select appointment purpose first...</option>
                        <?php foreach ($availableAppointmentPurposes as $purpose): ?>
                            <option value="<?= student_e($purpose) ?>"><?= student_e($purpose) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="student-card-copy mt-2 mb-0" id="appointment-purpose-hint">Choose a purpose to see the clinic dates and times available for that service.</p>
                </div>

                <div class="student-field" id="appointment-calendar-panel"
                     hidden
                     data-availability="<?= student_e(json_encode($availabilityPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
                     data-weekly-schedule="<?= student_e(json_encode($weeklySchedule, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
                     data-current-month="<?= student_e($month->format('Y-m')) ?>">
                    <div class="student-month-row">
                        <label class="student-label mb-0">2. Preferred Date</label>
                        <div class="student-month-nav">
                            <a href="?month=<?= student_e($prevMonth) ?>" aria-label="Previous month" data-appointment-month-link>
                                <span class="material-symbols-outlined">chevron_left</span>
                            </a>
                            <strong><?= student_e($month->format('F Y')) ?></strong>
                            <a href="?month=<?= student_e($nextMonth) ?>" aria-label="Next month" data-appointment-month-link>
                                <span class="material-symbols-outlined">chevron_right</span>
                            </a>
                        </div>
                    </div>
                    <div class="student-calendar-legend">
                        <span><i class="legend-open"></i>Available</span>
                        <span><i class="legend-blocked"></i>Unavailable</span>
                        <span><i class="legend-partial"></i>Limited hours</span>
                        <span><i class="legend-blocked"></i>APE examination</span>
                        <span><i class="legend-appointment"></i>Your appointment</span>
                    </div>
                    <div class="student-calendar-grid mb-2 text-center">
                        <?php foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $day): ?>
                            <span class="student-calendar-weekday"><?= student_e($day) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="student-calendar-grid" id="calendar-grid">
                        <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
                            <span class="student-date-empty"></span>
                        <?php endfor; ?>

                        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                            <?php
                            $date = $month->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                            $blocks = $blocksByDate[$date] ?? [];
                            $fullDay = appointment_is_full_day_blocked($blocks);
                            $unavailableTimes = array_values(array_unique(array_merge(
                                $availabilityPayload[$date]['blockedTimes'] ?? [],
                                $availabilityPayload[$date]['apeTimes'] ?? []
                            )));
                            $openTimes = array_values(array_filter($allowedTimes, static fn(string $time): bool => appointment_slot_is_open($date, $time)));
                            $allTimesUnavailable = !$openTimes || count(array_intersect($openTimes, $unavailableTimes)) >= count($openTimes);
                            $hasAppointment = !empty($studentAppointmentDates[$date]);
                            $isPast = $date < $today;
                            $isClosed = !appointment_date_is_clinic_day($date);
                            $disabled = $fullDay || $allTimesUnavailable || $isPast || $isClosed;
                            $classes = ['student-date-btn'];
                            $classes[] = $disabled ? 'disabled' : 'available';
                            if (!$disabled && !empty($unavailableTimes)) {
                                $classes[] = 'partial';
                            }
                            if ($hasAppointment) {
                                $classes[] = 'has-appointment';
                            }
                            ?>
                            <button type="button"
                                    class="<?= student_e(implode(' ', $classes)) ?>"
                                    data-date="<?= student_e($date) ?>"
                                    data-base-disabled="<?= $disabled ? 'true' : 'false' ?>"
                                    aria-controls="time-selection-panel"
                                    aria-expanded="false"
                                    <?= $disabled ? 'disabled' : '' ?>>
                                <span><?= (int) $day ?></span>
                                <?php if ($hasAppointment): ?>
                                    <small>Booked</small>
                                <?php elseif ($fullDay || $allTimesUnavailable || $isClosed): ?>
                                    <small>Closed</small>
                                <?php elseif (!$isPast && !empty($unavailableTimes)): ?>
                                    <small>Limited</small>
                                <?php endif; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <p class="student-calendar-action-hint">
                        <span class="material-symbols-outlined" aria-hidden="true">touch_app</span>
                        <span class="student-calendar-desktop-hint">Double-click an available date to choose a time. On touchscreens, tap once.</span>
                        <span class="student-calendar-mobile-hint">Tap a date to select a time.</span>
                    </p>
                    <p class="student-card-copy mt-2" data-appointment-calendar-status role="status" aria-live="polite"></p>
                </div>

                <div class="student-appointment-booking-sheet" id="appointment-booking-sheet" aria-hidden="true">
                    <div class="student-appointment-booking-sheet-head">
                        <div><p>Appointment details</p><strong>Finish your request</strong></div>
                        <button type="button" data-close-booking-sheet aria-label="Close appointment details"><span class="material-symbols-outlined">close</span></button>
                    </div>
                <div class="student-field student-selected-schedule" id="selected-schedule-summary" hidden aria-live="polite">
                    <span class="student-icon-box">
                        <span class="material-symbols-outlined">event_available</span>
                    </span>
                    <div>
                        <span>Selected Schedule</span>
                        <strong id="selected-schedule-text"></strong>
                    </div>
                </div>

                <div class="student-field">
                    <label class="student-label" for="appt-note">Optional Note</label>
                    <textarea id="appt-note" name="appt_note" class="student-textarea" placeholder="Briefly describe your concern or document you need to submit."></textarea>
                </div>

                <button type="submit" class="student-button w-full">
                    Send Appointment Request
                    <span class="material-symbols-outlined">send</span>
                </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <details class="student-mobile-more appointment-calendar-guide">
        <summary>Calendar guide</summary>
    <section class="student-card student-span-5">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Calendar Guide</h2>
                <p class="student-card-copy">What each state means</p>
            </div>
            <span class="student-badge student-badge-info">Guide</span>
        </div>
        <div class="student-card-pad">
            <div class="student-progress-list mb-4">
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">event_available</span>
                    <div>
                        <strong>Available dates</strong>
                        <span>You can request any open time slot.</span>
                    </div>
                    <span class="student-badge student-badge-success">Open</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">schedule</span>
                    <div>
                        <strong>Limited hours</strong>
                        <span>Some time slots are blocked by the clinic.</span>
                    </div>
                    <span class="student-badge student-badge-warning">Limited</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">event_busy</span>
                    <div>
                        <strong>Unavailable dates</strong>
                        <span>The clinic cannot accept appointment requests.</span>
                    </div>
                    <span class="student-badge student-badge-danger">Closed</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">pending_actions</span>
                    <div>
                        <strong>Orange dates</strong>
                        <span>You already have a pending or scheduled appointment.</span>
                    </div>
                    <span class="student-badge student-badge-warning">Yours</span>
                </div>
            </div>

            <div class="student-note student-note-warning">
                <span class="material-symbols-outlined">info</span>
                <div><strong>Pending is not confirmed.</strong> Wait for the clinic to approve your request before visiting.</div>
            </div>
        </div>
    </section>
    </details>
</div>

<div class="student-calendar-time-modal" id="appointment-time-modal" aria-hidden="true">
    <div class="student-calendar-time-dialog" role="dialog" aria-modal="true" aria-labelledby="appointment-time-title">
        <div class="student-calendar-time-header">
            <div>
                <p>Available Appointment Times</p>
                <h2 id="appointment-time-title">Choose a time</h2>
                <span id="selected-date-label"></span>
            </div>
            <button type="button" class="student-calendar-time-close" data-close-time-modal aria-label="Close time selection">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <div class="student-calendar-time-guide">
            <span><i class="is-open"></i>Available</span>
            <span><i class="is-reserved"></i>Reserved</span>
            <span><i class="is-blocked"></i>Unavailable</span>
        </div>

        <div class="student-calendar-time-list" id="time-slots">
            <?php for ($hour = 7; $hour < 21; $hour++): ?>
                <?php
                $value = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00:00';
                $isOffered = in_array($value, $allowedTimes, true);
                $startLabel = date('g:i A', mktime($hour, 0));
                $endLabel = date('g:i A', mktime($hour + 1, 0));
                ?>
                <button type="button"
                        class="student-time-slot student-calendar-time-row <?= $isOffered ? '' : 'is-not-offered' ?>"
                        <?= $isOffered ? 'data-time="' . student_e($value) . '"' : 'disabled' ?>>
                    <span class="student-calendar-time-hour"><?= student_e($startLabel) ?></span>
                    <span class="student-calendar-time-range"><?= student_e($startLabel) ?>–<?= student_e($endLabel) ?></span>
                    <strong class="student-calendar-time-status"><?= $isOffered ? 'Available' : 'Not Offered' ?></strong>
                </button>
            <?php endfor; ?>
        </div>
    </div>
</div>

<details class="student-mobile-more appointment-history">
    <summary>Recent appointment requests</summary>
<section class="student-card mt-4">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">Recent Appointment Requests</h2>
            <p class="student-card-copy">Your latest requests and clinic decisions</p>
        </div>
        <span class="student-badge student-badge-info"><?= count($appointments) ?> Record(s)</span>
    </div>
    <div class="student-card-pad grid gap-3">
        <?php if (empty($appointments)): ?>
            <div class="student-note student-note-warning">
                <span class="material-symbols-outlined">event_busy</span>
                <div><strong>No requests yet.</strong> Submit your first appointment request using the form above.</div>
            </div>
        <?php else: ?>
            <?php foreach ($appointments as $appointment): ?>
                <?php
                $status = $appointment['status'];
                $canCancel = in_array($status, ['Pending', 'Scheduled'], true);
                $badge = match ($status) {
                    'Scheduled', 'Completed' => 'student-badge-success',
                    'Cancelled', 'No Show' => 'student-badge-danger',
                    'Pending', 'For Confirmation' => 'student-badge-warning',
                    default => 'student-badge-info',
                };
                $displayStatus = match ($status) {
                    'Pending' => 'Awaiting Clinic',
                    'For Confirmation' => 'Awaiting Clinic Confirmation',
                    default => $status,
                };
                ?>
                <div class="student-document-card">
                    <span class="student-icon-box">
                        <span class="material-symbols-outlined">event_note</span>
                    </span>
                    <div class="student-document-meta">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3><?= student_e($appointment['purpose']) ?></h3>
                            <span class="student-badge <?= student_e($badge) ?>"><?= student_e($displayStatus) ?></span>
                        </div>
                        <p><?= student_e(date('F j, Y \a\t g:i A', strtotime($appointment['appointment_datetime']))) ?></p>
                        <?php if ($status === 'For Confirmation'): ?>
                            <p><strong>Status:</strong> Your appointment time has passed. Please wait for clinic staff to confirm if it was completed.</p>
                        <?php endif; ?>
                        <?php if ($status === 'Cancelled' && trim((string) ($appointment['cancellation_reason'] ?? '')) !== ''): ?>
                            <p><strong>Reason:</strong> <?= student_e($appointment['cancellation_reason']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="student-appointment-actions">
                        <span class="student-badge <?= student_e($badge) ?>"><?= student_e($displayStatus) ?></span>
                        <?php if ($canCancel): ?>
                            <form method="post" class="student-cancel-form">
                                <input type="hidden" name="action" value="cancel_appointment">
                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                                <input class="student-input student-cancel-input" type="text" name="cancellation_reason" placeholder="Reason for cancellation" required maxlength="500">
                                <button type="submit" class="student-button-danger">
                                    Cancel
                                    <span class="material-symbols-outlined">cancel</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
</details>

<?php if (!$feedbackRequired): ?>
<script>
    let availability = <?= json_encode($availabilityPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let weeklySchedule = <?= json_encode($weeklySchedule, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const dateInput = document.getElementById('appt-date-input');
    const timeInput = document.getElementById('appt-time-input');
    const timeSlots = Array.from(document.querySelectorAll('.student-time-slot[data-time]'));
    const timeModal = document.getElementById('appointment-time-modal');
    const selectedDateLabel = document.getElementById('selected-date-label');
    const selectedScheduleSummary = document.getElementById('selected-schedule-summary');
    const selectedScheduleText = document.getElementById('selected-schedule-text');
    const bookingSheet = document.getElementById('appointment-booking-sheet');
    const purposeSelect = document.getElementById('appt-type');
    const purposeHint = document.getElementById('appointment-purpose-hint');
    const mobileBookingSheet = window.matchMedia('(max-width: 640px)').matches;
    const coarsePointer = window.matchMedia('(pointer: coarse)').matches;

    function formatSelectedDate(date) {
        const [year, month, day] = date.split('-').map(Number);
        return new Intl.DateTimeFormat('en-US', {
            month: 'long',
            day: 'numeric',
            year: 'numeric'
        }).format(new Date(year, month - 1, day));
    }

    function updateTimeSlots(date) {
        const blockedTimes = availability[date]?.blockedTimes || [];
        const purpose = document.getElementById('appt-type')?.value || '';
        const reservedByPurpose = availability[date]?.reservedTimesByPurpose || {};
        const patientTimes = availability[date]?.patientTimes || [];
        const allowedPurposes = <?= json_encode($availableAppointmentPurposes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const reservedTimes = reservedByPurpose[purpose] || [];
        const apeTimes = availability[date]?.apeTimes || [];
        const [year, month, day] = date.split('-').map(Number);
        const weekday = new Date(year, month - 1, day).getDay() || 7;
        const hours = weeklySchedule[weekday];

        timeSlots.forEach((slot) => {
            const start = slot.dataset.time.slice(0, 5);
            const end = String(Number(start.slice(0, 2)) + 1).padStart(2, '0') + ':00';
            const isWithinHours = Boolean(hours?.enabled) && start >= hours.start && end <= hours.end;
            const isClinicBlocked = blockedTimes.includes(slot.dataset.time);
            const isReserved = reservedTimes.includes(slot.dataset.time);
            const medicalReserved = !allowedPurposes.includes('Medical Consult') || (reservedByPurpose['Medical Consult'] || []).includes(slot.dataset.time);
            const dentalReserved = !allowedPurposes.includes('Dental') || (reservedByPurpose['Dental'] || []).includes(slot.dataset.time);
            const isApeBlocked = apeTimes.includes(slot.dataset.time);
            const patientHasTime = patientTimes.includes(slot.dataset.time);
            const isUnavailable = !purpose || !isWithinHours || isClinicBlocked || isApeBlocked || isReserved || patientHasTime;
            slot.hidden = !isWithinHours;
            slot.classList.toggle('disabled', isUnavailable);
            slot.classList.toggle('is-blocked', isClinicBlocked || isApeBlocked);
            slot.classList.toggle('is-reserved', isReserved);
            slot.disabled = isUnavailable;
            const purposeStatus = medicalReserved && !dentalReserved
                ? 'Dental available'
                : dentalReserved && !medicalReserved
                    ? 'Medical Consult available'
                    : '';
            slot.title = !isWithinHours ? '' : (patientHasTime
                ? 'You already have an appointment at this time'
                : (isApeBlocked
                    ? 'Reserved for APE examinations'
                    : (isReserved
                        ? 'Reserved for this purpose'
                        : (isClinicBlocked ? 'This time is unavailable' : ''))));
            slot.querySelector('.student-calendar-time-status').textContent = !purpose
                ? 'Select purpose'
                : (patientHasTime
                    ? 'Your appointment'
                    : (isApeBlocked
                        ? 'APE Examination'
                        : (isReserved ? 'Reserved for this purpose' : (isClinicBlocked ? 'Unavailable' : purposeStatus))));

            if (isUnavailable && slot.classList.contains('selected')) {
                slot.classList.remove('selected');
                timeInput.value = '';
            }
        });
        syncPurposeOptions(date, timeInput.value);
    }

    function applyPurposeToCalendar() {
        const purpose = purposeSelect?.value || '';
        const currentCalendarPanel = document.getElementById('appointment-calendar-panel');
        if (currentCalendarPanel) currentCalendarPanel.hidden = !purpose;
        if (purposeHint) purposeHint.textContent = purpose
            ? `Showing clinic availability for ${purpose}.`
            : 'Choose a purpose to see the clinic dates and times available for that service.';
        if (!purpose) return;
        document.querySelectorAll('.student-date-btn').forEach((button) => {
            const date = button.dataset.date || '';
            const data = availability[date] || {};
            const blocked = data.blockedTimes || [];
            const reserved = (data.reservedTimesByPurpose || {})[purpose] || [];
            const ape = data.apeTimes || [];
            const patientTimesForDate = data.patientTimes || [];
            const [year, month, day] = date.split('-').map(Number);
            const weekday = new Date(year, month - 1, day).getDay() || 7;
            const hours = weeklySchedule[weekday];
            const hasOpenPurposeSlot = <?= json_encode($allowedTimes) ?>.some((value) => {
                const start = value.slice(0, 5);
                const end = String(Number(start.slice(0, 2)) + 1).padStart(2, '0') + ':00';
                return Boolean(hours?.enabled) && start >= hours.start && end <= hours.end
                    && !blocked.includes(value) && !ape.includes(value)
                    && !reserved.includes(value) && !patientTimesForDate.includes(value);
            });
            const disabled = button.dataset.baseDisabled === 'true' || !hasOpenPurposeSlot;
            button.disabled = disabled;
            button.classList.toggle('disabled', disabled);
            button.classList.toggle('available', !disabled);
        });
    }

    function syncPurposeOptions(date, time) {
        const purposeSelect = document.getElementById('appt-type');
        if (!purposeSelect || !date || !time) return;
        const reservedByPurpose = availability[date]?.reservedTimesByPurpose || {};
        Array.from(purposeSelect.options).forEach((option) => {
            if (!option.value) return;
            const unavailable = (reservedByPurpose[option.value] || []).includes(time);
            option.disabled = unavailable;
            const base = option.dataset.baseLabel || option.textContent.replace(/ — (Available|Reserved)/, '');
            option.dataset.baseLabel = base;
            option.textContent = `${base} — ${unavailable ? 'Reserved' : 'Available'}`;
        });
        if (purposeSelect.selectedOptions[0]?.disabled) purposeSelect.value = '';
    }

    function closeTimeModal() {
        timeModal.classList.remove('active');
        timeModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('student-time-modal-open');
    }

    function closeBookingSheet() {
        bookingSheet.classList.remove('active');
        bookingSheet.setAttribute('aria-hidden', 'true');
    }

    function openBookingSheet() {
        bookingSheet.classList.add('active');
        bookingSheet.setAttribute('aria-hidden', 'false');
    }

    function openTimeModal(focusFirstTime = false) {
        timeModal.classList.add('active');
        timeModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('student-time-modal-open');

        if (focusFirstTime) {
            const firstAvailableTime = timeSlots.find((slot) => !slot.hidden && !slot.disabled);
            firstAvailableTime?.focus({ preventScroll: true });
        }
    }

    function selectAppointmentDate(button, revealTimes = false, focusFirstTime = false) {
        document.querySelectorAll('.student-date-btn').forEach((item) => {
            item.classList.remove('selected');
            item.setAttribute('aria-expanded', 'false');
        });
        button.classList.add('selected');
        button.setAttribute('aria-expanded', revealTimes ? 'true' : 'false');
        dateInput.value = button.dataset.date;
        timeInput.value = '';
        timeSlots.forEach((item) => item.classList.remove('selected'));
        selectedScheduleSummary.hidden = true;
        updateTimeSlots(button.dataset.date);
        selectedDateLabel.textContent = formatSelectedDate(button.dataset.date);
        closeTimeModal();
        closeBookingSheet();

        if (!revealTimes) {
            return;
        }

        openTimeModal(focusFirstTime);
    }

    function bindCalendarButtons() {
        document.querySelectorAll('.student-date-btn:not(.disabled)').forEach((button) => {
            button.addEventListener('click', (event) => {
                const shouldReveal = coarsePointer || event.detail === 0;
                selectAppointmentDate(button, shouldReveal, event.detail === 0);
            });

            button.addEventListener('dblclick', (event) => {
                event.preventDefault();
                selectAppointmentDate(button, true);
            });
        });
    }
    bindCalendarButtons();
    purposeSelect?.addEventListener('change', () => {
        dateInput.value = '';
        timeInput.value = '';
        selectedScheduleSummary.hidden = true;
        closeTimeModal();
        closeBookingSheet();
        applyPurposeToCalendar();
    });
    applyPurposeToCalendar();

    let monthLoadController = null;
    document.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-appointment-month-link]');
        if (!link) return;
        event.preventDefault();

        monthLoadController?.abort();
        const controller = new AbortController();
        monthLoadController = controller;
        const currentPanel = document.getElementById('appointment-calendar-panel');
        const status = currentPanel?.querySelector('[data-appointment-calendar-status]');
        currentPanel?.setAttribute('aria-busy', 'true');
        if (status) status.textContent = 'Loading calendar…';

        try {
            const response = await fetch(link.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('The calendar could not be loaded.');
            const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = parsed.getElementById('appointment-calendar-panel');
            if (!replacement) throw new Error('The calendar response was incomplete.');

            availability = JSON.parse(replacement.dataset.availability || '{}');
            weeklySchedule = JSON.parse(replacement.dataset.weeklySchedule || '{}');
            currentPanel.replaceWith(replacement);
            document.getElementById('booking-form').action = `?month=${encodeURIComponent(replacement.dataset.currentMonth || '')}`;
            dateInput.value = '';
            timeInput.value = '';
            selectedScheduleSummary.hidden = true;
            timeSlots.forEach(slot => slot.classList.remove('selected'));
            closeTimeModal();
            closeBookingSheet();
            bindCalendarButtons();
            applyPurposeToCalendar();
            history.pushState({ appointmentMonth: replacement.dataset.currentMonth }, '', link.href);
            replacement.querySelector('[data-appointment-calendar-status]').textContent = '';
        } catch (error) {
            if (error.name !== 'AbortError') {
                currentPanel?.removeAttribute('aria-busy');
                if (status) status.textContent = error.message || 'Unable to load this month. Use the month button again.';
            }
        } finally {
            if (monthLoadController === controller) {
                monthLoadController = null;
                document.getElementById('appointment-calendar-panel')?.removeAttribute('aria-busy');
            }
        }
    });

    timeSlots.forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }

            timeSlots.forEach((item) => item.classList.remove('selected'));
            button.classList.add('selected');
            timeInput.value = button.dataset.time;
            syncPurposeOptions(dateInput.value, timeInput.value);
            const chosenTime = button.querySelector('.student-calendar-time-hour').textContent.trim();
            selectedScheduleText.textContent = formatSelectedDate(dateInput.value) + ' at ' + chosenTime;
            selectedScheduleSummary.hidden = false;
            closeTimeModal();
            if (mobileBookingSheet) openBookingSheet();
        });
    });

    document.querySelectorAll('[data-close-booking-sheet]').forEach((button) => {
        button.addEventListener('click', closeBookingSheet);
    });

    document.querySelectorAll('[data-close-time-modal]').forEach((button) => {
        button.addEventListener('click', closeTimeModal);
    });

    timeModal.addEventListener('click', (event) => {
        if (event.target === timeModal) {
            closeTimeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && timeModal.classList.contains('active')) {
            closeTimeModal();
        }
    });

    document.getElementById('booking-form').addEventListener('submit', function (event) {
        if (!dateInput.value || !timeInput.value) {
            event.preventDefault();
            alert('Please select an available date and time.');
        }
    });
    document.getElementById('appt-type')?.addEventListener('change', () => {
        const selectedDate = dateInput.value;
        if (selectedDate) updateTimeSlots(selectedDate);
    });
</script>
<?php endif; ?>

<?php render_student_footer(); ?>
