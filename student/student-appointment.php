<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/includes/student-layout.php';

ensure_appointment_schema();

$profile = student_require_login();
$patientId = (int) $profile['patient_id'];
$db = db();

$timeSlots = [
    ['value' => '08:00:00', 'label' => '8:00 AM'],
    ['value' => '09:00:00', 'label' => '9:00 AM'],
    ['value' => '10:00:00', 'label' => '10:00 AM'],
    ['value' => '13:00:00', 'label' => '1:00 PM'],
    ['value' => '14:00:00', 'label' => '2:00 PM'],
    ['value' => '15:00:00', 'label' => '3:00 PM'],
];
$allowedTimes = array_column($timeSlots, 'value');

$month = appointment_month_from_request($_GET['month'] ?? null);
$success = false;
$successMessage = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_appointment') {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $cancellationReason = trim($_POST['cancellation_reason'] ?? '');

    if ($appointmentId <= 0) {
        $error = 'Please choose a valid appointment to cancel.';
    } elseif ($cancellationReason === '') {
        $error = 'Please provide a reason for cancelling the appointment.';
    } else {
        $stmt = $db->prepare("
            UPDATE appointments
            SET status = 'Cancelled', cancellation_reason = ?
            WHERE id = ?
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
    } else {
        $datetimeStr = $dateStr . ' ' . $timeStr;
        $notes = 'Student requested this appointment through the student portal. Awaiting clinic approval.';
        if ($note !== '') {
            $notes .= ' Student note: ' . $note;
        }

        $stmt = $db->prepare("INSERT INTO appointments (patient_id, appointment_datetime, purpose, status, notes) VALUES (?, ?, ?, 'Pending', ?)");
        $stmt->execute([$patientId, $datetimeStr, $type, $notes]);
        $success = true;
        $successMessage = 'Appointment request sent. Please wait for clinic approval before going to the clinic.';
    }
}

$blocksByDate = appointment_blocks_for_month($month);
$studentAppointmentDates = appointment_patient_dates_for_month($patientId, $month);

$availabilityPayload = [];
foreach ($blocksByDate as $date => $blocks) {
    $blockedTimes = [];
    foreach ($timeSlots as $slot) {
        if (appointment_time_is_blocked($date, $slot['value'], $blocksByDate)) {
            $blockedTimes[] = $slot['value'];
        }
    }

    $availabilityPayload[$date] = [
        'fullDay' => appointment_is_full_day_blocked($blocks),
        'blockedTimes' => $blockedTimes,
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
                            $hasAppointment = !empty($studentAppointmentDates[$date]);
                            $isPast = $date < $today;
                            $disabled = $fullDay || $isPast;
                            $classes = ['student-date-btn'];
                            $classes[] = $disabled ? 'disabled' : 'available';
                            if (!$fullDay && !$isPast && !empty($blocks)) {
                                $classes[] = 'partial';
                            }
                            if ($hasAppointment) {
                                $classes[] = 'has-appointment';
                            }
                            ?>
                            <button type="button"
                                    class="<?= student_e(implode(' ', $classes)) ?>"
                                    data-date="<?= student_e($date) ?>"
                                    <?= $disabled ? 'disabled' : '' ?>>
                                <span><?= (int) $day ?></span>
                                <?php if ($hasAppointment): ?>
                                    <small>Booked</small>
                                <?php elseif ($fullDay): ?>
                                    <small>Closed</small>
                                <?php elseif (!$isPast && !empty($blocks)): ?>
                                    <small>Limited</small>
                                <?php endif; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="student-field">
                    <label class="student-label">Preferred Time</label>
                    <div class="student-time-grid" id="time-slots">
                        <?php foreach ($timeSlots as $slot): ?>
                            <button type="button" class="student-time-slot" data-time="<?= student_e($slot['value']) ?>">
                                <?= student_e($slot['label']) ?>
                            </button>
                        <?php endforeach; ?>
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
                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
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
    const timeSlots = Array.from(document.querySelectorAll('.student-time-slot'));

    function updateTimeSlots(date) {
        const blockedTimes = availability[date]?.blockedTimes || [];

        timeSlots.forEach((slot) => {
            const isBlocked = blockedTimes.includes(slot.dataset.time);
            slot.classList.toggle('disabled', isBlocked);
            slot.disabled = isBlocked;
            slot.title = isBlocked ? 'This time is unavailable' : '';

            if (isBlocked && slot.classList.contains('selected')) {
                slot.classList.remove('selected');
                timeInput.value = '';
            }
        });
    }

    document.querySelectorAll('.student-date-btn:not(.disabled)').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.student-date-btn').forEach((item) => item.classList.remove('selected'));
            button.classList.add('selected');
            dateInput.value = button.dataset.date;
            updateTimeSlots(button.dataset.date);
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
        });
    });

    document.getElementById('booking-form').addEventListener('submit', function (event) {
        if (!dateInput.value || !timeInput.value) {
            event.preventDefault();
            alert('Please select an available date and time.');
        }
    });
</script>

<?php render_student_footer(); ?>
