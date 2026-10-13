<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

if (student_current_profile() !== null) {
    header('Location: patient-dashboard.php');
    exit;
}

$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);
$contactEmail = (string) ($clinicProfile['contact_email'] ?? '');
$clinicAddress = (string) ($clinicProfile['physical_address'] ?? '');

render_student_auth_header('Welcome');
?>

<main class="student-welcome-wrap">
    <section class="student-welcome-shell" aria-labelledby="welcome-title">
        <header class="student-welcome-header">
            <div class="student-welcome-brand">
                <a href="patient-welcome.php" class="student-brand-mark text-decoration-none" aria-label="<?= student_e($clinicProfile['system_name']) ?> home">
                    <img src="<?= student_e($clinicLogoSrc) ?>" alt="<?= student_e($clinicProfile['department']) ?> logo">
                </a>
                <div>
                    <p class="student-eyebrow mb-1"><?= student_e($clinicProfile['department']) ?></p>
                    <p class="student-welcome-system-name"><?= student_e($clinicProfile['system_name']) ?></p>
                </div>
            </div>
            <?php if ($contactEmail !== ''): ?>
                <a href="mailto:<?= student_e($contactEmail) ?>" class="student-welcome-help">Need help?</a>
            <?php endif; ?>
        </header>

        <div class="student-welcome-hero">
            <div class="student-welcome-intro">
                <p class="student-eyebrow">Secure patient access</p>
                <h1 id="welcome-title"><?= student_e($clinicProfile['system_name']) ?> Patient Portal</h1>
                <p>Review your APE progress, update available health information, and manage clinic appointments from one place.</p>
            </div>

            <aside class="student-welcome-access" aria-labelledby="welcome-access-title">
                <p class="student-eyebrow" id="welcome-access-title">Portal access</p>
                <p class="student-welcome-access-title">Sign in to your clinic record.</p>
                <a href="patient-login.php" class="student-button student-welcome-primary-action">
                    <span>Sign in to the portal</span>
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </a>
                <p class="student-welcome-access-note">For students, faculty, and school personnel with a clinic account.</p>
            </aside>
        </div>

        <section class="student-welcome-services" aria-labelledby="welcome-services-title">
            <div class="student-welcome-services-heading">
                <p class="student-eyebrow" id="welcome-services-title">Inside the portal</p>
                <p>Three essentials, arranged around your clinic access.</p>
            </div>
            <ol class="student-welcome-checklist">
                <li>
                    <span aria-hidden="true">01</span>
                    <div><h2>APE status</h2><p>Follow requested steps and see your annual physical examination progress.</p></div>
                </li>
                <li>
                    <span aria-hidden="true">02</span>
                    <div><h2>Appointments</h2><p>View available clinic appointments when your account has access.</p></div>
                </li>
                <li>
                    <span aria-hidden="true">03</span>
                    <div><h2>Health Passport</h2><p>Keep the information in your clinic record accurate and ready when needed.</p></div>
                </li>
            </ol>
        </section>

        <aside class="student-welcome-contact">
            <p><strong>Need help signing in?</strong><?php if ($contactEmail !== ''): ?> Contact the clinic at <a href="mailto:<?= student_e($contactEmail) ?>"><?= student_e($contactEmail) ?></a>.<?php endif; ?><?php if ($clinicAddress !== ''): ?> Visit <?= student_e($clinicAddress) ?>.<?php endif; ?></p>
        </aside>
    </section>
</main>

<?php render_student_auth_footer(); ?>
