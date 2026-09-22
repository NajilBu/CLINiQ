<?php

declare(strict_types=1);

$viewPath = __DIR__ . '/../app/helpers/view.php';
$cssPath = __DIR__ . '/../public/assets/css/app.css';
$view = file_get_contents($viewPath);
$css = file_get_contents($cssPath);

if ($view === false || $css === false) {
    throw new RuntimeException('Unable to read staff sidebar sources.');
}

foreach (['Overview', 'People & Records', 'Clinical Operations', 'Resources & Reports', 'Administration'] as $group) {
    if (!str_contains($view, "'group' => '{$group}'")) {
        throw new RuntimeException("Missing staff sidebar group: {$group}");
    }
}

foreach (['dashboard.php', 'patients/index.php', 'visits/index.php', 'alerts/index.php', 'ape/index.php', 'appointments/index.php', 'settings/index.php'] as $destination) {
    if (!str_contains($view, "app_url('{$destination}')")) {
        throw new RuntimeException("Existing staff navigation destination was removed: {$destination}");
    }
}

foreach (['staff_sidebar_action_counts', 'app-nav-badge', 'aria-label="Staff navigation"', 'sidebar-collapsed .app-nav-badge'] as $marker) {
    if (!str_contains($view . $css, $marker)) {
        throw new RuntimeException("Missing sidebar action-counter behavior: {$marker}");
    }
}

if (!str_contains($css, '.app-nav-group-title') || !str_contains($css, 'display: none;')) {
    throw new RuntimeException('Collapsed sidebar group-label behavior is missing.');
}

if (!str_contains($css, 'font-size: 0.62rem;') || !str_contains($css, 'font-weight: 500;') || !str_contains($css, 'var(--cliniq-primary) 72%')) {
    throw new RuntimeException('Sidebar group labels do not use the approved compact muted-accent styling.');
}

if (!str_contains($css, '.app-nav-link {') || !str_contains($css, 'font-size: 0.84rem;') || !str_contains($css, 'font-weight: 500;')) {
    throw new RuntimeException('Sidebar navigation links no longer use the original typography.');
}

echo "Staff sidebar navigation structure passed.\n";
