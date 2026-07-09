<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/brand.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Store a flash message in the session for display after redirect.
 * @param string $type  One of: success, error, info, warning
 * @param string $message The message text
 */
function flash_message(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Get a deterministic avatar CSS color class from a name.
 */
function avatar_color(string $name): string
{
    $colors = ['avatar-blue', 'avatar-emerald', 'avatar-amber', 'avatar-red', 'avatar-indigo', 'avatar-slate'];
    $hash = crc32($name);
    return $colors[abs($hash) % count($colors)];
}

/**
 * Get initials from a full name (max 2 chars).
 */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials;
}

/**
 * Get the CSS class for a risk level badge.
 */
function risk_badge_class(string $level): string
{
    return match (strtolower($level)) {
        'critical' => 'badge-critical',
        'high' => 'badge-high',
        'moderate' => 'badge-moderate',
        'low' => 'badge-low',
        default => 'badge-pending',
    };
}

/**
 * Get the CSS class for an alert status badge.
 */
function status_badge_class(string $status): string
{
    return match (strtolower($status)) {
        'pending' => 'badge-pending',
        'not checked' => 'badge-pending',
        'pre-verified' => 'badge-completed',
        'verified' => 'badge-completed',
        'needs correction' => 'badge-high',
        'in progress' => 'badge-in-progress',
        'submitted' => 'badge-in-progress',
        'reviewed' => 'badge-in-progress',
        'scheduled' => 'badge-in-progress',
        'resolved' => 'badge-resolved',
        'cancelled' => 'badge-cancelled',
        'active' => 'badge-active',
        'registered' => 'badge-pending',
        'batch assigned' => 'badge-in-progress',
        'requirements checked' => 'badge-in-progress',
        'exam done' => 'badge-completed',
        'follow-up required' => 'badge-high',
        'for follow-up' => 'badge-high',
        'cleared' => 'badge-completed',
        'completed' => 'badge-completed',
        default => 'badge-pending',
    };
}

