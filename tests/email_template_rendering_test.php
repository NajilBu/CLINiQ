<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mail = file_get_contents($root . '/app/helpers/mail.php');
$service = file_get_contents($root . '/app/services/PatientEmail.php');
if ($mail === false || $service === false) {
    throw new RuntimeException('Unable to read email template sources.');
}

require_once $root . '/app/helpers/mail.php';
$rendered = cliniq_mail_template_interpolate(
    '[{{clinic_name}}] APE follow-up for {{patient_name}}',
    ['clinic_name' => 'CLINiQ', 'patient_name' => 'Alex Student']
);
if ($rendered !== '[CLINiQ] APE follow-up for Alex Student') {
    throw new RuntimeException('Email template placeholders did not render correctly.');
}
foreach (['$origin === \'manual\'', 'manualTemplateContext', 'cliniq_mail_template_interpolate($subject, $manualTemplateContext)', 'cliniq_mail_template_interpolate($message, $manualTemplateContext)'] as $contract) {
    if (!str_contains($service, $contract)) {
        throw new RuntimeException("Manual email template rendering is missing {$contract}.");
    }
}

echo "Email template rendering test passed.\n";
