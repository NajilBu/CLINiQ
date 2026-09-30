<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/ApeCycleService.php');
$scheduling = file_get_contents($root . '/public/ape/scheduling.php');
$index = file_get_contents($root . '/public/ape/index.php');

if ($service === false || $scheduling === false || $index === false) {
    throw new RuntimeException('APE batch lifecycle sources must be readable.');
}

$checks = [
    'candidate list excludes inactive and examined records' => str_contains($service, "AND ar.workflow_status NOT IN ('Inactive', 'Cleared')")
        && str_contains($service, 'AND ar.exam_date IS NULL'),
    'locked assignment repeats the eligibility restrictions' => substr_count($service, "AND ar.workflow_status NOT IN ('Inactive', 'Cleared')") >= 2
        && substr_count($service, 'AND ar.exam_date IS NULL') >= 2,
    'same-day batch starts must be in the future' => str_contains($service, "\$scheduleDate === date('Y-m-d') && \$startTime <= date('H:i:s')"),
    'cancellation is blocked once a batch starts' => str_contains($service, 'TIMESTAMP(schedule_date, start_time) > NOW()'),
    'doctors can manage batches from the page and queue' => str_contains($scheduling, "['admin', 'doctor']")
        && str_contains($index, "['admin', 'doctor']"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed: ' . $label);
    }
}

echo 'APE batch lifecycle safety tests passed (' . count($checks) . " assertions).\n";
