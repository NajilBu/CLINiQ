<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/student_id.php';
require_once __DIR__ . '/../services/SystemSettings.php';
require_once __DIR__ . '/../services/ProfilePhoto.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function row_actions_button(string $title, string $actionsHtml): string
{
    if (trim($actionsHtml) === '') {
        return '<span class="text-slate-300 font-bold">-</span>';
    }

    return '<button type="button" class="row-actions-trigger" data-no-row-click data-row-actions-trigger data-row-actions-title="' . e($title) . '" data-row-actions-html="' . e($actionsHtml) . '" aria-label="' . e($title) . '"><span class="material-symbols-outlined">more_vert</span></button>';
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

function set_page_back_link(string $url, string $label = 'Back'): void
{
    $GLOBALS['cliniq_page_back_link'] = [
        'url' => $url,
        'label' => $label,
    ];
}

function render_clinic_command_header(string $eyebrow, string $heading, string $subheading = '', string $actionsHtml = ''): void
{
    ?>
    <div class="dashboard-hero flex flex-col lg:flex-row lg:items-center justify-between gap-5 mb-8">
        <div>
            <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2"><?= e($eyebrow) ?></p>
            <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]"><?= e($heading) ?></h1>
            <?php if ($subheading !== ''): ?>
                <p class="text-sm font-bold text-slate-500 mt-1"><?= e($subheading) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actionsHtml !== ''): ?>
            <div class="flex items-center gap-3">
                <?= $actionsHtml ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
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
        'not assessed' => 'badge-pending',
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
    $isElectronRuntime = str_contains(strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'cliniqelectron/');
    $patientPortalUrl = rtrim((string) env_value('PATIENT_PORTAL_URL', '/CLINiQ/patient-portal'), '/');

    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if ($user) {
            header('Cache-Control: no-store, private, max-age=0');
            header('Pragma: no-cache');
        }
    }

    $clinicProfile = clinic_profile_settings();
    $clinicLogoUrl = app_url(clinic_profile_logo_path($clinicProfile));
    $theme = active_cliniq_theme();
    $pageBackLink = $GLOBALS['cliniq_page_back_link'] ?? null;
    $currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $staffPhotoPath = $user ? profile_photo_path_for_person((int) ($user['person_id'] ?? $user['id'] ?? 0)) : null;
    $staffPhotoUrl = $staffPhotoPath !== null ? app_url($staffPhotoPath) : null;
    $activeAlertCount = 0;
    $criticalAlertCount = 0;
    $pendingAlertUrl = app_url('alerts/index.php?status=pending');
    $pendingAlertTitle = 'View pending alerts';
    if ($user) {
        try {
            $alertSummary = auth_db()->query("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) AS critical_total
                FROM nurse_alerts
                WHERE status = 'Pending'
            ")->fetch();
            $activeAlertCount = (int) ($alertSummary['total'] ?? 0);
            $criticalAlertCount = (int) ($alertSummary['critical_total'] ?? 0);

            if ($activeAlertCount === 1) {
                $latestAlert = auth_db()->query("SELECT id FROM nurse_alerts WHERE status = 'Pending' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch();
                $latestAlertId = (int) ($latestAlert['id'] ?? 0);
                if ($latestAlertId > 0) {
                    $pendingAlertUrl = app_url('alerts/view.php?id=' . $latestAlertId);
                    $pendingAlertTitle = 'Open pending alert #' . $latestAlertId;
                }
            } elseif ($activeAlertCount > 1) {
                $pendingAlertTitle = 'View ' . $activeAlertCount . ' pending alerts';
            }
        } catch (Throwable $e) {
            $activeAlertCount = 0;
            $criticalAlertCount = 0;
            $pendingAlertUrl = app_url('alerts/index.php?status=pending');
            $pendingAlertTitle = 'View pending alerts';
        }
    }
    $bodyClasses = 'bg-surface font-body text-on-surface min-h-screen overflow-x-hidden';
    if ($isElectronRuntime) {
        $bodyClasses .= ' is-electron-runtime';
    }
    if ($activeAlertCount > 0) {
        $bodyClasses .= ' has-active-alerts';
    }
    $nav = [
        'Dashboard' => ['url' => app_url('dashboard.php'), 'match' => 'dashboard.php', 'icon' => 'dashboard'],
        'Patients' => ['url' => app_url('patients/index.php'), 'match' => '/patients/', 'icon' => 'personal_injury'],
        'Visits' => ['url' => app_url('visits/index.php'), 'match' => '/visits/', 'icon' => 'clinical_notes'],
        'Alerts' => ['url' => app_url('alerts/index.php'), 'match' => '/alerts/', 'icon' => 'notification_important'],
        'Inventory' => ['url' => app_url('inventory/index.php'), 'match' => '/inventory/', 'icon' => 'inventory_2'],
        'APE' => ['url' => app_url('ape/index.php'), 'match' => '/ape/', 'icon' => 'description'],
        'Appointments' => ['url' => app_url('appointments/index.php'), 'match' => '/appointments/', 'icon' => 'calendar_month'],
        'Referrals' => ['url' => app_url('referrals/index.php'), 'match' => '/referrals/', 'icon' => 'send'],
        'Reports' => ['url' => app_url('reports/index.php'), 'match' => '/reports/', 'icon' => 'analytics'],
        'Feedback' => ['url' => app_url('feedback/index.php'), 'match' => '/feedback/', 'icon' => 'rate_review', 'roles' => ['admin', 'doctor']],
        'Audit Log' => ['url' => app_url('audit/index.php'), 'match' => '/audit/', 'icon' => 'history', 'roles' => ['admin']],
        'Settings' => ['url' => app_url('settings/index.php'), 'match' => '/settings/', 'icon' => 'settings'],
    ];
    if (first_registration_pending('staff')) {
        $nav = [
            'Dashboard' => $nav['Dashboard'],
        ];
    }
    ?>
    <!doctype html>
    <html class="light" lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | <?= e($clinicProfile['system_name']) ?></title>
        <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
        <script src="<?= app_url('assets/js/csrf.js?v=' . filemtime(__DIR__ . '/../../public/assets/js/csrf.js')) ?>" defer></script>
        <link href="<?= app_url('assets/vendor/fonts/inter-manrope.css?v=offline-1') ?>" rel="stylesheet">
        <link href="<?= app_url('assets/vendor/fonts/material-symbols.css?v=offline-1') ?>" rel="stylesheet">
        <script src="<?= app_url('assets/vendor/tailwind/tailwind-cdn.js?v=offline-1') ?>"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            primary: <?= json_encode($theme['primary']) ?>,
                            'primary-fixed': <?= json_encode($theme['primary_fixed']) ?>,
                            'primary-container': <?= json_encode($theme['primary_container']) ?>,
                            'on-primary': '#ffffff',
                            surface: <?= json_encode($theme['surface']) ?>,
                            'on-surface': '#17261d',
                            'surface-container-low': <?= json_encode($theme['surface_container_low']) ?>,
                            'outline-variant': <?= json_encode($theme['outline_variant']) ?>
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
        <link href="<?= app_url('assets/css/app.css?v=' . filemtime(__DIR__ . '/../../public/assets/css/app.css')) ?>" rel="stylesheet">
        <style>
            :root {
                --cliniq-primary: <?= e($theme['primary']) ?>;
                --cliniq-primary-hover: <?= e($theme['primary_container']) ?>;
                --cliniq-primary-fixed: <?= e($theme['primary_fixed']) ?>;
                --cliniq-accent: <?= e($theme['accent']) ?>;
                --cliniq-accent-foreground: <?= e($theme['primary_container']) ?>;
                --cliniq-surface: <?= e($theme['surface']) ?>;
                --cliniq-surface-low: <?= e($theme['surface_container_low']) ?>;
                --cliniq-outline: <?= e($theme['outline_variant']) ?>;
                --cliniq-focus-rgb: <?= e($theme['focus_rgb']) ?>;
                --cliniq-shadow-rgb: <?= e($theme['shadow_rgb']) ?>;
            }
        </style>
    <script src="<?= app_url('assets/js/id-number-format.js?v=' . filemtime(__DIR__ . '/../../public/assets/js/id-number-format.js')) ?>"></script>
    </head>
    <body class="<?= e($bodyClasses) ?>" data-cliniq-runtime="<?= $isElectronRuntime ? 'electron' : 'browser' ?>" data-cliniq-app-url="<?= e(app_url()) ?>" data-cliniq-patient-portal-url="<?= e($patientPortalUrl) ?>"<?php if ($user): ?> data-alert-status-url="<?= e(app_url('api/alerts.php')) ?>" data-active-alert-count="<?= (int) $activeAlertCount ?>" data-critical-alert-count="<?= (int) $criticalAlertCount ?>"<?php endif; ?>>
    <?php if ($user): ?>
        <div class="app-shell">
            <aside class="app-sidebar">
                <a href="<?= app_url('dashboard.php') ?>" class="app-brand text-decoration-none" data-no-ajax="true">
                    <span class="app-brand-mark">
                        <img src="<?= e($clinicLogoUrl) ?>" alt="<?= e($clinicProfile['department']) ?> logo">
                    </span>
                    <span class="app-brand-copy">
                        <span class="app-brand-title"><?= e($clinicProfile['system_name']) ?></span>
                        <span class="app-brand-subtitle"><?= e($clinicProfile['department']) ?></span>
                    </span>
                </a>
                <nav class="app-nav">
                    <?php foreach ($nav as $label => $item): ?>
                        <?php if (isset($item['roles']) && !in_array($user['role'] ?? '', $item['roles'], true)) continue; ?>
                        <?php $active = str_contains($currentPath, $item['match']); ?>
                        <a href="<?= e($item['url']) ?>" class="app-nav-link <?= $active ? 'active' : '' ?> text-decoration-none" title="<?= e($label) ?>" data-no-ajax="true">
                            <span class="material-symbols-outlined"><?= e($item['icon']) ?></span>
                            <span class="app-nav-label"><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="app-sidebar-footer">
                    <details class="app-user-menu">
                        <summary class="app-user-menu-trigger">
                        <span class="app-user-copy">
                            <span class="app-user-label">Signed in as</span>
                            <strong><?= e($user['name']) ?></strong>
                        </span>
                        <span class="material-symbols-outlined app-user-menu-icon" aria-hidden="true">expand_more</span>
                        </summary>
                        <div class="app-user-menu-popover">
                        <a href="<?= app_url('logout.php') ?>" class="app-logout text-decoration-none" data-no-ajax="true">
                            <span class="material-symbols-outlined">logout</span>
                            Logout
                        </a>
                        </div>
                    </details>
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
                    <div class="app-topbar-controls">
                        <button class="app-sidebar-toggle" type="button" id="sidebarToggle" aria-label="Collapse sidebar" aria-expanded="true">
                            <span class="material-symbols-outlined" aria-hidden="true">left_panel_close</span>
                        </button>
                    </div>
                    <form class="app-search" action="<?= $searchAction ?>" method="get">
                        <span class="material-symbols-outlined">search</span>
                        <input name="q" placeholder="<?= $searchPlaceholder ?>" autocomplete="off" value="<?= isset($_GET['q']) ? e($_GET['q']) : '' ?>">
                    </form>
                    <div class="app-topbar-meta">
                        <?php if ($activeAlertCount > 0): ?>
                            <a href="<?= e($pendingAlertUrl) ?>" class="app-alert-link has-alerts <?= $activeAlertCount > 0 ? 'has-active-alerts' : '' ?> text-decoration-none" title="<?= e($pendingAlertTitle) ?>" data-no-ajax="true">
                                <span class="material-symbols-outlined">notification_important</span>
                                <span class="app-alert-label">Pending Alerts</span>
                                <span class="app-alert-badge"><?= $activeAlertCount > 99 ? '99+' : $activeAlertCount ?></span>
                            </a>
                        <?php endif; ?>
                        <span><?= e(date('l, F j')) ?></span>
                        <button type="button" class="app-profile-photo" data-profile-photo-open="staff-profile-photo-modal" title="Change profile picture" aria-label="Change profile picture">
                            <?php if ($staffPhotoUrl !== null): ?>
                                <img src="<?= e($staffPhotoUrl) ?>" alt="<?= e($user['name']) ?> profile picture">
                            <?php else: ?>
                                <span><?= e(initials((string) $user['name'])) ?></span>
                            <?php endif; ?>
                            <span class="profile-photo-camera material-symbols-outlined" aria-hidden="true">photo_camera</span>
                        </button>
                    </div>
                </header>
                <main class="app-content">
                    <div class="max-w-7xl mx-auto w-full space-y-6 pb-14" id="cliniqPageContent" data-cliniq-page-content>
                        <?php if ($pageBackLink): ?>
                            <div class="app-page-back-row">
                                <a href="<?= e($pageBackLink['url']) ?>" class="app-page-back text-decoration-none">
                                    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                                    <span><?= e($pageBackLink['label']) ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
    <?php else: ?>
        <main class="auth-main">
            <div class="auth-wrap">
    <?php endif; ?>
    <?php
}

function render_footer(): void
{
    $user = current_user();
    $personId = (int) ($user['person_id'] ?? $user['id'] ?? 0);
    $photoPath = $personId > 0 ? profile_photo_path_for_person($personId) : null;
    $photoUrl = $photoPath !== null ? app_url($photoPath) : null;
    $requestPath = ltrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/public/dashboard.php', PHP_URL_PATH), '/');
    $returnTo = preg_replace('#^public/#', '', $requestPath) ?: 'dashboard.php';
    ?>
            </div>
        </main>
        <?php if ($user): ?>
            </div>
        </div>
        <div id="rowActionsModal" class="modal-backdrop" data-no-row-click>
            <div class="modal-content row-actions-modal">
                <div class="row-actions-modal-header">
                    <div>
                        <p class="row-actions-eyebrow">Available Actions</p>
                        <h3 id="rowActionsModalTitle">Actions</h3>
                    </div>
                    <button type="button" class="row-actions-close" onclick="closeModal('rowActionsModal')" aria-label="Close actions">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div id="rowActionsModalBody" class="row-actions-modal-body"></div>
            </div>
        </div>
        <div id="staff-profile-photo-modal" class="profile-photo-modal" role="dialog" aria-modal="true" aria-labelledby="staff-profile-photo-title" hidden>
            <div class="profile-photo-dialog">
                <div class="profile-photo-dialog-header">
                    <div>
                        <p class="profile-photo-eyebrow">Clinic profile</p>
                        <h3 id="staff-profile-photo-title">Change profile picture</h3>
                    </div>
                    <button type="button" class="profile-photo-close" data-profile-photo-close aria-label="Close profile-picture dialog">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form action="<?= app_url('profile-photo-update.php') ?>" method="post" enctype="multipart/form-data" class="profile-photo-preview-form" data-profile-photo-form>
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                    <div class="profile-photo-stage is-active" data-profile-photo-editor>
                        <div class="profile-photo-preview-frame">
                            <img data-profile-photo-preview src="<?= e($photoUrl ?? '') ?>" alt="Selected profile-picture preview" <?= $photoUrl === null ? 'hidden' : '' ?>>
                            <span data-profile-photo-fallback <?= $photoUrl !== null ? 'hidden' : '' ?>><?= e(initials((string) ($user['name'] ?? 'Staff'))) ?></span>
                        </div>
                        <p class="profile-photo-help">Choose a clear JPG, PNG, or WebP image up to 5 MB.</p>
                        <input id="staff-profile-photo-input" class="profile-photo-input" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required data-profile-photo-input>
                        <div class="profile-photo-dialog-actions">
                            <label for="staff-profile-photo-input" class="btn btn-ghost profile-photo-choose">
                                <span class="material-symbols-outlined">image</span>
                                Choose Photo
                            </label>
                            <button type="button" class="btn btn-ghost" data-profile-photo-close>Cancel</button>
                            <button type="submit" class="btn btn-primary" data-profile-photo-save disabled>Review Photo</button>
                        </div>
                    </div>
                    <div class="profile-photo-stage profile-photo-confirmation" data-profile-photo-confirmation aria-hidden="true">
                        <div class="profile-photo-preview-frame profile-photo-confirm-preview-frame">
                            <img data-profile-photo-confirm-preview src="" alt="Profile picture ready to save" hidden>
                        </div>
                        <h4>Use this profile picture?</h4>
                        <p class="profile-photo-help">Your clinic profile will display this photo after you confirm.</p>
                        <div class="profile-photo-dialog-actions">
                            <button type="button" class="btn btn-ghost" data-profile-photo-confirm-cancel>Go Back</button>
                            <button type="submit" class="btn btn-primary" data-profile-photo-confirm-submit>Confirm &amp; Save</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <?php render_flash_toasts(); ?>
    <script src="<?= app_url('assets/js/app.js?v=' . filemtime(__DIR__ . '/../../public/assets/js/app.js')) ?>"></script>
    <script src="<?= app_url('assets/js/ag-grid-tables.js?v=' . filemtime(__DIR__ . '/../../public/assets/js/ag-grid-tables.js')) ?>"></script>
    <script src="<?= app_url('assets/js/file-preview.js?v=ape-popup-2') ?>"></script>
    <script>
        const profilePhotoMotionMs = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 240;

        function setProfilePhotoConfirmation(form, confirming) {
            const editor = form.querySelector('[data-profile-photo-editor]');
            const confirmation = form.querySelector('[data-profile-photo-confirmation]');
            if (!editor || !confirmation) return;
            editor.classList.toggle('is-active', !confirming);
            editor.setAttribute('aria-hidden', confirming ? 'true' : 'false');
            confirmation.classList.toggle('is-active', confirming);
            confirmation.setAttribute('aria-hidden', confirming ? 'false' : 'true');
            form.dataset.profilePhotoConfirmed = 'false';
            if (confirming) {
                const preview = form.querySelector('[data-profile-photo-preview]');
                const confirmationPreview = form.querySelector('[data-profile-photo-confirm-preview]');
                if (preview && confirmationPreview) {
                    confirmationPreview.src = preview.src;
                    confirmationPreview.hidden = false;
                }
                form.querySelector('[data-profile-photo-confirm-submit]')?.focus();
            }
        }

        function openProfilePhotoModal(modal) {
            clearTimeout(modal._profilePhotoCloseTimer);
            modal.hidden = false;
            modal._profilePhotoClosing = false;
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => {
                if (!modal._profilePhotoClosing) modal.classList.add('is-open');
            });
        }

        function closeProfilePhotoModal(modal) {
            modal._profilePhotoClosing = true;
            modal.classList.remove('is-open');
            clearTimeout(modal._profilePhotoCloseTimer);
            modal._profilePhotoCloseTimer = setTimeout(() => {
                modal.hidden = true;
                const form = modal.querySelector('[data-profile-photo-form]');
                if (form) setProfilePhotoConfirmation(form, false);
                if (!document.querySelector('.profile-photo-modal.is-open, .modal-backdrop.show')) {
                    document.body.style.overflow = '';
                }
            }, profilePhotoMotionMs);
        }

        document.querySelectorAll('[data-profile-photo-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = document.getElementById(button.dataset.profilePhotoOpen);
                if (modal) openProfilePhotoModal(modal);
            });
        });
        document.querySelectorAll('[data-profile-photo-close]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = button.closest('.profile-photo-modal');
                if (modal) closeProfilePhotoModal(modal);
            });
        });
        document.querySelectorAll('.profile-photo-modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeProfilePhotoModal(modal);
            });
        });
        document.querySelectorAll('[data-profile-photo-input]').forEach((input) => {
            input.addEventListener('change', () => {
                const form = input.closest('[data-profile-photo-form]');
                const file = input.files && input.files[0];
                if (!form || !file) return;
                const preview = form.querySelector('[data-profile-photo-preview]');
                const fallback = form.querySelector('[data-profile-photo-fallback]');
                const save = form.querySelector('[data-profile-photo-save]');
                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    preview.src = String(reader.result || '');
                    preview.hidden = false;
                    if (fallback) fallback.hidden = true;
                    if (save) save.disabled = false;
                    setProfilePhotoConfirmation(form, false);
                });
                reader.readAsDataURL(file);
            });
        });
        document.querySelectorAll('[data-profile-photo-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.dataset.profilePhotoConfirmed !== 'true') {
                    event.preventDefault();
                    setProfilePhotoConfirmation(form, true);
                    return;
                }
                const submit = form.querySelector('[data-profile-photo-confirm-submit]');
                if (submit) {
                    submit.disabled = true;
                    submit.textContent = 'Saving...';
                }
            });
            form.querySelector('[data-profile-photo-confirm-cancel]')?.addEventListener('click', () => {
                setProfilePhotoConfirmation(form, false);
                form.querySelector('[data-profile-photo-save]')?.focus();
            });
            form.querySelector('[data-profile-photo-confirm-submit]')?.addEventListener('click', () => {
                form.dataset.profilePhotoConfirmed = 'true';
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.profile-photo-modal.is-open').forEach(closeProfilePhotoModal);
        });
    </script>
    </body>
    </html>
    <?php
}

