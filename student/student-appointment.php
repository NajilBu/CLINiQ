<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/includes/student-layout.php';

ensure_appointment_schema();

$db = db();
$db->exec("INSERT IGNORE INTO patients (id, student_number, first_name, last_name, emergency_token) VALUES (1, '23-00456', 'Juan', 'dela Cruz', 'dummy-token-123')");

$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appt_type'], $_POST['appt_date'], $_POST['appt_time'])) {
    $type = trim($_POST['appt_type']);
    $dateStr = trim($_POST['appt_date']);
    $timeStr = trim($_POST['appt_time']);
    $note = trim($_POST['appt_note'] ?? '');
    $datetimeStr = date('Y-m-d H:i:s', strtotime("$dateStr $timeStr"));
    $notes = 'Student requested this appointment through the student portal. Awaiting clinic approval.';
    if ($note !== '') {
        $notes .= ' Student note: ' . $note;
    }

    $stmt = $db->prepare("INSERT INTO appointments (patient_id, appointment_datetime, purpose, status, notes) VALUES (1, ?, ?, 'Pending', ?)");
    $stmt->execute([$datetimeStr, $type, $notes]);
    $success = true;
}

$historyStmt = $db->prepare("
    SELECT *
    FROM appointments
    WHERE patient_id = 1
    ORDER BY appointment_datetime DESC, created_at DESC
    LIMIT 5
");
$historyStmt->execute();
$appointments = $historyStmt->fetchAll();

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
            <strong>Appointment request sent.</strong>
            Please wait for clinic approval before going to the clinic.
        </div>
    </div>
<?php endif; ?>

<div class="student-grid">
    <section class="student-card student-span-7">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Preferred Schedule</h2>
                <p class="student-card-copy">This creates a pending request for clinic staff.</p>
            </div>
            <span class="student-badge student-badge-warning">Pending First</span>
        </div>
        <div class="student-card-pad">
            <form id="booking-form" method="POST" action="">
                <input type="hidden" name="appt_date" id="appt-date-input" value="">
                <input type="hidden" name="appt_time" id="appt-time-input" value="">

                <div class="student-field">
                    <label class="student-label" for="appt-type">Appointment Purpose</label>
                    <select id="appt-type" name="appt_type" class="student-select" required>
                        <option value="" disabled selected>Select appointment purpose...</option>
                        <option value="General Checkup">General Checkup</option>
                        <option value="Dental Consultation">Dental Consultation</option>
                        <option value="Medical Consultation">Medical Consultation</option>
                        <option value="APE Follow-up">APE Follow-up</option>
                        <option value="Clearance Submission">Clearance Submission</option>
                    </select>
                </div>

                <div class="student-field">
                    <label class="student-label">Preferred Date</label>
                    <div class="student-calendar-grid mb-2 text-center">
                        <span class="text-[10px] font-black text-slate-400 uppercase">Su</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase">Mo</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase">Tu</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase">We</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase">Th</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase">Fr</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase">Sa</span>
                    </div>
                    <div class="student-calendar-grid" id="calendar-grid">
                        <button type="button" class="student-date-btn disabled">5</button>
                        <button type="button" class="student-date-btn disabled">6</button>
                        <button type="button" class="student-date-btn disabled">7</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 8, 2026')">8</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 9, 2026')">9</button>
                        <button type="button" class="student-date-btn disabled">10</button>
                        <button type="button" class="student-date-btn disabled">11</button>
                        <button type="button" class="student-date-btn disabled">12</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 13, 2026')">13</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 14, 2026')">14</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 15, 2026')">15</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 16, 2026')">16</button>
                        <button type="button" class="student-date-btn available" onclick="selectDate(this, 'July 17, 2026')">17</button>
                        <button type="button" class="student-date-btn disabled">18</button>
                    </div>
                </div>

                <div class="student-field">
                    <label class="student-label">Preferred Time</label>
                    <div class="student-time-grid" id="time-slots">
                        <button type="button" class="student-time-slot" onclick="selectTime(this, '8:00 AM')">8:00 AM</button>
                        <button type="button" class="student-time-slot" onclick="selectTime(this, '9:00 AM')">9:00 AM</button>
                        <button type="button" class="student-time-slot" onclick="selectTime(this, '10:00 AM')">10:00 AM</button>
                        <button type="button" class="student-time-slot" onclick="selectTime(this, '1:00 PM')">1:00 PM</button>
                        <button type="button" class="student-time-slot" onclick="selectTime(this, '2:00 PM')">2:00 PM</button>
                        <button type="button" class="student-time-slot" onclick="selectTime(this, '3:00 PM')">3:00 PM</button>
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
                <h2 class="student-card-title">Request Status</h2>
                <p class="student-card-copy">How clinic approval works</p>
            </div>
            <span class="student-badge student-badge-info">Guide</span>
        </div>
        <div class="student-card-pad">
            <div class="student-progress-list mb-4">
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">send</span>
                    <div>
                        <strong>Request sent</strong>
                        <span>Your selected date and time are submitted.</span>
                    </div>
                    <span class="student-badge student-badge-success">You</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">approval</span>
                    <div>
                        <strong>Clinic approval</strong>
                        <span>Clinic staff approves, adjusts, or cancels.</span>
                    </div>
                    <span class="student-badge student-badge-warning">Clinic</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">event_available</span>
                    <div>
                        <strong>Scheduled visit</strong>
                        <span>Go only once your status is scheduled.</span>
                    </div>
                    <span class="student-badge student-badge-info">Final</span>
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
                    </div>
                    <span class="student-badge <?= student_e($badge) ?>"><?= student_e($status === 'Pending' ? 'Awaiting Clinic' : $status) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<script>
    function selectDate(button, value) {
        document.querySelectorAll('.student-date-btn').forEach((item) => item.classList.remove('selected'));
        button.classList.add('selected');
        document.getElementById('appt-date-input').value = value;
    }

    function selectTime(button, value) {
        document.querySelectorAll('.student-time-slot').forEach((item) => item.classList.remove('selected'));
        button.classList.add('selected');
        document.getElementById('appt-time-input').value = value;
    }

    document.getElementById('booking-form').addEventListener('submit', function (event) {
        if (!document.getElementById('appt-date-input').value || !document.getElementById('appt-time-input').value) {
            event.preventDefault();
            alert('Please select a preferred date and time.');
        }
    });
</script>

<?php render_student_footer(); ?>
