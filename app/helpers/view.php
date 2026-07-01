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
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | CLINiQ</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="<?= app_url('assets/css/app.css') ?>" rel="stylesheet">
    </head>
    <body>
    <nav class="navbar navbar-expand-lg border-bottom bg-white">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold" href="<?= app_url('dashboard.php') ?>">CLINiQ</a>
            <?php if ($user): ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="nav">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item"><a class="nav-link" href="<?= app_url('patients/index.php') ?>">Patients</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= app_url('visits/index.php') ?>">Visits</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= app_url('alerts/index.php') ?>">Alerts</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= app_url('inventory/index.php') ?>">Inventory</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= app_url('reports/index.php') ?>">Reports</a></li>
                    </ul>
                    <span class="navbar-text me-3"><?= e($user['name']) ?></span>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= app_url('logout.php') ?>">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
    <main class="container-fluid py-4">
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= app_url('assets/js/app.js') ?>"></script>
    </body>
    </html>
    <?php
}
