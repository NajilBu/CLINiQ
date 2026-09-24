<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ApeWorkflow.php';

function expect_requirement_remarks(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$record = [
    'ape_id' => 42,
    'id' => 42,
    'patient_id' => 99,
    'exam_date' => '2026-09-25',
    'workflow_status' => 'Follow-up Required',
    'clearance_status' => 'For Follow-up',
    'follow_up_required' => 1,
];
$requirements = [[
    'requirement_id' => 11,
    'requirement_name' => 'TB clearance certificate',
    'status' => 'Needs Correction',
    'remarks' => 'Please submit a current TB clearance signed by your specialist.',
    'upload_group' => 'follow_up',
    'upload_due_date' => '2026-10-02',
]];

expect_requirement_remarks(
    ape_phase_three_review_group($record, $requirements) === 'follow_up',
    'A returned requirement must remain in the Follow-up Phase 3 group.'
);

$actions = ape_normalized_action_items($record, $requirements, []);
expect_requirement_remarks(
    ($actions[0]['action_type'] ?? '') === 'document_correction',
    'A returned requirement must create the unified document-correction action.'
);
expect_requirement_remarks(
    ($actions[0]['description'] ?? '') === $requirements[0]['remarks'],
    'The patient correction action must preserve the clinic instructions saved on the requirement.'
);
expect_requirement_remarks(
    ($actions[0]['due_at'] ?? '') === '2026-10-02',
    'The patient correction action must retain the requirement return date.'
);

$view = file_get_contents(dirname(__DIR__) . '/public/ape/view.php');
expect_requirement_remarks($view !== false, 'The APE review page must be readable.');
expect_requirement_remarks(
    str_contains($view, "\$missingItems = trim((string) (\$_POST['missing_items'] ?? ''));"),
    'Phase 3 returns must require clinic correction instructions.'
);
expect_requirement_remarks(
    str_contains($view, "\$returnSchedule = ape_return_schedule((string) (\$_POST['follow_up_due_date'] ?? ''));"),
    'Phase 3 returns must require a valid due date.'
);
expect_requirement_remarks(
    str_contains($view, "UPDATE ape_requirements SET status = 'Needs Correction', upload_group = 'follow_up', upload_due_date = ?, remarks = ?"),
    'Phase 3 returns must store instructions and the due date on the affected follow-up requirement.'
);
expect_requirement_remarks(
    str_contains($view, 'name="missing_items"') && str_contains($view, 'name="follow_up_due_date"'),
    'The Phase 3 return panel must expose both required staff fields.'
);

echo "APE Phase 3 requirement instruction test passed. No database writes.\n";
