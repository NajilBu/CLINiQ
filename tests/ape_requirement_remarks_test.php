<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ApeWorkflow.php';

$requirements = [
    ['requirement_id' => 11, 'requirement_name' => 'Lab Request Form', 'status' => 'Missing', 'remarks' => null],
    ['requirement_id' => 12, 'requirement_name' => 'TB Cert', 'status' => 'Missing', 'remarks' => 'Previous TB note'],
];
$remarks = [
    11 => 'Individual lab remark',
    12 => 'Individual TB remark',
];

foreach (['correction', 'follow_up'] as $mode) {
    $plan = ape_hard_copy_review_plan($requirements, $mode, [11], 'Shared document instructions', $remarks);
    $updates = array_column($plan['updates'], null, 'requirement_id');

    if (($updates[11]['remarks'] ?? null) !== 'Individual lab remark') {
        throw new RuntimeException("Selected {$mode} documents must retain their individual remarks.");
    }
    if (($updates[12]['remarks'] ?? null) !== 'Individual TB remark') {
        throw new RuntimeException("Unselected {$mode} documents must retain their individual remarks.");
    }
    if (!str_contains($plan['notes'], 'Shared document instructions')) {
        throw new RuntimeException("Shared {$mode} instructions must remain in the review summary.");
    }
}

$script = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
if (str_contains($script, 'input.readOnly = selected')) {
    throw new RuntimeException('Selecting a correction or follow-up document must not lock its remarks field.');
}

$view = file_get_contents(dirname(__DIR__) . '/public/ape/view.php');
if (!str_contains($view, 'name="requirement_remarks[')) {
    throw new RuntimeException('The examination form must submit individual requirement remarks.');
}
if (!str_contains($view, '<fieldset <?= $requirementsLocked ? \'disabled\' : \'\' ?>')) {
    throw new RuntimeException('Saved examination remarks must remain locked.');
}

echo "APE requirement remarks test passed. No database writes.\n";
