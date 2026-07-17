<?php
require_once __DIR__ . '/includes/student-layout.php';
render_student_auth_header('Student Registration');
?>

<?php render_cliniq_entry_header([
    'homeUrl' => '../public/index.php',
    'logoUrl' => '../public/assets/img/clinic-logo.png',
]); ?>

<main class="student-auth-wrap">
    <section class="student-auth-shell student-auth-shell-wide">
        <aside class="student-auth-side">
            <div>
                <a href="../public/index.php" class="student-brand-mark text-decoration-none" aria-label="Go to CLINiQ access portal">
                    <img src="../public/assets/img/clinic-logo.png" alt="PLP Health Services Department logo">
                </a>
                <p class="student-auth-brand-line">CLINiQ &middot; Freshman onboarding</p>
                <h1 class="student-auth-side-title">Create your clinic account</h1>
                <p class="student-auth-side-copy">One account connects your APE status, document uploads, and appointment history.</p>
                <ul class="student-auth-checklist">
                    <li><span class="material-symbols-outlined">check</span>Track your APE clearance status</li>
                    <li><span class="material-symbols-outlined">check</span>Upload documents from anywhere</li>
                    <li><span class="material-symbols-outlined">check</span>Book appointments without queuing</li>
                </ul>
            </div>
            <p class="student-auth-side-footnote">Access is limited to enrolled PLP students</p>
        </aside>

        <div class="student-auth-form-side">
            <p class="student-eyebrow">Student clinic registration</p>
            <h2 class="student-card-title text-xl">Register your account</h2>
            <p class="student-card-copy mb-5">Use your enrolled student details so the clinic can match your records.</p>

            <div id="success-alert" class="student-note student-note-success mb-4 hidden">
                <span class="material-symbols-outlined">check_circle</span>
                <div>Registration simulated successfully. You can now sign in with the demo account.</div>
            </div>

            <form onsubmit="handleRegister(event)">
                <p class="student-label mb-3">Personal information</p>
                <div class="student-grid">
                    <div class="student-field student-span-6">
                        <label class="student-label" for="first-name">First Name</label>
                        <input id="first-name" class="student-input" type="text" placeholder="Juan" required>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="last-name">Last Name</label>
                        <input id="last-name" class="student-input" type="text" placeholder="dela Cruz" required>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="student-id">Student ID</label>
                        <input id="student-id" class="student-input" type="text" placeholder="00-00000" data-student-id-format required>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="course">Program</label>
                        <select id="course" class="student-select" required>
                            <option value="">Select program</option>
                            <?php foreach (dropdown_options('student_program') as $program): ?>
                                <option value="<?= student_e($program) ?>"><?= student_e($program) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="year-level">Year Level</label>
                        <select id="year-level" class="student-select" required>
                            <option value="">Select year level</option>
                            <?php foreach (dropdown_options('year_level') as $year): ?>
                                <option value="<?= student_e($year) ?>"><?= student_e($year) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="section">Section</label>
                        <select id="section" class="student-select" required>
                            <option value="">Select section</option>
                            <?php foreach (dropdown_options('student_section') as $section): ?>
                                <option value="<?= student_e($section) ?>"><?= student_e($section) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="student-label mb-3 mt-1">Account details</p>
                <div class="student-grid">
                    <div class="student-field student-span-12">
                        <label class="student-label" for="email">School Email</label>
                        <input id="email" class="student-input" type="email" placeholder="student@plpasig.edu.ph" autocomplete="username" required>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="password">Password</label>
                        <div class="relative">
                            <input id="password" class="student-input pr-14" type="password" placeholder="Create password" autocomplete="new-password" required>
                            <button type="button" class="student-toggle-pw" data-target="password">Show</button>
                        </div>
                    </div>
                    <div class="student-field student-span-6">
                        <label class="student-label" for="confirm-password">Confirm Password</label>
                        <div class="relative">
                            <input id="confirm-password" class="student-input pr-14" type="password" placeholder="Confirm password" autocomplete="new-password" required>
                            <button type="button" class="student-toggle-pw" data-target="confirm-password">Show</button>
                        </div>
                    </div>
                </div>
                <p class="student-card-copy text-[11px] mb-3">At least 8 characters, with one number.</p>

                <button type="submit" class="student-button w-full">
                    Register Account
                    <span class="material-symbols-outlined">person_add</span>
                </button>
            </form>

            <p class="text-center text-xs font-bold text-slate-500 mt-4">
                Already registered?
                <a href="student-login.php" class="student-auth-link text-decoration-none">Login here.</a>
            </p>
        </div>
    </section>
</main>

<script>
    document.querySelectorAll('.student-toggle-pw').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.target);
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            button.textContent = isPassword ? 'Hide' : 'Show';
        });
    });

    function handleRegister(event) {
        event.preventDefault();
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm-password').value;

        if (password !== confirmPassword) {
            alert('Passwords do not match.');
            return;
        }

        document.getElementById('success-alert').classList.remove('hidden');
        setTimeout(() => {
            window.location.href = 'student-login.php';
        }, 1200);
    }
</script>

<?php render_student_auth_footer(); ?>
