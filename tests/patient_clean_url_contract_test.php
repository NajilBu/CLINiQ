<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gateway = file_get_contents($root . '/docker/public-gateway.conf');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');

if ($gateway === false || $layout === false) {
    throw new RuntimeException('Unable to read clean-URL source.');
}

$routes = [
    '/' => 'patient-welcome.php',
    '/login' => 'patient-login.php',
    '/signup' => 'patient-register.php',
    '/forgot-password' => 'patient-forgot-password.php',
    '/reset-password' => 'patient-reset-password.php',
    '/onboarding' => 'patient-onboarding.php',
    '/dashboard' => 'patient-dashboard.php',
    '/ape-status' => 'patient-ape-status.php',
    '/ape-document' => 'patient-ape-document.php',
    '/appointments' => 'patient-appointment.php',
    '/health-passport' => 'patient-passport.php',
    '/help' => 'patient-help.php',
    '/feedback' => 'patient-feedback.php',
    '/feedback/consent' => 'patient-feedback-consent.php',
];

foreach ($routes as $url => $script) {
    if (!str_contains($gateway, $url === '/' ? 'location = / {' : 'location = ' . $url)) {
        throw new RuntimeException('Missing public route: ' . $url);
    }
    if (!str_contains($gateway, '/patient-portal/' . $script)) {
        throw new RuntimeException('Missing upstream handler for: ' . $url);
    }
    if (!str_contains($gateway, '/patient-portal/' . $script . '$is_args$args')) {
        throw new RuntimeException('Clean route must preserve query parameters: ' . $url);
    }
}

foreach ([
    'map $request_method $cliniq_legacy_portal_redirect',
    'GET 1;',
    'HEAD 1;',
    'return 301 $cliniq_clean_portal_url$is_args$args;',
    'function student_portal_url(string $route, array $query = []): string',
    'function student_portal_route_for_script(?string $script = null): string',
    'href="/patient-portal/assets/css/patient.css',
    'src="/public/assets/js/csrf.js?v=2"',
] as $marker) {
    if (!str_contains($gateway . $layout, $marker)) {
        throw new RuntimeException('Clean URL contract is missing: ' . $marker);
    }
}

foreach ([
    $root . '/app/services/ApeCycleService.php',
    $root . '/app/services/PatientAccessStatus.php',
    $root . '/app/services/PatientEmail.php',
    $root . '/app/services/PatientNotification.php',
    $root . '/app/services/PatientPasswordResetService.php',
    $root . '/public/assets/js/patient-passport-qr.js',
] as $file) {
    $source = file_get_contents($file);
    if ($source === false || preg_match('/(?<![A-Za-z0-9_-])patient-(?:login|register|reset-password|dashboard|ape-status|appointment|feedback|nfc-authorize)\\.php/', $source)) {
        throw new RuntimeException('Legacy student portal URL remains in: ' . basename($file));
    }
}

foreach (['patient-login.php', 'patient-forgot-password.php', 'patient-reset-password.php'] as $page) {
    $source = file_get_contents($root . '/patient-portal/' . $page);
    if ($source === false || str_contains($source, '../public/index.php') || !str_contains($source, "student_portal_url('welcome')")) {
        throw new RuntimeException('Authentication home link is not clean in: ' . $page);
    }
}

echo "Patient clean URL contract test passed.\n";
