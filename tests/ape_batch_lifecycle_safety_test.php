<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/ApeCycleService.php');
$cohortMigration = file_get_contents($root . '/database/migrations/20261012_create_ape_batch_cohorts.sql');
$scheduling = file_get_contents($root . '/public/ape/scheduling.php');
$index = file_get_contents($root . '/public/ape/index.php');

if ($service === false || $scheduling === false || $index === false || $cohortMigration === false) {
    throw new RuntimeException('APE batch lifecycle sources must be readable.');
}

$createBatchStart = strpos($service, 'function create_ape_schedule_batch(');
$createBatchEnd = strpos($service, 'function cancel_ape_schedule_batch(', $createBatchStart ?: 0);
if ($createBatchStart === false || $createBatchEnd === false) {
    throw new RuntimeException('APE batch creation function must be readable.');
}
$createBatch = substr($service, $createBatchStart, $createBatchEnd - $createBatchStart);

$checks = [
    'candidate list excludes inactive and examined records' => str_contains($service, "AND ar.workflow_status NOT IN ('Inactive', 'Cleared')")
        && str_contains($service, 'AND ar.exam_date IS NULL'),
    'locked assignment repeats the eligibility restrictions' => str_contains($createBatch, "AND ar.workflow_status NOT IN ('Inactive', 'Cleared')")
        && str_contains($createBatch, 'AND ar.exam_date IS NULL')
        && str_contains($createBatch, "pt.access_status = 'Official'"),
    'same-day batch starts must be in the future' => str_contains($service, "\$scheduleDate === date('Y-m-d') && \$startTime <= date('H:i:s')"),
    'cancellation is blocked once a batch starts' => str_contains($service, 'TIMESTAMP(schedule_date, start_time) > NOW()'),
    'doctors can manage batches from the page and queue' => str_contains($scheduling, "['admin', 'doctor']")
        && str_contains($index, "['admin', 'doctor']"),
    'email queueing runs after the batch transaction commits' => (($commit = strpos($createBatch, '$db->commit();')) !== false)
        && (($email = strpos($createBatch, 'patient_email_queue_notification(', $commit)) !== false)
        && $email > $commit
        && str_contains($createBatch, 'catch (Throwable $emailError)'),
    'cycle intake seeds applicants only' => str_contains($service, "pt.access_status = 'Applicant'"),
    'official cohorts are durable and cancel-safe' => str_contains($service, 'ape_schedule_batch_cohorts')
        && str_contains($service, "b.status <> 'Cancelled'")
        && str_contains($cohortMigration, 'uq_ape_batch_cohort'),
    'cohorts require complete official placements' => str_contains($service, 'AND s.program_id IS NOT NULL')
        && str_contains($service, "AND TRIM(COALESCE(s.year_level, '')) <> ''")
        && str_contains($service, "AND TRIM(COALESCE(s.section, '')) <> ''"),
    'candidate cohorts compare mixed-collation student fields safely' => str_contains($service, 'c.year_level COLLATE utf8mb4_unicode_ci = s.year_level COLLATE utf8mb4_unicode_ci')
        && str_contains($service, 'c.section COLLATE utf8mb4_unicode_ci = s.section COLLATE utf8mb4_unicode_ci'),
    'legacy records adopted at cycle start remain applicant-only' => str_contains($service, "pt.access_status = 'Applicant'"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed: ' . $label);
    }
}

echo 'APE batch lifecycle safety tests passed (' . count($checks) . " assertions).\n";
