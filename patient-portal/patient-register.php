<?php

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/PatientRegistrationService.php';

student_start_session();
if (student_current_profile() !== null) {
    header('Location: patient-dashboard.php');
    exit;
}
if (isset($_GET['restart'])) {
    unset($_SESSION['patient_registration']);
    csrf_rotate_token();
    header('Location: patient-register.php');
    exit;
}

$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);
$programOptions = patient_account_active_programs();
$context = is_array($_SESSION['patient_registration'] ?? null) ? $_SESSION['patient_registration'] : [];
$step = in_array($context['step'] ?? '', ['verify', 'details'], true) ? (string) $context['step'] : 'identity';
$error = '';
$values = [];

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
            $_SESSION['patient_registration'] = $verified + ['step' => 'details'];
        } elseif ($action === 'complete_registration' && !empty($context['verification_id']) && $step === 'details') {
            $values = $_POST;
            complete_patient_registration((int) $context['verification_id'], $_POST);
            unset($_SESSION['patient_registration']);
            csrf_rotate_token();
            header('Location: patient-login.php?registered=1');
            exit;
        } else {
            throw new RuntimeException('Registration session expired. Start again.');
        }
        header('Location: patient-register.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $context = is_array($_SESSION['patient_registration'] ?? null) ? $_SESSION['patient_registration'] : $context;
        $step = in_array($context['step'] ?? '', ['verify', 'details'], true) ? (string) $context['step'] : 'identity';
    }
}

