<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$visitor = file_get_contents($root . '/public/visitor-registration.php');
$brand = file_get_contents($root . '/app/helpers/brand.php');

if (!str_contains($visitor, "'showBack' => false")) {
    throw new RuntimeException('Visitor registration must hide the shared Back button.');
}

if (!str_contains($visitor, 'render_cliniq_entry_header([')) {
    throw new RuntimeException('Visitor registration must keep using the shared CLINiQ entry header.');
}

if (!str_contains($brand, '($options[\'showBack\'] ?? true)')) {
    throw new RuntimeException('Shared entry header must keep Back enabled by default for other pages.');
}

if (!str_contains($brand, 'class="cliniq-entry-brand"')) {
    throw new RuntimeException('Shared entry header must continue rendering the CLINiQ brand.');
}

if (!str_contains($visitor, 'class="visit-success-back-button btn btn-primary text-decoration-none"')) {
    throw new RuntimeException('The success state must provide the enlarged Back to form action.');
}

if (!str_contains($visitor, 'href="visitor-registration.php"')) {
    throw new RuntimeException('Back to form must use a same-origin relative registration URL.');
}

if (!str_contains($visitor, 'Give feedback about a clinic visit')
    || !str_contains($visitor, "app_url('clinic-feedback.php')")
    || !str_contains($visitor, 'rounded-full border border-primary/25')) {
    throw new RuntimeException('Visitor registration must render the restored legacy feedback pill.');
}

echo "Visitor registration header test passed.\n";
