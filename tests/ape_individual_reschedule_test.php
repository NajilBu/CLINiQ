<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/ApeCycleService.php');
$view = file_get_contents($root . '/public/ape/view.php');

if ($service === false || $view === false) {
    throw new RuntimeException('APE reschedule sources must be readable.');
}

foreach (['function reschedule_ape_record', 'FOR UPDATE', 'TIMESTAMP(schedule_date, end_time) > NOW()', 'The selected APE batch is already full.', 'APE schedule updated', "'ape_schedule_updated'", "'ape_schedule_updates'"] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("APE reschedule service is missing {$marker}.");
    }
}
foreach (['reschedule_ape_record', 'target_batch_id', 'Move this student to another batch', 'Reschedule student'] as $marker) {
    if (!str_contains($view, $marker)) {
        throw new RuntimeException("APE reschedule UI is missing {$marker}.");
    }
}

echo "APE individual reschedule coverage test passed.\n";
