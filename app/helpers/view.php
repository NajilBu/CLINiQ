<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/env.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function render_header(string $title): void
{
    $user = current_user();
    $currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $nav = [
        'Dashboard' => ['url' => app_url('dashboard.php'), 'match' => 'dashboard.php'],
        'Patient Records' => ['url' => app_url('patients/index.php'), 'match' => '/patients/'],
        'Visits' => ['url' => app_url('visits/index.php'), 'match' => '/visits/'],
        'Alerts' => ['url' => app_url('alerts/index.php'), 'match' => '/alerts/'],
        'Inventory' => ['url' => app_url('inventory/index.php'), 'match' => '/inventory/'],
        'Reports' => ['url' => app_url('reports/index.php'), 'match' => '/reports/'],
    ];
    ?>
    <!doctype html>
    <html class="light" lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | PLP ClinicConnect</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            primary: '#00478d',
                            'primary-fixed': '#d6e3ff',
                            'primary-container': '#005eb8',
                            'on-primary': '#ffffff',
                            surface: '#f8f9fa',
                            'on-surface': '#191c1d',
                            'surface-container-low': '#f3f4f5',
                            'outline-variant': '#c2c6d4'
                        },
                        fontFamily: {
                            headline: ['Manrope', 'sans-serif'],
                            body: ['Inter', 'sans-serif']
                        }
                    }
                }
            };
        </script>
        <link href="<?= app_url('assets/css/app.css') ?>" rel="stylesheet">
    </head>
    <body class="bg-surface font-body text-on-surface min-h-screen flex flex-col overflow-x-hidden">
    <header class="w-full px-4 md:px-8 py-4 shrink-0 flex flex-col lg:flex-row justify-between gap-4 lg:items-center bg-white border-b border-outline-variant/20 shadow-sm relative z-20">
        <a href="<?= app_url('dashboard.php') ?>" class="flex items-center gap-2.5 text-decoration-none">
            <span class="w-8 h-8 bg-primary text-white rounded-lg flex items-center justify-center shadow-lg shadow-primary/20">
                <span class="material-symbols-outlined text-[18px]">clinical_notes</span>
            </span>
            <span class="font-headline font-extrabold text-base text-[#1c2a59]">PLP Clinic<span class="text-[#004d9c]">Connect</span></span>
        </a>
            <?php if ($user): ?>
                <nav class="flex flex-wrap items-center gap-4 lg:gap-7">
                    <?php foreach ($nav as $label => $item): ?>
                        <?php $active = str_contains($currentPath, $item['match']); ?>
                        <a href="<?= e($item['url']) ?>" class="text-xs font-bold <?= $active ? 'text-primary border-b-2 border-primary' : 'text-slate-500 hover:text-slate-800' ?> transition-colors py-1 text-decoration-none"><?= e($label) ?></a>
                    <?php endforeach; ?>
                    <span class="hidden lg:block w-px h-4 bg-slate-200"></span>
                    <span class="text-xs font-bold text-slate-400"><?= e($user['name']) ?></span>
                    <a href="<?= app_url('logout.php') ?>" class="flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-red-600 transition-colors text-decoration-none">
                        Logout
                        <span class="material-symbols-outlined text-[16px]">logout</span>
                    </a>
                </nav>
            <?php endif; ?>
    </header>
    <main class="flex-1 w-full p-4 md:p-6 lg:p-10 overflow-y-auto">
        <div class="max-w-7xl mx-auto w-full space-y-8 pb-16">
    <?php
}

function render_footer(): void
{
    ?>
        </div>
    </main>
    <script src="<?= app_url('assets/js/app.js') ?>"></script>
    </body>
    </html>
    <?php
}
