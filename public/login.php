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
<div class="min-h-[72vh] flex items-center justify-center">
    <div class="bg-white rounded-[4rem] border border-outline-variant/10 shadow-2xl shadow-slate-200/60 w-full max-w-5xl overflow-hidden grid grid-cols-1 lg:grid-cols-12">
        <div class="lg:col-span-5 bg-primary p-10 lg:p-14 text-white flex flex-col justify-between relative overflow-hidden">
            <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0); background-size: 32px 32px;"></div>
            <div class="relative">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-white/10 rounded-[2rem] mb-8 border border-white/20">
                    <span class="material-symbols-outlined text-3xl">admin_panel_settings</span>
                </div>
                <h1 class="font-headline font-extrabold text-4xl lg:text-5xl leading-none mb-5">Nurse's Station</h1>
                <p class="text-blue-100/80 font-medium text-sm lg:text-base leading-relaxed">
                    Authenticate your staff account to access patient records, alerts, inventory, and clinic reports.
                </p>
            </div>
            <div class="relative mt-10 p-4 bg-white/5 rounded-2xl border border-white/10">
                <p class="text-[10px] font-bold uppercase opacity-60 mb-1">Security Protocol</p>
                <p class="text-sm font-semibold mb-0">Staff-only access for clinic records.</p>
            </div>
        </div>
        <div class="lg:col-span-7 p-10 lg:p-14">
            <h2 class="font-headline text-3xl font-extrabold text-[#1c2a59] mb-2">Sign in</h2>
            <p class="text-sm font-bold text-slate-500 mb-6">PLP ClinicConnect management dashboard</p>
        <?php if ($error): ?>
            <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-5"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-5">
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase mb-2 block" for="email">Email</label>
                <input class="w-full min-h-[3.25rem] rounded-2xl border-slate-200 bg-slate-50 font-bold" id="email" name="email" type="email" value="admin@cliniq.local" required>
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase mb-2 block" for="password">Password</label>
                <input class="w-full min-h-[3.25rem] rounded-2xl border-slate-200 bg-slate-50 font-bold" id="password" name="password" type="password" value="password" required>
            </div>
            <button class="w-full min-h-[3.25rem] rounded-2xl bg-primary text-white font-black shadow-lg shadow-primary/20 hover:bg-primary-container" type="submit">Sign in</button>
        </form>
        </div>
    </div>
</div>
<?php render_footer(); ?>
