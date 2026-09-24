<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ApeWorkflow.php';

$record = ['ape_id' => 42, 'workflow_status' => 'Follow-up Required'];
$requirements = [
    ['requirement_id' => 1, 'requirement_name' => 'Legacy scan', 'upload_group' => '', 'status' => 'Verified'],
    ['requirement_id' => 2, 'requirement_name' => 'TB clearance', 'upload_group' => 'follow_up', 'status' => 'Submitted'],
];
$documents = [
    ['document_type' => 'TB clearance', 'verification_status' => 'Pending'],
];
$flags = ape_data_quality_flags($record, $requirements, $documents);
$codes = array_column($flags, 'code');

foreach (['missing_upload_group', 'verified_without_archived_file', 'submitted_pending_review'] as $code) {
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException("Expected APE data-quality flag {$code}.");
    }
}

$clearedFlags = ape_data_quality_flags(
    ['ape_id' => 43, 'workflow_status' => 'Cleared'],
    [['requirement_id' => 3, 'requirement_name' => 'Specialist clearance', 'upload_group' => 'follow_up', 'status' => 'Missing']],
    []
);
if (!in_array('follow_up_on_cleared_record', array_column($clearedFlags, 'code'), true)) {
    throw new RuntimeException('Cleared records with active follow-up requirements must be flagged for review.');
}

echo "APE data-quality flag test passed. No database writes.\n";
