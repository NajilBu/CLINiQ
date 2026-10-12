<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/public/patients/view.php');

function patient_care_timeline_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

patient_care_timeline_assert(str_contains((string) $source, "(string) (\$appointment['status'] ?? '') !== 'Completed'"), 'Only completed appointments may appear in the Care Timeline.');
patient_care_timeline_assert(str_contains((string) $source, "'type' => 'appointment'"), 'Completed appointments must be merged into the Care Timeline.');
patient_care_timeline_assert(str_contains((string) $source, "'type' => 'visit'"), 'Existing clinic visits must remain in the Care Timeline.');
patient_care_timeline_assert(str_contains((string) $source, "usort(\$careTimelineRecords"), 'Care Timeline records must be sorted together.');
patient_care_timeline_assert(str_contains((string) $source, 'Clinic appointment'), 'Appointment timeline events need a clear record type label.');
patient_care_timeline_assert(str_contains((string) $source, "appointments/view.php?id=' . (int) (\$appointment['appointment_id'] ?? 0) . '&status=Completed"), 'Completed appointment events must link to their read-only record.');
patient_care_timeline_assert(str_contains((string) $source, '<?php ob_start(); ?>') && str_contains((string) $source, '$alertHistoryHtml = ob_get_clean();'), 'Alert History must be retained for placement after the Care Timeline.');
patient_care_timeline_assert(strpos((string) $source, '<h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Care Timeline</h2>') < strpos((string) $source, '<?= $alertHistoryHtml ?>'), 'Alert History must render below the Care Timeline.');

echo "Patient care timeline checks passed.\n";
