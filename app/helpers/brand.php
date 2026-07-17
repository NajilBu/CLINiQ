<?php

require_once __DIR__ . '/../services/SystemSettings.php';

function cliniq_brand_e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function render_cliniq_entry_header(array $options = []): void
{
    $clinicProfile = clinic_profile_settings();
    $homeUrl = $options['homeUrl'] ?? '#';
    $logoUrl = $options['logoUrl'] ?? 'assets/img/clinic-logo.png';
    $class = trim('cliniq-entry-header ' . ($options['class'] ?? ''));
    $systemName = $options['systemName'] ?? $clinicProfile['system_name'];
    $department = $options['department'] ?? $clinicProfile['department'];
    $brandLabel = $options['brandLabel'] ?? 'Go to ' . $systemName . ' access portal';
    ?>
    <header class="<?= cliniq_brand_e($class) ?>" aria-label="CLINiQ navigation">
        <a href="<?= cliniq_brand_e($homeUrl) ?>" class="cliniq-entry-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Back
        </a>
        <span class="cliniq-entry-divider" aria-hidden="true"></span>
        <a href="<?= cliniq_brand_e($homeUrl) ?>" class="cliniq-entry-brand" aria-label="<?= cliniq_brand_e($brandLabel) ?>">
            <span class="cliniq-entry-logo">
                <img src="<?= cliniq_brand_e($logoUrl) ?>" alt="<?= cliniq_brand_e($department) ?> logo">
            </span>
            <span class="cliniq-entry-copy">
                <span class="cliniq-entry-name"><?= cliniq_brand_e($systemName) ?></span>
                <span class="cliniq-entry-subtitle"><?= cliniq_brand_e($department) ?></span>
            </span>
        </a>
    </header>
    <?php
}
