<?php

function cliniq_brand_e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function render_cliniq_entry_header(array $options = []): void
{
    $homeUrl = $options['homeUrl'] ?? '#';
    $logoUrl = $options['logoUrl'] ?? 'assets/img/clinic-logo.png';
    $class = trim('cliniq-entry-header ' . ($options['class'] ?? ''));
    $brandLabel = $options['brandLabel'] ?? 'Go to CLINiQ access portal';
    ?>
    <header class="<?= cliniq_brand_e($class) ?>" aria-label="CLINiQ navigation">
        <a href="<?= cliniq_brand_e($homeUrl) ?>" class="cliniq-entry-back">
            <span class="material-symbols-outlined">arrow_back</span>
            Back
        </a>
        <span class="cliniq-entry-divider" aria-hidden="true"></span>
        <a href="<?= cliniq_brand_e($homeUrl) ?>" class="cliniq-entry-brand" aria-label="<?= cliniq_brand_e($brandLabel) ?>">
            <span class="cliniq-entry-logo">
                <img src="<?= cliniq_brand_e($logoUrl) ?>" alt="PLP Health Services Department logo">
            </span>
            <span class="cliniq-entry-copy">
                <span class="cliniq-entry-name">CLINiQ</span>
                <span class="cliniq-entry-subtitle">University Health Services</span>
            </span>
        </a>
    </header>
    <?php
}