function render_header(string $title): void
{
    $user = current_user();
    $currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $nav = [
        'Dashboard' => ['url' => app_url('dashboard.php'), 'match' => 'dashboard.php', 'icon' => 'dashboard'],
        'Patients' => ['url' => app_url('patients/index.php'), 'match' => '/patients/', 'icon' => 'personal_injury'],
        'Visits' => ['url' => app_url('visits/index.php'), 'match' => '/visits/', 'icon' => 'clinical_notes'],
        'Alerts' => ['url' => app_url('alerts/index.php'), 'match' => '/alerts/', 'icon' => 'notification_important'],
        'Inventory' => ['url' => app_url('inventory/index.php'), 'match' => '/inventory/', 'icon' => 'inventory_2'],
        'APE' => ['url' => app_url('ape/index.php'), 'match' => '/ape/', 'icon' => 'description'],
        'Referrals' => ['url' => app_url('referrals/index.php'), 'match' => '/referrals/', 'icon' => 'send'],
        'Reports' => ['url' => app_url('reports/index.php'), 'match' => '/reports/', 'icon' => 'analytics'],
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
                            primary: '#3F7D52',
                            'primary-fixed': '#e8f6ec',
                            'primary-container': '#23422C',
                            'on-primary': '#ffffff',
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
        <link rel="stylesheet" href="<?= app_url('assets/vendor/ag-grid/ag-grid.css?v=31') ?>">
        <link rel="stylesheet" href="<?= app_url('assets/vendor/ag-grid/ag-theme-quartz.css?v=31') ?>">
        <script src="<?= app_url('assets/vendor/ag-grid/ag-grid-community.min.js?v=31') ?>"></script>
        <link href="<?= app_url('assets/css/app.css?v=system-ui-2') ?>" rel="stylesheet">
    </head>
    <body class="bg-surface font-body text-on-surface min-h-screen overflow-x-hidden">
    <?php if ($user): ?>
        <div class="app-shell">
            <aside class="app-sidebar">
                <a href="<?= app_url('dashboard.php') ?>" class="app-brand text-decoration-none">
                    <span class="app-brand-mark">
                        <img src="<?= app_url('assets/img/clinic-logo.png') ?>" alt="PLP Health Services Department logo">
                    </span>
                    <span class="app-brand-copy">
                        <span class="app-brand-title">CLINiQ</span>
                        <span class="app-brand-subtitle">University Health Services</span>
                    </span>
                </a>
                <nav class="app-nav">
                    <?php foreach ($nav as $label => $item): ?>
                        <?php $active = str_contains($currentPath, $item['match']); ?>
                        <a href="<?= e($item['url']) ?>" class="app-nav-link <?= $active ? 'active' : '' ?> text-decoration-none" title="<?= e($label) ?>">
                            <span class="material-symbols-outlined"><?= e($item['icon']) ?></span>
                            <span class="app-nav-label"><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="app-sidebar-footer">
                    <span class="app-user-label">Signed in as</span>
                    <strong><?= e($user['name']) ?></strong>
                </div>
            </aside>
            <div class="app-main">
                <header class="app-topbar">
                    <?php
                    $searchAction = app_url('patients/index.php');
                    $searchPlaceholder = 'Search student name or ID...';
                    if (str_contains($title ?? '', 'APE')) {
                        $searchAction = app_url('ape/index.php');
                        $searchPlaceholder = 'Search APE records...';
                    }
                    ?>
                    <button class="app-sidebar-toggle" type="button" id="sidebarToggle" aria-label="Collapse sidebar" aria-expanded="true">
                        <span class="material-symbols-outlined" aria-hidden="true">left_panel_close</span>
                    </button>
                    <form class="app-search" action="<?= $searchAction ?>" method="get">
                        <span class="material-symbols-outlined">search</span>
                        <input name="q" placeholder="<?= $searchPlaceholder ?>" autocomplete="off" value="<?= isset($_GET['q']) ? e($_GET['q']) : '' ?>">
                    </form>
                    <div class="app-topbar-meta">
                        <span><?= e(date('l, F j')) ?></span>
                        <a href="<?= app_url('logout.php') ?>" class="app-logout text-decoration-none">
                            <span class="material-symbols-outlined">logout</span>
                            Logout
                        </a>
                    </div>
                </header>
                <main class="app-content">
                    <div class="max-w-7xl mx-auto w-full space-y-6 pb-14">
    <?php else: ?>
        <main class="auth-main">
            <div class="auth-wrap">
    <?php endif; ?>
    <?php
}

function render_footer(): void
{
    $user = current_user();
    ?>
            </div>
        </main>
        <?php if ($user): ?>
            </div>
        </div>
        <?php endif; ?>
    <?php render_flash_toasts(); ?>
    <script src="<?= app_url('assets/js/app.js?v=sidebar-1') ?>"></script>
    <script src="<?= app_url('assets/js/ag-grid-tables.js?v=3') ?>"></script>
    </body>
    </html>
    <?php
}

/**
 * Render an AG Grid component.
 */
function render_ag_grid(string $gridId, array $columns, array $rows, array $options = []): void
{
    $pageSize = $options['pageSize'] ?? 25;
    $height = $options['height'] ?? 'standard';
    $heightClass = match ($height) {
        'compact' => 'cliniq-ag-grid-compact',
        'fill' => 'cliniq-ag-grid-fill',
        default => 'cliniq-ag-grid-standard',
    };
    $searchInput = $options['searchInput'] ?? '';

    echo '<div id="' . e($gridId) . '" class="cliniq-ag-grid ag-theme-quartz ' . $heightClass . '" data-ag-grid ' .
         ($searchInput ? 'data-search-input="' . e($searchInput) . '" ' : '') .
         (!empty($options['fitColumns']) ? 'data-fit-columns="true" ' : '') .
         'data-page-size="' . (int)$pageSize . '" ' .
         'data-empty-title="' . e($options['emptyTitle'] ?? '') . '" ' .
         'data-empty-text="' . e($options['emptyText'] ?? '') . '">';
    echo '<script type="application/json" data-grid-columns>' . json_encode($columns) . '</script>';
    echo '<script type="application/json" data-grid-rows>' . json_encode($rows) . '</script>';
    echo '</div>';
}

/**
 * Render pending flash messages as hidden data elements
 * that app.js will pick up and display as toasts.
 */
function render_flash_toasts(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $flashes = $_SESSION['flash'] ?? [];
    if (empty($flashes)) return;

    echo '<div id="flash-toasts" style="display:none;">';
    foreach ($flashes as $flash) {
        $type = e($flash['type'] ?? 'info');
        $message = e($flash['message'] ?? '');
        echo "<div data-flash=\"{$type}\" data-message=\"{$message}\"></div>";
    }
    echo '</div>';

    $_SESSION['flash'] = [];
}
