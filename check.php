<?php
require 'app/config/database.php';
require 'app/services/ApeWorkflow.php';
require 'app/helpers/view.php';
$db = db();
$stmt = $db->query("SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section FROM ape_records a JOIN patients p ON p.id = a.patient_id");
$allRecords = $stmt->fetchAll();

$apeRows = [];
foreach ($allRecords as $rec) {
    $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
    $next = ape_next_action($rec);
    $priority = ape_priority_badge($rec);
    $apeRows[] = [
        'priorityHtml' => '<span class="badge ' . e($priority['class']) . '">' . e($priority['label']) . '</span>',
        'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($rec['student_number']) . '</div></div></div>',
        'programHtml' => '<p class="text-sm font-bold text-slate-700 mb-1">' . e($rec['course_section'] ?: 'No course set') . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e($rec['document_type'] ?: 'APE documents') . '</p>',
        'waiting' => ape_waiting_label($rec),
        'nextActionHtml' => '<div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[18px]">' . e($next['icon']) . '</span><div><strong class="block text-sm text-slate-800">' . e($next['label']) . '</strong><span class="block text-xs font-bold text-slate-400">' . e(ape_missing_item($rec)) . '</span></div></div>',
        'actionHtml' => '<a href="view.php?id=' . (int)$rec['id'] . '" class="btn btn-primary btn-sm text-decoration-none"><span class="material-symbols-outlined text-[14px]">' . e($next['icon']) . '</span>' . e($next['label']) . '</a>',
    ];
}

$json = json_encode($apeRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($json === false) {
    echo "JSON Encode Error: " . json_last_error_msg();
} else {
    echo "JSON Encode Success, length: " . strlen($json);
}
