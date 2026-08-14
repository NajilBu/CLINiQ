<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/includes/patient-layout.php';

ensure_appointment_schema();

$profile = student_require_login();
$patientId = (int) $profile['person_id'];
$db = appointment_db();
$patientProfileStmt = $db->prepare('SELECT COUNT(*) FROM patients WHERE person_id = ?');
$patientProfileStmt->execute([$patientId]);
$hasAppointmentPatientProfile = (int) $patientProfileStmt->fetchColumn() === 1;

$timeSlots = [
    ['value' => '08:00:00', 'label' => '8:00 AM'],
    ['value' => '09:00:00', 'label' => '9:00 AM'],
    ['value' => '10:00:00', 'label' => '10:00 AM'],
    ['value' => '11:00:00', 'label' => '11:00 AM'],
    ['value' => '12:00:00', 'label' => '12:00 PM'],
    ['value' => '13:00:00', 'label' => '1:00 PM'],
    ['value' => '14:00:00', 'label' => '2:00 PM'],
    ['value' => '15:00:00', 'label' => '3:00 PM'],
    ['value' => '16:00:00', 'label' => '4:00 PM'],
];
$allowedTimes = array_column($timeSlots, 'value');

$month = appointment_month_from_request($_GET['month'] ?? null);
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

    if ($selectedDate) {
        $month = appointment_month_from_request($selectedDate->format('Y-m'));
    }

    $blocksForPostMonth = appointment_blocks_for_month($month);

    if ($type === '') {
        $error = 'Please choose an appointment purpose.';
    } elseif (!$selectedDate || $selectedDate->format('Y-m-d') !== $dateStr) {
        $error = 'Please choose a valid appointment date.';
    } elseif ($dateStr < date('Y-m-d')) {
        $error = 'Please choose a future clinic date.';
    } elseif (!in_array($timeStr, $allowedTimes, true)) {
        $error = 'Please choose one of the available appointment times.';
    } elseif (appointment_time_is_blocked($dateStr, $timeStr, $blocksForPostMonth)) {
        $error = 'That date or time is unavailable. Please choose another schedule.';
    } elseif (appointment_slot_is_reserved($datetimeStr)) {
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

$blocksByDate = appointment_blocks_for_month($month);
$studentAppointmentDates = appointment_patient_dates_for_month($patientId, $month);
$reservedTimesByDate = appointment_reserved_times_for_month($month);

$availabilityPayload = [];
$availabilityDates = array_values(array_unique(array_merge(array_keys($blocksByDate), array_keys($reservedTimesByDate))));
foreach ($availabilityDates as $date) {
    $blocks = $blocksByDate[$date] ?? [];
    $blockedTimes = [];
    foreach ($timeSlots as $slot) {
        if (appointment_time_is_blocked($date, $slot['value'], $blocksByDate)) {
            $blockedTimes[] = $slot['value'];
        }
    }

    $availabilityPayload[$date] = [
        'fullDay' => appointment_is_full_day_blocked($blocks),
        'blockedTimes' => $blockedTimes,
        'reservedTimes' => array_values(array_unique($reservedTimesByDate[$date] ?? [])),
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

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Clinic Appointment</p>
        <h1 class="student-title">Request Appointment</h1>
        <p class="student-subtitle">Choose your preferred schedule. The clinic will approve the request before it becomes official.</p>
    </div>
    <span class="student-badge student-badge-info">
        <span class="material-symbols-outlined text-[14px]">approval</span>
        Clinic Approval Required
    </span>
</section>

<?php if ($success): ?>
    <div class="student-note student-note-success mb-4">
        <span class="material-symbols-outlined">check_circle</span>
        <div>
            <strong>Appointment updated.</strong>
            <?= student_e($successMessage) ?>
        </div>
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

<div class="student-grid">
    <section class="student-card student-span-7">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Preferred Schedule</h2>
                <p class="student-card-copy">Unavailable clinic dates and hours are blocked from selection.</p>
            </div>
            <span class="student-badge student-badge-warning">Pending First</span>
        </div>
        <div class="student-card-pad">
            <form id="booking-form" method="POST" action="?month=<?= student_e($month->format('Y-m')) ?>">
                <input type="hidden" name="appt_date" id="appt-date-input" value="">
                <input type="hidden" name="appt_time" id="appt-time-input" value="">

                <div class="student-field">
                    <label class="student-label" for="appt-type">Appointment Purpose</label>
                    <select id="appt-type" name="appt_type" class="student-select" required>
                        <option value="" disabled selected>Select appointment purpose...</option>
                        <?php foreach (dropdown_options('appointment_purpose') as $purpose): ?>
                            <option value="<?= student_e($purpose) ?>"><?= student_e($purpose) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="student-field">
                    <div class="student-month-row">
                        <label class="student-label mb-0">Preferred Date</label>
                        <div class="student-month-nav">
                            <a href="?month=<?= student_e($prevMonth) ?>" aria-label="Previous month">
                                <span class="material-symbols-outlined">chevron_left</span>
                            </a>
                            <strong><?= student_e($month->format('F Y')) ?></strong>
                            <a href="?month=<?= student_e($nextMonth) ?>" aria-label="Next month">
                                <span class="material-symbols-outlined">chevron_right</span>
                            </a>
                        </div>
                    </div>
                    <div class="student-calendar-legend">
                        <span><i class="legend-open"></i>Available</span>
                        <span><i class="legend-blocked"></i>Unavailable</span>
                        <span><i class="legend-partial"></i>Limited hours</span>
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
                                $availabilityPayload[$date]['reservedTimes'] ?? []
                            )));
                            $allTimesUnavailable = count(array_intersect($allowedTimes, $unavailableTimes)) >= count($allowedTimes);
                            $hasAppointment = !empty($studentAppointmentDates[$date]);
                            $isPast = $date < $today;
                            $disabled = $fullDay || $allTimesUnavailable || $isPast;
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
                                    aria-controls="time-selection-panel"
                                    aria-expanded="false"
                                    <?= $disabled ? 'disabled' : '' ?>>
                                <span><?= (int) $day ?></span>
                                <?php if ($hasAppointment): ?>
                                    <small>Booked</small>
                                <?php elseif ($fullDay || $allTimesUnavailable): ?>
                                    <small>Closed</small>
                                <?php elseif (!$isPast && !empty($unavailableTimes)): ?>
                                    <small>Limited</small>
                                <?php endif; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <p class="student-calendar-action-hint">
                        <span class="material-symbols-outlined" aria-hidden="true">touch_app</span>
                        Double-click an available date to choose a time. On touchscreens, tap once.
                    </p>
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
            </form>
        </div>
    </section>

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
            <?php for ($hour = 8; $hour < 17; $hour++): ?>
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
                    'Pending' => 'student-badge-warning',
                    default => 'student-badge-info',
                };
                ?>
                <div class="student-document-card">
                    <span class="student-icon-box">
                        <span class="material-symbols-outlined">event_note</span>
                    </span>
                    <div class="student-document-meta">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3><?= student_e($appointment['purpose']) ?></h3>
                            <span class="student-badge <?= student_e($badge) ?>"><?= student_e($status) ?></span>
                        </div>
                        <p><?= student_e(date('F j, Y \a\t g:i A', strtotime($appointment['appointment_datetime']))) ?></p>
                        <?php if ($status === 'Cancelled' && trim((string) ($appointment['cancellation_reason'] ?? '')) !== ''): ?>
                            <p><strong>Reason:</strong> <?= student_e($appointment['cancellation_reason']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="student-appointment-actions">
                        <span class="student-badge <?= student_e($badge) ?>"><?= student_e($status === 'Pending' ? 'Awaiting Clinic' : $status) ?></span>
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

<script>
    const availability = <?= json_encode($availabilityPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const dateInput = document.getElementById('appt-date-input');
    const timeInput = document.getElementById('appt-time-input');
    const timeSlots = Array.from(document.querySelectorAll('.student-time-slot[data-time]'));
    const timeModal = document.getElementById('appointment-time-modal');
    const selectedDateLabel = document.getElementById('selected-date-label');
    const selectedScheduleSummary = document.getElementById('selected-schedule-summary');
    const selectedScheduleText = document.getElementById('selected-schedule-text');
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
        const reservedTimes = availability[date]?.reservedTimes || [];

        timeSlots.forEach((slot) => {
            const isClinicBlocked = blockedTimes.includes(slot.dataset.time);
            const isReserved = reservedTimes.includes(slot.dataset.time);
            const isUnavailable = isClinicBlocked || isReserved;
            slot.classList.toggle('disabled', isUnavailable);
            slot.classList.toggle('is-blocked', isClinicBlocked);
            slot.classList.toggle('is-reserved', isReserved);
            slot.disabled = isUnavailable;
            slot.title = isReserved
                ? 'Another patient has already requested this time'
                : (isClinicBlocked ? 'This time is unavailable' : '');
            slot.querySelector('.student-calendar-time-status').textContent = isReserved
                ? 'Reserved'
                : (isClinicBlocked ? 'Unavailable' : 'Available');

            if (isUnavailable && slot.classList.contains('selected')) {
                slot.classList.remove('selected');
                timeInput.value = '';
            }
        });
    }

    function closeTimeModal() {
        timeModal.classList.remove('active');
        timeModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('student-time-modal-open');
    }

    function openTimeModal(focusFirstTime = false) {
        timeModal.classList.add('active');
        timeModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('student-time-modal-open');

        if (focusFirstTime) {
            const firstAvailableTime = timeSlots.find((slot) => !slot.disabled);
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

        if (!revealTimes) {
            return;
        }

        openTimeModal(focusFirstTime);
    }

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

    timeSlots.forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }

            timeSlots.forEach((item) => item.classList.remove('selected'));
            button.classList.add('selected');
            timeInput.value = button.dataset.time;
            const chosenTime = button.querySelector('.student-calendar-time-hour').textContent.trim();
            selectedScheduleText.textContent = formatSelectedDate(dateInput.value) + ' at ' + chosenTime;
            selectedScheduleSummary.hidden = false;
            closeTimeModal();
        });
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
</script>

<?php render_student_footer(); ?>
