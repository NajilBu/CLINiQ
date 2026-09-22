<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

student_start_session();
if (student_current_profile() !== null) {
    header('Location: patient-dashboard.php');
    exit;
}
if (isset($_GET['start']) || isset($_GET['restart'])) {
    unset($_SESSION['patient_registration'], $_SESSION['patient_onboarding']);
    csrf_rotate_token();
    header('Location: patient-register.php');
    exit;
}
student_redirect_pending_onboarding();

$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);
$context = is_array($_SESSION['patient_registration'] ?? null) ? $_SESSION['patient_registration'] : [];
$step = ($context['step'] ?? '') === 'verify' ? 'verify' : 'identity';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce_request();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'request_code') {
            $requested = request_patient_registration_code((string) ($_POST['student_number'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['email_confirmation'] ?? ''), auth_request_ip());
            $_SESSION['patient_registration'] = $requested + ['step' => 'verify'];
        } elseif ($action === 'resend_code' && !empty($context['student_number']) && !empty($context['email'])) {
            $requested = request_patient_registration_code((string) $context['student_number'], (string) $context['email'], (string) $context['email'], auth_request_ip());
            $_SESSION['patient_registration'] = $requested + ['step' => 'verify'];
        } elseif ($action === 'verify_code' && !empty($context['verification_id'])) {
            $verified = verify_patient_registration_code((int) $context['verification_id'], (string) ($_POST['verification_code'] ?? ''));
            student_begin_verified_onboarding($verified);
            header('Location: patient-onboarding.php');
            exit;
        } else {
            throw new RuntimeException('Registration session expired. Start again.');
        }
        header('Location: patient-register.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException
            ? ((int) ($exception->errorInfo[1] ?? 0) === 1062 ? 'This student number or email is already registered. Sign in or use password recovery.' : 'Registration could not be completed. Please try again.')
            : $exception->getMessage();
        if ($exception instanceof PDOException) {
            error_log('[CLINiQ Registration] Database error: ' . $exception->getMessage());
        }
        $context = is_array($_SESSION['patient_registration'] ?? null) ? $_SESSION['patient_registration'] : $context;
        $step = ($context['step'] ?? '') === 'verify' ? 'verify' : 'identity';
    }
}

render_student_auth_header('Create Student Account');
render_cliniq_entry_header([
    'homeUrl' => 'patient-login.php',
    'logoUrl' => $clinicLogoSrc,
    'class' => 'cliniq-entry-header-mobile-hidden cliniq-entry-header-registration',
]);
?>
<main class="student-auth-wrap student-register-wrap"><section class="student-auth-shell student-registration-shell">
<aside class="student-auth-side"><div><a href="patient-login.php" class="student-brand-mark text-decoration-none" aria-label="Return to patient login"><img src="<?= student_e($clinicLogoSrc) ?>" alt="<?= student_e($clinicProfile['department']) ?> logo"></a><p class="student-auth-brand-line"><?= student_e($clinicProfile['system_name']) ?></p><h1 class="student-auth-side-title">Student<br>Registration</h1><p class="student-auth-side-copy">Verify your email, create your profile, and begin your APE requirements.</p><svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/></svg></div><p class="student-auth-side-footnote">New accounts begin with Applicant access</p></aside>
<div class="student-auth-form-side">
<p class="student-eyebrow">Step <?= $step === 'identity' ? '1' : '2' ?> of 3</p>
<h2 class="student-card-title text-xl"><?= $step === 'identity' ? 'Verify your student identity' : 'Confirm your email' ?></h2>
<p class="student-card-copy mb-5"><?= $step === 'identity' ? 'Enter your student number and email twice before continuing.' : 'Enter the six-digit code sent to ' . student_e((string) ($context['email'] ?? 'your email')) . '.' ?></p>
<?php if ($error !== ''): ?><div class="student-note student-note-danger mb-4" role="alert"><span class="material-symbols-outlined">error</span><div><?= student_e($error) ?></div></div><?php endif; ?>
<?php if ($step === 'identity'): ?>
<form method="post" class="space-y-4"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="request_code">
<div class="student-field"><label class="student-label" for="student_number">Student Number</label><input class="student-input" id="student_number" name="student_number" placeholder="23-00262" autocomplete="username" data-id-number-format value="<?= student_e((string) ($_POST['student_number'] ?? '')) ?>" required></div>
<div class="student-field"><label class="student-label" for="registration_email">Institutional Email Address</label><input class="student-input" id="registration_email" name="email" type="email" autocomplete="email" maxlength="160" pattern="[^@\s]+@plpasig\.edu\.ph" title="Use your @plpasig.edu.ph email address." value="<?= student_e((string) ($_POST['email'] ?? '')) ?>" required></div>
<div class="student-field"><label class="student-label" for="email_confirmation">Confirm Email Address</label><input class="student-input" id="email_confirmation" name="email_confirmation" type="email" autocomplete="email" maxlength="160" pattern="[^@\s]+@plpasig\.edu\.ph" title="Enter the same @plpasig.edu.ph email address." value="<?= student_e((string) ($_POST['email_confirmation'] ?? '')) ?>" required></div>
<button class="student-button w-full" type="submit">Send Verification Code <span class="material-symbols-outlined">mail</span></button></form>
<?php else: ?>
<form method="post" class="space-y-4"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="verify_code"><div class="student-field"><label class="student-label" for="verification_code">Verification Code</label><input class="student-input text-center tracking-[0.35em] text-xl" id="verification_code" name="verification_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div><button class="student-button w-full" type="submit">Verify Email <span class="material-symbols-outlined">verified</span></button></form>
<div class="flex items-center justify-between gap-3 mt-4 text-xs font-bold"><form method="post"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="resend_code"><button class="student-auth-link" type="submit">Resend code</button></form><a class="student-auth-link text-decoration-none" href="patient-register.php?restart=1">Use another email</a></div>
<?php endif; ?>
<hr class="student-auth-divider"><p class="text-center text-xs font-bold text-slate-500">Already registered? <a href="patient-login.php" class="student-auth-link text-decoration-none">Back to login.</a></p>
</div></section></main>
<script>
document.getElementById('verification_code')?.addEventListener('input',(event)=>{event.target.value=event.target.value.replace(/\D/g,'').slice(0,6);});
const registrationEmail=document.getElementById('registration_email');const emailConfirmation=document.getElementById('email_confirmation');const validateEmailMatch=()=>{if(!emailConfirmation)return;emailConfirmation.setCustomValidity(emailConfirmation.value&&registrationEmail?.value.toLowerCase()!==emailConfirmation.value.toLowerCase()?'Email addresses do not match.':'');};registrationEmail?.addEventListener('input',validateEmailMatch);emailConfirmation?.addEventListener('input',validateEmailMatch);
</script>
<?php render_student_auth_footer(); ?>
