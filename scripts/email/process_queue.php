<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/PatientEmail.php';

try {
    patient_email_due_automations();
    $result = patient_email_process_queue(null, 50);
    echo json_encode($result, JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[CLINiQ Email Queue] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