render_student_auth_header('Create Student Account');
render_cliniq_entry_header(['homeUrl' => 'patient-login.php', 'logoUrl' => $clinicLogoSrc]);
?>
<main class="student-auth-wrap"><section class="student-auth-shell">
<aside class="student-auth-side"><div><a href="patient-login.php" class="student-brand-mark text-decoration-none" aria-label="Return to patient login"><img src="<?= student_e($clinicLogoSrc) ?>" alt="<?= student_e($clinicProfile['department']) ?> logo"></a><p class="student-auth-brand-line"><?= student_e($clinicProfile['system_name']) ?></p><h1 class="student-auth-side-title">Student<br>Registration</h1><p class="student-auth-side-copy">Verify your email, create your profile, and begin your APE requirements.</p><svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/></svg></div><p class="student-auth-side-footnote">New accounts begin with Applicant access</p></aside>
<div class="student-auth-form-side">
<p class="student-eyebrow">Step <?= $step === 'identity' ? '1' : ($step === 'verify' ? '2' : '3') ?> of 3</p>
<h2 class="student-card-title text-xl"><?= $step === 'identity' ? 'Verify your student identity' : ($step === 'verify' ? 'Confirm your email' : 'Complete your profile') ?></h2>
<p class="student-card-copy mb-5"><?= $step === 'identity' ? 'Enter your student number and email twice before continuing.' : ($step === 'verify' ? 'Enter the six-digit code sent to ' . student_e((string) ($context['email'] ?? 'your email')) . '.' : 'Your email is verified. Complete your details to create an Applicant account.') ?></p>
<?php if ($error !== ''): ?><div class="student-note student-note-danger mb-4" role="alert"><span class="material-symbols-outlined">error</span><div><?= student_e($error) ?></div></div><?php endif; ?>
<?php if ($step === 'identity'): ?>
<form method="post" class="space-y-4"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="request_code">
<div class="student-field"><label class="student-label" for="student_number">Student Number</label><input class="student-input" id="student_number" name="student_number" placeholder="23-00262" autocomplete="username" data-id-number-format value="<?= student_e((string) ($_POST['student_number'] ?? '')) ?>" required></div>
<div class="student-field"><label class="student-label" for="registration_email">Institutional Email Address</label><input class="student-input" id="registration_email" name="email" type="email" autocomplete="email" maxlength="160" pattern="[^@\s]+@plpasig\.edu\.ph" title="Use your @plpasig.edu.ph email address." value="<?= student_e((string) ($_POST['email'] ?? '')) ?>" required></div>
<div class="student-field"><label class="student-label" for="email_confirmation">Confirm Email Address</label><input class="student-input" id="email_confirmation" name="email_confirmation" type="email" autocomplete="email" maxlength="160" pattern="[^@\s]+@plpasig\.edu\.ph" title="Enter the same @plpasig.edu.ph email address." value="<?= student_e((string) ($_POST['email_confirmation'] ?? '')) ?>" required></div>
<button class="student-button w-full" type="submit">Send Verification Code <span class="material-symbols-outlined">mail</span></button></form>
<?php elseif ($step === 'verify'): ?>
<form method="post" class="space-y-4"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="verify_code"><div class="student-field"><label class="student-label" for="verification_code">Verification Code</label><input class="student-input text-center tracking-[0.35em] text-xl" id="verification_code" name="verification_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div><button class="student-button w-full" type="submit">Verify Email <span class="material-symbols-outlined">verified</span></button></form>
<div class="flex items-center justify-between gap-3 mt-4 text-xs font-bold"><form method="post"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="resend_code"><button class="student-auth-link" type="submit">Resend code</button></form><a class="student-auth-link text-decoration-none" href="patient-register.php?restart=1">Use another email</a></div>
<?php else: ?>
<form method="post" class="space-y-4"><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="complete_registration"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
<div class="student-field"><label class="student-label" for="first_name">First Name</label><input class="student-input" id="first_name" name="first_name" maxlength="100" data-person-name value="<?= student_e((string) ($values['first_name'] ?? '')) ?>" required></div><div class="student-field"><label class="student-label" for="middle_name">Middle Name (optional)</label><input class="student-input" id="middle_name" name="middle_name" maxlength="100" data-person-name value="<?= student_e((string) ($values['middle_name'] ?? '')) ?>"></div><div class="student-field"><label class="student-label" for="last_name">Last Name</label><input class="student-input" id="last_name" name="last_name" maxlength="100" data-person-name value="<?= student_e((string) ($values['last_name'] ?? '')) ?>" required></div><div class="student-field"><label class="student-label" for="birthdate">Birthdate</label><input class="student-input" id="birthdate" name="birthdate" type="date" min="<?= date('Y-m-d', strtotime('-120 years')) ?>" max="<?= date('Y-m-d') ?>" value="<?= student_e((string) ($values['birthdate'] ?? '')) ?>" required></div>
<div class="student-field"><label class="student-label" for="sex">Recorded Sex</label><select class="student-input" id="sex" name="sex" required><option value="">Select</option><?php foreach (['Male', 'Female', 'Other'] as $option): ?><option value="<?= $option ?>" <?= ($values['sex'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div><div class="student-field"><label class="student-label" for="program_code">Program</label><select class="student-input" id="program_code" name="program_code" required><option value="">Select program</option><?php foreach ($programOptions as $program): ?><option value="<?= student_e((string) $program['code']) ?>" <?= ($values['program_code'] ?? '') === $program['code'] ? 'selected' : '' ?>><?= student_e((string) $program['code'] . ' — ' . (string) $program['name']) ?></option><?php endforeach; ?></select></div>
<div class="student-field"><label class="student-label" for="year_level">Year Level</label><select class="student-input" id="year_level" name="year_level" required><option value="">Select</option><?php foreach (['1', '2', '3', '4'] as $option): ?><option value="<?= $option ?>" <?= ($values['year_level'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div><div class="student-field"><label class="student-label" for="section">Section</label><select class="student-input" id="section" name="section" required><option value="">Select</option><?php foreach (['A', 'B', 'C', 'D', 'E'] as $option): ?><option value="<?= $option ?>" <?= ($values['section'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div>
<div class="student-field"><label class="student-label" for="registration_password">Password</label><div class="relative"><input class="student-input pr-14" id="registration_password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="128" title="Use uppercase, lowercase, number, and special character." required><button type="button" class="student-toggle-pw" data-target="registration_password">Show</button></div><p class="text-xs font-bold text-slate-500 mt-1">8–128 characters with uppercase, lowercase, number, and special character.</p></div><div class="student-field"><label class="student-label" for="password_confirmation">Confirm Password</label><div class="relative"><input class="student-input pr-14" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128" required><button type="button" class="student-toggle-pw" data-target="password_confirmation">Show</button></div></div></div>
<div class="student-note"><span class="material-symbols-outlined">info</span><div>Your account starts as Applicant. Passport and appointment access unlock after final APE clearance.</div></div><button class="student-button w-full" type="submit">Create Applicant Account <span class="material-symbols-outlined">person_add</span></button></form>
<?php endif; ?>
<hr class="student-auth-divider"><p class="text-center text-xs font-bold text-slate-500">Already registered? <a href="patient-login.php" class="student-auth-link text-decoration-none">Back to login.</a></p>
</div></section></main>
<script>
document.querySelectorAll('.student-toggle-pw').forEach((button)=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.target);if(!input)return;input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Show':'Hide';}));
document.getElementById('verification_code')?.addEventListener('input',(event)=>{event.target.value=event.target.value.replace(/\D/g,'').slice(0,6);});
document.querySelectorAll('[data-person-name]').forEach((input)=>input.addEventListener('input',()=>{input.setCustomValidity(input.value&&!/^[\p{L} .'-]+$/u.test(input.value)?'Use only letters, spaces, apostrophes, periods, and hyphens.':'');}));
const registrationEmail=document.getElementById('registration_email');const emailConfirmation=document.getElementById('email_confirmation');
const validateEmailMatch=()=>{if(!emailConfirmation)return;emailConfirmation.setCustomValidity(emailConfirmation.value&&registrationEmail?.value.toLowerCase()!==emailConfirmation.value.toLowerCase()?'Email addresses do not match.':'');};registrationEmail?.addEventListener('input',validateEmailMatch);emailConfirmation?.addEventListener('input',validateEmailMatch);
const registrationPassword=document.getElementById('registration_password');const passwordConfirmation=document.getElementById('password_confirmation');
const validatePasswords=()=>{if(!registrationPassword||!passwordConfirmation)return true;const strong=registrationPassword.value.length>=8&&registrationPassword.value.length<=128&&/[a-z]/.test(registrationPassword.value)&&/[A-Z]/.test(registrationPassword.value)&&/\d/.test(registrationPassword.value)&&/[^A-Za-z0-9]/.test(registrationPassword.value);registrationPassword.setCustomValidity(registrationPassword.value&&!strong?'Use 8–128 characters with uppercase, lowercase, number, and special character.':'');passwordConfirmation.setCustomValidity(passwordConfirmation.value&&registrationPassword.value!==passwordConfirmation.value?'Passwords do not match.':'');return strong&&registrationPassword.value===passwordConfirmation.value;};registrationPassword?.addEventListener('input',validatePasswords);passwordConfirmation?.addEventListener('input',validatePasswords);
</script>
<?php render_student_auth_footer(); ?>
