<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

student_start_session();
if (student_current_profile() !== null) {
    header('Location: patient-dashboard.php');
    exit;
}

$context = student_verified_onboarding_context();
if ($context === null) {
    unset($_SESSION['patient_onboarding'], $_SESSION['patient_registration']);
    csrf_rotate_token();
    header('Location: patient-register.php?start=1');
    exit;
}

$programOptions = patient_account_active_programs();
$error = '';
$values = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce_request();
    if (($_POST['action'] ?? '') === 'complete_registration') {
        $values = $_POST;
        try {
            $context = student_verified_onboarding_context();
            if ($context === null) {
                throw new RuntimeException('Your verified setup session has expired. Start registration again.');
            }
            $registration = complete_patient_registration((int) $context['verification_id'], $values);
            student_upgrade_verified_onboarding_session($registration);
            audit_log_event('auth', 'patient_self_registration_auto_login', (int) $registration['person_id'], 'student', 'person', (int) $registration['person_id']);
            header('Location: patient-dashboard.php?activated=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            if (student_verified_onboarding_context() === null) {
                unset($_SESSION['patient_onboarding'], $_SESSION['patient_registration']);
            }
        }
    }
}

$clinicProfile = clinic_profile_settings();
$clinicLogoSrc = student_public_logo_src($clinicProfile);
render_student_auth_header('Complete Profile');
?>
<div class="student-shell">
    <header class="student-topbar student-onboarding-topbar">
        <a href="patient-onboarding.php" class="student-brand text-decoration-none" aria-label="Profile setup">
            <span class="student-brand-mark"><img src="<?= student_e($clinicLogoSrc) ?>" alt="<?= student_e($clinicProfile['department']) ?> logo"></span>
            <span class="student-brand-copy"><span class="student-brand-title"><?= student_e($clinicProfile['system_name']) ?></span><span class="student-brand-subtitle">Patient Health Portal</span></span>
        </a>
        <a class="student-onboarding-start-over text-decoration-none" href="patient-register.php?restart=1">
            <span class="material-symbols-outlined" aria-hidden="true">restart_alt</span>
            <span>Start over</span>
        </a>
    </header>
    <main class="student-auth-wrap"><section class="student-auth-shell student-onboarding-shell">
                        <aside class="student-auth-side"><div><span class="student-brand-mark"><img src="<?= student_e($clinicLogoSrc) ?>" alt=""></span><p class="student-auth-brand-line"><?= student_e($clinicProfile['system_name']) ?></p><h1 class="student-auth-side-title">Complete your<br>profile</h1><p class="student-auth-side-copy">Your verified setup account is active in this browser session. Complete your profile to continue to the portal.</p></div><p class="student-auth-side-footnote">Clinical records remain unavailable until setup is complete</p></aside>
        <div class="student-auth-form-side">
            <p class="student-eyebrow">Verified Setup Account · Step 3 of 3</p><h2 class="student-card-title text-xl">Complete your profile</h2><p class="student-card-copy mb-5">Your setup account is signed in and ready. Finish your profile in this browser session to create your Applicant account.</p>
            <div class="student-note student-onboarding-identity mb-4"><span class="material-symbols-outlined">verified</span><div><strong><?= student_e((string) $context['student_number']) ?></strong><br><?= student_e((string) $context['email']) ?></div></div>
            <?php if ($error !== ''): ?><div class="student-note student-note-danger mb-4" role="alert"><span class="material-symbols-outlined">error</span><div><?= student_e($error) ?></div></div><?php endif; ?>
            <div class="student-onboarding-progress" data-onboarding-progress aria-label="Profile setup progress">
                <span class="student-onboarding-progress-step active" data-progress-step="0"><b>1</b><span>Personal</span></span>
                <span class="student-onboarding-progress-line" aria-hidden="true"></span>
                <span class="student-onboarding-progress-step" data-progress-step="1"><b>2</b><span>Academic</span></span>
                <span class="student-onboarding-progress-line" aria-hidden="true"></span>
                <span class="student-onboarding-progress-step" data-progress-step="2"><b>3</b><span>Security</span></span>
            </div>
            <form method="post" class="space-y-4" data-onboarding-form><input type="hidden" name="_csrf" value="<?= student_e(csrf_token()) ?>"><input type="hidden" name="action" value="complete_registration"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="student-field"><label class="student-label" for="first_name">First Name</label><input class="student-input" id="first_name" name="first_name" maxlength="100" autocomplete="given-name" data-person-name value="<?= student_e((string) ($values['first_name'] ?? '')) ?>" required></div><div class="student-field"><label class="student-label" for="middle_name">Middle Name (optional)</label><input class="student-input" id="middle_name" name="middle_name" maxlength="100" autocomplete="additional-name" data-person-name value="<?= student_e((string) ($values['middle_name'] ?? '')) ?>"></div><div class="student-field"><label class="student-label" for="last_name">Last Name</label><input class="student-input" id="last_name" name="last_name" maxlength="100" autocomplete="family-name" data-person-name value="<?= student_e((string) ($values['last_name'] ?? '')) ?>" required></div><div class="student-field"><label class="student-label" for="birthdate">Birthdate</label><input class="student-input" id="birthdate" name="birthdate" type="date" autocomplete="bday" min="<?= date('Y-m-d', strtotime('-120 years')) ?>" max="<?= date('Y-m-d') ?>" value="<?= student_e((string) ($values['birthdate'] ?? '')) ?>" required></div>
                <div class="student-field"><label class="student-label" for="sex">Recorded Sex</label><select class="student-input" id="sex" name="sex" required><option value="">Select</option><?php foreach (['Male', 'Female', 'Other'] as $option): ?><option value="<?= $option ?>" <?= ($values['sex'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div><div class="student-field"><label class="student-label" for="program_code">Program</label><select class="student-input" id="program_code" name="program_code" required><option value="">Select program</option><?php foreach ($programOptions as $program): ?><option value="<?= student_e((string) $program['code']) ?>" <?= ($values['program_code'] ?? '') === $program['code'] ? 'selected' : '' ?>><?= student_e((string) $program['code'] . ' — ' . (string) $program['name']) ?></option><?php endforeach; ?></select></div>
                <div class="student-field"><label class="student-label" for="year_level">Year Level</label><select class="student-input" id="year_level" name="year_level" required><option value="">Select</option><?php foreach (['1', '2', '3', '4'] as $option): ?><option value="<?= $option ?>" <?= ($values['year_level'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div><div class="student-field"><label class="student-label" for="section">Section</label><select class="student-input" id="section" name="section" required><option value="">Select</option><?php foreach (['A', 'B', 'C', 'D', 'E'] as $option): ?><option value="<?= $option ?>" <?= ($values['section'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div>
                <div class="student-field"><label class="student-label" for="registration_password">Password</label><div class="relative"><input class="student-input pr-14" id="registration_password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="128" title="Use uppercase, lowercase, number, and special character." required><button type="button" class="student-toggle-pw" data-target="registration_password">Show</button></div><p class="text-xs font-bold text-slate-500 mt-1">8–128 characters with uppercase, lowercase, number, and special character.</p></div><div class="student-field"><label class="student-label" for="password_confirmation">Confirm Password</label><div class="relative"><input class="student-input pr-14" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128" required><button type="button" class="student-toggle-pw" data-target="password_confirmation">Show</button></div></div>
            </div><div class="student-note student-onboarding-account-note"><span class="material-symbols-outlined">info</span><div>Your normal Applicant account will be created after setup. Passport and appointment access unlock after final APE clearance.</div></div><label class="student-onboarding-legal flex items-start gap-3 text-xs font-bold text-slate-600 mt-4"><input class="mt-0.5" type="checkbox" name="legal_acknowledgement" value="1" required><span>I have read and acknowledge the <a href="<?= student_e(student_legal_url('terms')) ?>" target="_blank" rel="noopener" class="student-auth-link">Terms of Use</a> and <a href="<?= student_e(student_legal_url('privacy')) ?>" target="_blank" rel="noopener" class="student-auth-link">Privacy Notice</a>, including how CLINiQ processes health information.</span></label><button class="student-button w-full" type="submit">Create Applicant Account <span class="material-symbols-outlined">person_add</span></button></form>
        </div>
    </section></main>
</div>
<script>
document.querySelectorAll('.student-toggle-pw').forEach((button)=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.target);if(!input)return;input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Show':'Hide';}));
  document.querySelectorAll('[data-person-name]').forEach((input)=>input.addEventListener('input',()=>{input.setCustomValidity(input.value&&!/^[\p{L} .'-]+$/u.test(input.value)?'Use only letters, spaces, apostrophes, periods, and hyphens.':'');}));
  const onboardingForm=document.querySelector('[data-onboarding-form]');
  if(onboardingForm){
    const grid=onboardingForm.querySelector('.grid');
    const fields=grid?[...grid.querySelectorAll(':scope > .student-field')]:[];
    const steps=[fields.slice(0,5),fields.slice(5,8),fields.slice(8)];
    const finalFields=[...onboardingForm.children].filter((el)=>el.matches('.student-note:not([role="alert"]), label'));
    const submit=onboardingForm.querySelector('button[type="submit"]');
    const progress=[...document.querySelectorAll('[data-progress-step]')];
    const initialError=<?= $error !== '' ? 'true' : 'false' ?>;
    let current=initialError?2:0;
    steps[2].push(...finalFields);
    const back=document.createElement('button');back.type='button';back.className='student-onboarding-step-button secondary';back.textContent='Back';
    const next=document.createElement('button');next.type='button';next.className='student-onboarding-step-button primary';next.textContent='Next';
    const actions=document.createElement('div');actions.className='student-onboarding-step-actions';actions.append(back,next,submit);onboardingForm.append(actions);
    const validCurrent=()=>steps[current].every((el)=>{const controls=el.matches('.student-field')?[...el.querySelectorAll('input,select,textarea')]:[];return controls.every((control)=>{if(!control.checkValidity()){control.reportValidity();return false;}return true;});});
    const show=(index)=>{current=index;steps.forEach((group,i)=>group.forEach((el)=>{el.hidden=i!==current;}));progress.forEach((item,i)=>{item.classList.toggle('active',i===current);item.classList.toggle('complete',i<current);if(i===current)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});back.hidden=current===0;next.hidden=current===steps.length-1;actions.classList.toggle('final-step',current===steps.length-1);if(submit)submit.hidden=current!==steps.length-1;};
    back.addEventListener('click',()=>show(Math.max(0,current-1)));next.addEventListener('click',()=>{if(validCurrent())show(Math.min(steps.length-1,current+1));});show(current);
  }
const registrationPassword=document.getElementById('registration_password');const passwordConfirmation=document.getElementById('password_confirmation');const validatePasswords=()=>{if(!registrationPassword||!passwordConfirmation)return true;const strong=registrationPassword.value.length>=8&&registrationPassword.value.length<=128&&/[a-z]/.test(registrationPassword.value)&&/[A-Z]/.test(registrationPassword.value)&&/\d/.test(registrationPassword.value)&&/[^A-Za-z0-9]/.test(registrationPassword.value);registrationPassword.setCustomValidity(registrationPassword.value&&!strong?'Use 8–128 characters with uppercase, lowercase, number, and special character.':'');passwordConfirmation.setCustomValidity(passwordConfirmation.value&&registrationPassword.value!==passwordConfirmation.value?'Passwords do not match.':'');return strong&&registrationPassword.value===passwordConfirmation.value;};registrationPassword?.addEventListener('input',validatePasswords);passwordConfirmation?.addEventListener('input',validatePasswords);
</script>
<?php render_student_auth_footer(); ?>
