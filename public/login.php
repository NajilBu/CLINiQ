<?php

require_once __DIR__ . '/../app/helpers/view.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_attempt($email, $password)) {
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid email or password.';
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
        border-radius: 1rem;
        box-shadow: 0 1px 2px oklch(22% .03 250 / .04), 0 18px 42px oklch(22% .03 250 / .08);
    }

    .staff-login-panel {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 31rem;
        padding: 2.35rem 2.25rem;
        background: #23422C;
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
        border-radius: 0.625rem;
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
        border-color: rgba(63, 125, 82, 0.48);
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(63, 125, 82, 0.14);
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
        color: #23422C;
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
    'logoUrl' => app_url('assets/img/clinic-logo.png'),
]); ?>

<div class="min-h-[72vh] flex items-center justify-center py-8">
    <div class="staff-login-card">
        <section class="staff-login-panel">
            <div>
                <a href="<?= app_url('index.php') ?>" class="staff-login-logo text-decoration-none" aria-label="Go to CLINiQ access portal">
                    <img src="<?= app_url('assets/img/clinic-logo.png') ?>" alt="PLP Health Services Department logo">
                </a>
                <p class="staff-login-eyebrow">
                    <a href="<?= app_url('index.php') ?>" class="text-white/70 hover:text-white text-decoration-none">University Health Services</a>
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
            <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">CLINiQ</h2>
            <p class="text-sm font-bold text-slate-500 mb-7">Enter your credentials to continue.</p>

            <?php if ($error): ?>
                <div class="rounded-xl bg-red-50 border border-red-100 text-red-700 px-4 py-3 text-sm font-bold mb-5"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="staff-field">
                    <label for="email">Email</label>
                    <input class="staff-login-input" id="email" name="email" type="email" value="<?= e($_POST['email'] ?? 'admin@cliniq.local') ?>" placeholder="name@cliniq.local" autocomplete="username" required>
                </div>

                <div class="staff-field">
                    <label for="password">Password</label>
                    <div class="staff-input-wrap">
                        <input class="staff-login-input pr-14" id="password" name="password" type="password" value="<?= e($_POST['password'] ?? 'password') ?>" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="staff-toggle-pw" id="togglePassword">Show</button>
                    </div>
                </div>

                <button class="btn btn-primary w-full min-h-[2.9rem] mt-2" type="submit">Sign in</button>
            </form>

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
