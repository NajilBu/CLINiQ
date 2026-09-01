<?php

require_once __DIR__ . '/../app/helpers/view.php';

$error = null;
$clinicProfile = clinic_profile_settings();
$clinicLogoUrl = app_url(clinic_profile_logo_path($clinicProfile));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idNumber = trim($_POST['id_number'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_attempt($idNumber, $password)) {
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid ID number or password, or the account is not active.';
}

render_header('Login');
?>
<style>
    .staff-login-card {
        width: min(100%, 54rem);
        overflow: hidden;
        display: grid;
        grid-template-columns: 0.9fr 1fr;
        background: #ffffff;
        border: 1px solid oklch(92% .01 230 / .72);
        border-radius: 1.65rem;
        box-shadow: 0 2px 8px oklch(22% .03 250 / .04), 0 24px 64px oklch(22% .03 250 / .1);
    }

    .staff-login-panel {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 31rem;
        padding: 2.35rem 2.25rem;
        background: var(--cliniq-primary-hover);
        color: #ffffff;
    }

    .staff-login-logo {
        display: grid;
        place-items: center;
        width: 3.25rem;
        height: 3.25rem;
        padding: 0.16rem;
        overflow: hidden;
        background: #ffffff;
        border-radius: 0.75rem;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.16);
    }

    .staff-login-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .staff-login-eyebrow {
        margin: 1.25rem 0 0.55rem;
        color: rgba(255, 255, 255, 0.66);
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .staff-login-title {
        margin: 0 0 0.75rem;
        color: #ffffff !important;
        font-size: clamp(2rem, 4vw, 2.7rem);
        font-weight: 700;
        line-height: 1.08;
    }

    .staff-login-copy {
        max-width: 30ch;
        margin: 0;
        color: rgba(255, 255, 255, 0.74);
        font-size: 0.9rem;
        font-weight: 400;
        line-height: 1.48;
    }

    .staff-login-pulse {
        width: 100%;
        height: 2.5rem;
        margin-top: 1.5rem;
    }

    .staff-login-pulse path {
        fill: none;
        stroke: rgba(255, 255, 255, 0.42);
        stroke-width: 1.5;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .staff-login-footnote {
        margin: 1.25rem 0 0;
        color: rgba(255, 255, 255, 0.56);
        font-size: 0.78rem;
        font-weight: 400;
    }

    .staff-login-form {
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 2.35rem 2.5rem;
    }

    .staff-field {
        margin-bottom: 0.9rem;
    }

    .staff-field label {
        display: block;
        margin-bottom: 0.45rem;
        color: #17261d;
        font-size: 0.78rem;
        font-weight: 600;
    }

    .staff-input-wrap {
        position: relative;
    }

    .staff-login-input {
        width: 100%;
        height: 2.65rem;
        padding: 0 0.9rem;
        border: 1px solid oklch(92% .01 230 / .88);
        border-radius: 0.85rem;
        background: #fbfcfa;
        color: #17261d;
        font-size: 0.8125rem;
        font-weight: 500;
        line-height: 1.35;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    }

    .staff-login-input::placeholder {
        font-size: 0.8125rem;
        line-height: 1.35;
    }

    .staff-login-input:focus {
        border-color: rgba(var(--cliniq-focus-rgb), 0.48);
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(var(--cliniq-focus-rgb), 0.14);
    }

    .staff-toggle-pw {
        position: absolute;
        right: 0.7rem;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #64756a;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
    }

    .staff-toggle-pw:hover {
        color: var(--cliniq-primary-hover);
    }

    .staff-note {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1.35rem;
        color: #64756a;
        font-size: 0.76rem;
        font-weight: 400;
    }

    .staff-login-form .btn-primary {
        border-radius: 0.9rem;
        box-shadow: 0 12px 26px rgba(var(--cliniq-shadow-rgb), 0.16);
    }

    body.is-electron-runtime .auth-wrap {
        position: relative;
        z-index: 1;
    }

    body.is-electron-runtime .staff-login-card {
        border-color: color-mix(in srgb, var(--cliniq-outline) 72%, transparent);
        border-radius: 2rem;
        box-shadow:
            0 2px 10px rgba(var(--cliniq-shadow-rgb), 0.04),
            0 30px 80px rgba(var(--cliniq-shadow-rgb), 0.14);
    }

    body.is-electron-runtime .staff-login-panel {
        background: linear-gradient(
            155deg,
            var(--cliniq-primary-hover),
            color-mix(in srgb, var(--cliniq-primary-hover) 82%, var(--cliniq-accent))
        );
    }

    body.is-electron-runtime .staff-login-form {
        position: relative;
        background: color-mix(in srgb, #ffffff 94%, var(--cliniq-primary-fixed));
    }

    body.is-electron-runtime .staff-login-input {
        border-color: color-mix(in srgb, var(--cliniq-outline) 65%, transparent);
        background: color-mix(in srgb, #ffffff 84%, var(--cliniq-surface-low));
    }

    body.is-electron-runtime .cliniq-entry-back {
        border-radius: 1rem;
        background: color-mix(in srgb, #ffffff 72%, var(--cliniq-primary-fixed));
        box-shadow: 0 10px 28px rgba(var(--cliniq-shadow-rgb), 0.08);
    }

    body.is-electron-runtime .cliniq-entry-logo,
    body.is-electron-runtime .staff-login-logo {
        border-radius: 1rem;
    }

    @media (max-width: 760px) {
        .staff-login-card {
            grid-template-columns: 1fr;
        }

        .staff-login-panel {
            min-height: auto;
            padding: 2rem;
        }

        .staff-login-pulse,
        .staff-login-footnote {
            display: none;
        }

        .staff-login-form {
            padding: 2rem;
        }
    }
</style>

<?php render_cliniq_entry_header([
    'homeUrl' => app_url('index.php'),
    'logoUrl' => $clinicLogoUrl,
]); ?>

<div class="min-h-[72vh] flex items-center justify-center py-8">
    <div class="staff-login-card">
        <section class="staff-login-panel">
            <div>
                <a href="<?= app_url('index.php') ?>" class="staff-login-logo text-decoration-none" aria-label="Go to <?= e($clinicProfile['system_name']) ?> access portal">
                    <img src="<?= e($clinicLogoUrl) ?>" alt="<?= e($clinicProfile['department']) ?> logo">
                </a>
                <p class="staff-login-eyebrow">
                    <a href="<?= app_url('index.php') ?>" class="text-white/70 hover:text-white text-decoration-none"><?= e($clinicProfile['department']) ?></a>
                </p>
                <h1 class="staff-login-title">Nurse's<br>Station</h1>
                <p class="staff-login-copy">Sign in with your staff account to manage patient records, alerts, inventory, and clinic reports.</p>
                <svg class="staff-login-pulse" viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 20 H100 L112 20 L120 4 L132 36 L142 20 L154 20 L162 12 L170 28 L178 20 L320 20"/>
                </svg>
            </div>
            <p class="staff-login-footnote">Staff access only &middot; contact IT for account issues</p>
        </section>

        <div class="staff-login-form">
            <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1"><?= e($clinicProfile['system_name']) ?></h2>
            <p class="text-sm font-bold text-slate-500 mb-7">Enter the password provided by the clinic or the password you created after activation.</p>

            <?php if ($error): ?>
                <div class="rounded-xl bg-red-50 border border-red-100 text-red-700 px-4 py-3 text-sm font-bold mb-5"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="staff-field">
                    <label for="id_number">ID Number</label>
                    <input class="staff-login-input" id="id_number" name="id_number" type="text" value="<?= e($_POST['id_number'] ?? 'STAFF-0001') ?>" placeholder="STAFF-0001" autocomplete="username" required>
                </div>

                <div class="staff-field">
                    <label for="password">Password</label>
                    <div class="staff-input-wrap">
                        <input class="staff-login-input pr-14" id="password" name="password" type="password" value="<?= e($_POST['password'] ?? 'password') ?>" placeholder="Enter password" autocomplete="current-password" required>
                        <button type="button" class="staff-toggle-pw" id="togglePassword">Show</button>
                    </div>
                </div>

                <button class="btn btn-primary w-full min-h-[2.9rem] mt-2" type="submit">Sign in</button>
            </form>

            <p class="text-center text-xs font-bold text-slate-500 mt-4">
                First login? Enter the password provided by the clinic.
            </p>

            <div class="staff-note">
                <span class="material-symbols-outlined text-[16px]">lock</span>
                Access is restricted to registered clinic staff.
            </div>
        </div>
    </div>
</div>

<script>
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    togglePassword?.addEventListener('click', () => {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        togglePassword.textContent = isPassword ? 'Hide' : 'Show';
    });
</script>
<?php render_footer(); ?>
