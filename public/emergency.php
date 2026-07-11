<?php

require_once __DIR__ . '/../app/helpers/view.php';

$token = $_GET['token'] ?? '';
$stmt = db()->prepare('SELECT * FROM patients WHERE emergency_token = ? AND token_enabled = 1 LIMIT 1');
$stmt->execute([$token]);
$patient = $stmt->fetch();
$message = null;
$error = null;

if ($patient) {
    $log = db()->prepare('INSERT INTO passport_access_logs (patient_id, ip_address, user_agent) VALUES (?, ?, ?)');
    $log->execute([
        $patient['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patient) {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $location = trim($_POST['location'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $reporterName = trim($_POST['reporter_name'] ?? '');
    $reporterContact = trim($_POST['reporter_contact'] ?? '');

    if ($location === '') {
        $error = 'Please enter the reported location so the clinic knows where to respond.';
    } else {
        $rate = db()->prepare("
            SELECT COUNT(*) AS total
            FROM incident_reports
            WHERE patient_id = ?
              AND ip_address <=> ?
              AND reported_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $rate->execute([$patient['id'], $ipAddress]);
        $recentReports = (int) ($rate->fetch()['total'] ?? 0);

        if ($recentReports >= 3) {
            $error = 'Too many reports were sent recently for this tag. Please call the clinic directly if this is urgent.';
        } else {
            $incident = db()->prepare("
                INSERT INTO incident_reports
                    (patient_id, emergency_token, reporter_name, reporter_contact, location, notes, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $incident->execute([
                $patient['id'],
                $token,
                $reporterName ?: null,
                $reporterContact ?: null,
                $location,
                $notes ?: null,
                $ipAddress,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $details = trim(implode("\n", array_filter([
                'Reported from QR/NFC emergency tag.',
                $reporterContact ? 'Reporter contact: ' . $reporterContact : null,
                $notes ? 'Notes: ' . $notes : null,
            ])));

            $alert = db()->prepare("
                INSERT INTO nurse_alerts
                    (patient_id, reporter_name, reporter_role, location, concern, details, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $alert->execute([
                $patient['id'],
                $reporterName ?: 'QR/NFC scanner',
                'Emergency passport scanner',
                $location,
                'Possible accident reported from student ID',
                $details ?: null,
            ]);

            $message = 'The clinic has been notified. Please stay with the student and call the clinic directly if the situation is urgent.';
        }
    }
}

render_header('Emergency QR/NFC Response');
?>
<div class="passport-page">
    <?php if (!$patient): ?>
        <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">Emergency tag not found or
            disabled.</div>
    <?php else: ?>
        <div class="clinic-card w-full max-w-2xl overflow-hidden">
            <div class="bg-red-700 text-white px-6 py-4 font-black">Emergency QR/NFC Response</div>
            <div class="p-6 md:p-8">
                <p class="clinic-label">Student Emergency Tag</p>
                <h1 class="font-headline text-3xl font-extrabold text-[#1c2a59] mb-2">Possible emergency?</h1>
                <p class="text-sm font-bold text-slate-500 mb-6">
                    This page notifies the clinic. Private health details are visible only to authorized clinic staff.
                </p>

                <?php if ($message): ?>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-700 px-5 py-4 font-bold mb-4">
                        <?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-4">
                        <?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="clinic-label">Reported Location</label>
                        <input class="clinic-input" name="location" required
                            placeholder="Example: Gymnasium, Room 204, gate area">
                    </div>
                    <div>
                        <label class="clinic-label">Reporter Name</label>
                        <input class="clinic-input" name="reporter_name" placeholder="Optional">
                    </div>
                    <div>
                        <label class="clinic-label">Reporter Contact</label>
                        <input class="clinic-input" name="reporter_contact" placeholder="Optional phone number">
                    </div>
                    <div class="md:col-span-2">
                        <label class="clinic-label">Notes</label>
                        <textarea class="clinic-textarea" name="notes" rows="4"
                            placeholder="What happened? What does the student need?"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <button
                            class="w-full px-5 py-3 bg-red-600 text-white rounded-2xl text-sm font-black shadow-lg hover:bg-red-700" data-confirm-submit data-confirm-type="danger" data-confirm-title="Submit this emergency report?" data-confirm-message="This will send the possible accident report to the clinic response queue." data-confirm-toast="Submitting emergency report...">Report
                            Possible Accident to Clinic</button>
                    </div>
                </form>

                <p class="text-xs font-bold text-slate-500 mt-6 mb-0">
                    If this is urgent, call the clinic or school emergency contact immediately after submitting the report.
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>
