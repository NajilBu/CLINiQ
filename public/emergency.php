<?php

require_once __DIR__ . '/../app/helpers/view.php';

$token = $_GET['token'] ?? '';
$stmt = db()->prepare('SELECT * FROM patients WHERE emergency_token = ? AND token_enabled = 1 LIMIT 1');
$stmt->execute([$token]);
$patient = $stmt->fetch();

if ($patient) {
    $log = db()->prepare('INSERT INTO passport_access_logs (patient_id, ip_address, user_agent) VALUES (?, ?, ?)');
    $log->execute([
        $patient['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

render_header('Emergency Passport');
?>
<div class="passport-page">
    <?php if (!$patient): ?>
        <div class="alert alert-danger">Emergency passport not found or disabled.</div>
    <?php else: ?>
        <div class="passport-card">
            <div class="passport-banner">Emergency Health Passport</div>
            <h1 class="h3 mb-1"><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
            <p class="text-secondary mb-4"><?= e($patient['student_number']) ?> · <?= e($patient['course_section']) ?></p>
            <dl class="row">
                <dt class="col-sm-4">Blood Type</dt>
                <dd class="col-sm-8"><?= e($patient['blood_type']) ?: 'Not specified' ?></dd>
                <dt class="col-sm-4">Allergies</dt>
                <dd class="col-sm-8"><?= nl2br(e($patient['allergies'])) ?: 'None recorded' ?></dd>
                <dt class="col-sm-4">Existing Conditions</dt>
                <dd class="col-sm-8"><?= nl2br(e($patient['existing_conditions'])) ?: 'None recorded' ?></dd>
                <dt class="col-sm-4">Emergency Instructions</dt>
                <dd class="col-sm-8"><?= nl2br(e($patient['emergency_instructions'])) ?: 'None recorded' ?></dd>
                <dt class="col-sm-4">Guardian</dt>
                <dd class="col-sm-8"><?= e($patient['guardian_name']) ?> · <?= e($patient['guardian_contact']) ?></dd>
            </dl>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
