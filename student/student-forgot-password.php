<?php
require_once __DIR__ . '/includes/student-layout.php';
render_student_auth_header('Recover Password');
?>

<?php render_cliniq_entry_header([
    'homeUrl' => '../public/index.php',
    'logoUrl' => '../public/assets/img/clinic-logo.png',
]); ?>

<main class="student-auth-wrap">
    <section class="student-auth-shell">
        <aside class="student-auth-side">
            <div>
                <a href="../public/index.php" class="student-brand-mark text-decoration-none" aria-label="Go to CLINiQ access portal">
                    <img src="../public/assets/img/clinic-logo.png" alt="PLP Health Services Department logo">
                </a>
                <p class="student-auth-brand-line">CLINiQ</p>
                <h1 class="student-auth-side-title">Recover<br>Access</h1>
                <p class="student-auth-side-copy">Request recovery instructions for your student clinic portal account.</p>
                <svg class="student-auth-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/>
                </svg>
            </div>
            <p class="student-auth-side-footnote">Use your enrolled student ID and school email</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Account Help</p>
            <h2 class="student-card-title text-xl">Password recovery</h2>
            <p class="student-card-copy mb-5">Enter your student ID and school email. The clinic system will send recovery instructions.</p>

            <div id="success-alert" class="student-note student-note-success mb-4 hidden">
                <span class="material-symbols-outlined">mark_email_read</span>
                <div>Recovery instructions were simulated for this demo.</div>
            </div>

            <form onsubmit="handleRecover(event)">
                <div class="student-field">
                    <label class="student-label" for="student-id">Student ID</label>
                    <input id="student-id" class="student-input" type="text" placeholder="00-00000" autocomplete="username" data-student-id-format required>
                </div>

                <div class="student-field">
                    <label class="student-label" for="email">School Email</label>
                    <input id="email" class="student-input" type="email" placeholder="student@plpasig.edu.ph" required>
                </div>

                <button type="submit" class="student-button w-full">
                    Send Recovery Instructions
                    <span class="material-symbols-outlined">mail</span>
                </button>
            </form>

            <hr class="student-auth-divider">
            <p class="text-center text-xs font-bold text-slate-500">
                Remembered your password?
                <a href="student-login.php" class="student-auth-link text-decoration-none">Back to login.</a>
            </p>
        </div>
    </section>
</main>

<script>
    function handleRecover(event) {
        event.preventDefault();
        document.getElementById('success-alert').classList.remove('hidden');
    }
</script>

<?php render_student_auth_footer(); ?>
