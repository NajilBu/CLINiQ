<?php

require_once __DIR__ . '/../app/helpers/view.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$publicBase = rtrim(app_url(''), '/');
$appBase = preg_replace('#/public$#', '', $publicBase);
$visitorRegisterUrl = app_url('visitor-registration.php');
$studentLoginUrl = $appBase . '/student/student-login.php';
$staffLoginUrl = app_url('login.php');
$clinicProfile = clinic_profile_settings();

?>
<!doctype html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($clinicProfile['system_name']) ?> | Access Portal</title>
    <link href="<?= app_url('assets/vendor/fonts/inter-manrope.css?v=offline-1') ?>" rel="stylesheet">
    <link href="<?= app_url('assets/vendor/fonts/material-symbols.css?v=offline-1') ?>" rel="stylesheet">
    <script src="<?= app_url('assets/vendor/tailwind/tailwind-cdn.js?v=offline-1') ?>"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#3F7D52',
                        'primary-fixed': '#e8f6ec',
                        'primary-container': '#23422C',
                        surface: '#f4fbf6',
                        'on-surface': '#17261d',
                        'surface-container-low': '#edf8f0',
                        'outline-variant': '#c7dccd'
                    },
                    fontFamily: {
                        headline: ['Manrope', 'sans-serif'],
                        body: ['Inter', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link href="<?= app_url('assets/css/app.css?v=system-ui-2') ?>" rel="stylesheet">
    <style>
        body {
            background:
                linear-gradient(180deg, rgba(244, 251, 246, 0.95), rgba(248, 252, 249, 1)),
                radial-gradient(circle at top, rgba(79, 159, 94, 0.08), transparent 34rem);
        }

        .portal-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .portal-header,
        .portal-footer {
            flex: 0 0 auto;
        }

        .portal-main {
            flex: 1 1 auto;
            display: grid;
            place-items: center;
            padding: 1.5rem;
        }

        .portal-card {
            width: min(100%, 58rem);
            padding: clamp(1.5rem, 4vw, 3.5rem);
            border: 1px solid rgba(199, 220, 205, 0.72);
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 1px 2px rgba(23, 38, 29, 0.04), 0 24px 60px rgba(23, 38, 29, 0.08);
        }

        .portal-option {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            width: 100%;
            min-height: 7rem;
            padding: 1.25rem;
            border: 1px solid rgba(199, 220, 205, 0.72);
            border-radius: 0.75rem;
            text-decoration: none;
            transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease;
        }

        .portal-option:hover {
            transform: translateY(-1px);
            border-color: rgba(79, 159, 94, 0.42);
            box-shadow: 0 14px 34px rgba(23, 38, 29, 0.08);
        }

        .portal-option-primary {
            background: #edf8f0;
        }

        .portal-option-secondary {
            background: #ffffff;
        }

        .portal-icon {
            display: grid;
            place-items: center;
            width: 4rem;
            height: 4rem;
            flex: 0 0 auto;
            border-radius: 0.75rem;
        }

        .portal-arrow {
            display: grid;
            place-items: center;
            width: 2.5rem;
            height: 2.5rem;
            flex: 0 0 auto;
            border-radius: 999px;
            background: #ffffff;
            color: #64748b;
            transition: background-color 0.16s ease, color 0.16s ease;
        }

        .portal-option:hover .portal-arrow {
            background: #3F7D52;
            color: #ffffff;
        }

        @media (max-width: 640px) {
            .portal-option {
                align-items: flex-start;
                min-height: auto;
                padding: 1rem;
            }

            .portal-icon {
                width: 3.25rem;
                height: 3.25rem;
            }

            .portal-arrow {
                display: none;
            }
        }
    </style>
</head>
<body class="font-body text-on-surface overflow-x-hidden">
<div class="portal-shell">
    <header class="portal-header w-full px-5 sm:px-8 py-5 flex items-center justify-between">
        <a href="<?= app_url('index.php') ?>" class="flex items-center gap-3 text-decoration-none">
            <span class="app-brand-mark">
                <img src="<?= app_url('assets/img/clinic-logo.png') ?>" alt="<?= e($clinicProfile['department']) ?> logo">
            </span>
            <span>
                <span class="block font-headline font-extrabold text-base leading-none text-[#17261d]"><?= e($clinicProfile['system_name']) ?></span>
                <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mt-1"><?= e($clinicProfile['department']) ?></span>
            </span>
        </a>
        <a href="<?= e($studentLoginUrl) ?>" class="btn btn-sm btn-ghost text-decoration-none">
            <span class="material-symbols-outlined text-[16px]">school</span>
            Student
        </a>
    </header>

    <main class="portal-main">
        <section class="portal-card text-center">
            <div class="mx-auto max-w-2xl mb-9">
                <h1 class="font-headline font-extrabold text-4xl sm:text-5xl text-[#17261d] mb-3"><?= e($clinicProfile['system_name']) ?></h1>
                <p class="text-sm sm:text-base font-bold text-slate-500 leading-relaxed">
                    Select your access point to begin your clinic session.
                </p>
            </div>

            <div class="mx-auto max-w-2xl space-y-4">
                <a href="<?= e($visitorRegisterUrl) ?>" class="portal-option portal-option-primary group">
                    <span class="portal-icon bg-primary text-white shadow-lg shadow-primary/20">
                        <span class="material-symbols-outlined text-[2.25rem]">person_search</span>
                    </span>
                    <span class="text-left flex-1 min-w-0">
                        <span class="block font-headline text-xl sm:text-2xl font-extrabold text-[#17261d]">Visitor / Patient Registration</span>
                        <span class="block text-xs sm:text-sm font-bold text-slate-500 mt-1">Record your arrival for clinic assessment and treatment.</span>
                    </span>
                    <span class="portal-arrow">
                        <span class="material-symbols-outlined">arrow_forward</span>
                    </span>
                </a>

                <a href="<?= e($staffLoginUrl) ?>" class="portal-option portal-option-secondary group">
                    <span class="portal-icon bg-white border border-outline-variant/60 text-primary">
                        <span class="material-symbols-outlined text-[2.25rem]">medical_services</span>
                    </span>
                    <span class="text-left flex-1 min-w-0">
                        <span class="block font-headline text-xl sm:text-2xl font-extrabold text-[#17261d]">Clinic Staff Portal</span>
                        <span class="block text-xs sm:text-sm font-bold text-slate-500 mt-1">Authorized access to visits, patient records, alerts, and reports.</span>
                    </span>
                    <span class="portal-arrow">
                        <span class="material-symbols-outlined">lock</span>
                    </span>
                </a>
            </div>

            <div class="mt-9 flex flex-col sm:flex-row items-center justify-center gap-4 sm:gap-8">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Server Live</span>
                </div>
                <div class="hidden sm:block w-px h-3 bg-slate-200"></div>
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Clinic visit gateway</span>
            </div>
        </section>
    </main>

    <footer class="portal-footer px-5 sm:px-8 py-5">
        <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between text-[10px] font-black text-slate-400 uppercase tracking-[0.18em]">
            <span>&copy; <?= date('Y') ?> <?= e($clinicProfile['system_name']) ?></span>
            <span>Pamantasan ng Lungsod ng Pasig Clinic Services</span>
        </div>
    </footer>
</div>
</body>
</html>
