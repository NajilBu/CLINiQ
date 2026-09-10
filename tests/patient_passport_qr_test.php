<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/patient-portal/patient-passport.php');
$script = file_get_contents($root . '/public/assets/js/patient-passport-qr.js');
$library = $root . '/public/assets/vendor/qrcode/qrcode.min.js';

$assertions = [
    'patient page builds the deployed emergency passport URL' => str_contains($page, "../public/emergency.php?token="),
    'QR container receives the patient passport URL' => str_contains($page, 'data-passport-url="<?= student_e($passportUrl) ?>"'),
    'local QR library is loaded' => str_contains($page, '../public/assets/vendor/qrcode/qrcode.min.js'),
    'QR renderer encodes the provided passport URL' => str_contains($script, 'text: passportUrl'),
    'download button saves the rendered QR image' => str_contains($script, "canvas.toDataURL('image/png')"),
    'old QR placeholder is removed' => !str_contains($page, 'Inline SVG QR placeholder'),
    'old QR download alert is removed' => !str_contains($page, 'QR download will be connected to the backend.'),
    'NFC placeholder is removed' => !str_contains($page, 'NFC write feature requires a physical NFC device.'),
    'NFC write control is available' => str_contains($page, 'id="write-passport-nfc"'),
    'NFC status is announced accessibly' => str_contains($page, 'id="passport-nfc-status"') && str_contains($page, 'aria-live="polite"'),
    'NFC support is feature detected' => str_contains($script, "'NDEFReader' in window"),
    'NFC writer stores the passport URL' => str_contains($script, "recordType: 'url'") && str_contains($script, 'data: passportUrl'),
    'NFC writing requires a secure context' => str_contains($script, 'window.isSecureContext'),
    'patient controls BMI passport visibility' => str_contains($page, 'name="show_bmi_on_passport"') && str_contains($page, 'role="switch"'),
    'patient preview follows BMI visibility' => str_contains($page, 'id="prev-bmi-metric"') && str_contains($page, 'syncBmiVisibility'),
    'local QR library file exists' => is_file($library) && filesize($library) > 10000,
];

$failures = [];
foreach ($assertions as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Patient passport QR test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Patient passport QR test passed (" . count($assertions) . " assertions).\n";