/**
 * Render an AG Grid component.
 */
function render_ag_grid(string $gridId, array $columns, array $rows, array $options = []): void
{
    $hasRowNumberColumn = false;
    foreach ($columns as $column) {
        if (($column['field'] ?? '') === 'rowNumber') {
            $hasRowNumberColumn = true;
            break;
        }
    }
    if (!$hasRowNumberColumn) {
        array_unshift($columns, [
            'headerName' => 'No.',
            'field' => 'rowNumber',
            'width' => 70,
            'minWidth' => 70,
            'maxWidth' => 70,
            'flex' => 0,
            'suppressSizeToFit' => true,
            'sortable' => false,
            'filter' => false,
        ]);
    }
    foreach ($rows as $rowIndex => &$row) {
        if (!array_key_exists('rowNumber', $row)) {
            $row['rowNumber'] = $rowIndex + 1;
        }
    }
    unset($row);

    $pageSize = $options['pageSize'] ?? 25;
    $height = $options['height'] ?? 'standard';
    $heightClass = match ($height) {
        'compact' => 'cliniq-ag-grid-compact',
        'fill' => 'cliniq-ag-grid-fill',
        'patient-registry' => 'cliniq-ag-grid-patient-registry',
        default => 'cliniq-ag-grid-standard',
    };
    $searchInput = $options['searchInput'] ?? '';
    $fitColumns = $options['fitColumns'] ?? true;
    $pagination = !empty($options['pagination']);
    $paginationControls = (string) ($options['paginationControls'] ?? '');
    $rowHeight = max(40, (int) ($options['rowHeight'] ?? 70));

    echo '<div id="' . e($gridId) . '" class="cliniq-ag-grid ag-theme-quartz ' . $heightClass . '" data-ag-grid ' .
         ($searchInput ? 'data-search-input="' . e($searchInput) . '" ' : '') .
         ($paginationControls ? 'data-pagination-controls="' . e($paginationControls) . '" ' : '') .
         ($fitColumns ? 'data-fit-columns="true" ' : 'data-fit-columns="false" ') .
         ($pagination ? 'data-pagination="true" ' : 'data-pagination="false" ') .
         'data-row-height="' . $rowHeight . '" ' .
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
