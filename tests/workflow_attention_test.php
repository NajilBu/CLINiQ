<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/WorkflowAttention.php');
$center = file_get_contents($root . '/app/services/ClinicWorkCenter.php');
$page = file_get_contents($root . '/public/audit/index.php');
if ($service === false || $center === false || $page === false) {
    throw new RuntimeException('Unable to read Workflow Attention sources.');
}

foreach (['clinic_work_center_items', 'clinic_work_center_summary', 'clinic_work_center_export', "'workflow'", "'email'", 'compose_email', 'retry_email', 'view_email'] as $expected) {
    if (!str_contains($center, $expected)) {
        throw new RuntimeException("Combined work center is missing {$expected}.");
    }
}

foreach ([
    'workflow_attention_items',
    'workflow_attention_age_bucket',
    'workflow_attention_age_matches',
    'workflow_attention_summary',
    'ape_fetch_records',
    'ape_record_queue',
    'ape_patient_document_action_summaries',
    'nurse_alerts',
    'patient_notifications',
    'student_school_year_enrollments',
    "'waiting_on_patient'",
    "'clinic_action'",
    "'overdue'",
    'email_relevant',
    'email_event_type',
] as $expected) {
    if (!str_contains($service, $expected)) {
        throw new RuntimeException("Workflow Attention service is missing {$expected}.");
    }
}

foreach (['What needs attention now?', 'waiting_on_patient', 'Patient-contact work appears here', 'Export email work', 'source_url'] as $expected) {
    if (!str_contains($page, $expected)) {
        throw new RuntimeException("Workflow Attention UI is missing {$expected}.");
    }
}
if (!str_contains($page, 'work_age') || !str_contains($page, 'Age / due status')) {
    throw new RuntimeException('The Email Center age/due filter is missing.');
}
if (str_contains($page, 'href="index.php?tab=workflow"')) {
    throw new RuntimeException('The standalone Workflow Attention navigation tab still exists.');
}

require_once $root . '/app/services/WorkflowAttention.php';
$fixedNow = strtotime('2026-09-23 12:00:00');
$ageCases = [
    ['priority' => 'overdue', 'due_at' => '', 'created_at' => '2026-09-20 10:00:00', 'filter' => 'overdue', 'expected' => true],
    ['priority' => 'clinic_action', 'due_at' => '2026-09-22', 'created_at' => '2026-09-22 10:00:00', 'filter' => 'overdue', 'expected' => true],
    ['priority' => 'clinic_action', 'due_at' => '2026-09-23', 'created_at' => '2026-09-23 10:00:00', 'filter' => 'due_today', 'expected' => true],
    ['priority' => 'clinic_action', 'due_at' => '2026-09-27', 'created_at' => '2026-09-23 10:00:00', 'filter' => 'due_soon', 'expected' => true],
    ['priority' => 'clinic_action', 'due_at' => '2026-10-15', 'created_at' => '2026-09-10 10:00:00', 'filter' => 'older_than_7_days', 'expected' => true],
    ['priority' => 'clinic_action', 'due_at' => '', 'created_at' => '2026-09-23 10:00:00', 'filter' => 'no_due_date', 'expected' => true],
    ['priority' => 'overdue', 'due_at' => '', 'created_at' => '2026-09-23 10:00:00', 'filter' => 'no_due_date', 'expected' => false],
    ['priority' => 'clinic_action', 'due_at' => '2026-10-15', 'created_at' => '2026-09-23 10:00:00', 'filter' => 'due_soon', 'expected' => false],
    ['priority' => 'clinic_action', 'due_at' => '', 'created_at' => '2026-09-10 10:00:00', 'filter' => 'no_due_date', 'expected' => false],
    ['priority' => 'clinic_action', 'due_at' => '', 'created_at' => '2026-09-10 10:00:00', 'filter' => 'older_than_7_days', 'expected' => true],
];
foreach ($ageCases as $case) {
    $actual = workflow_attention_age_matches($case, $case['filter'], $fixedNow);
    if ($actual !== $case['expected']) {
        throw new RuntimeException('Age/due classification failed for ' . $case['filter'] . '.');
    }
}

echo "Workflow Attention structural test passed.\n";
