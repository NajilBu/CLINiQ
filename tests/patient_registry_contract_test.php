<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/public/patients/index.php');
$profileService = file_get_contents(dirname(__DIR__) . '/app/services/CliniqPatientProfile.php');
$view = file_get_contents(dirname(__DIR__) . '/app/helpers/view.php');

function patient_registry_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

patient_registry_assert(str_contains((string) $source, "['headerName' => 'Sex', 'field' => 'sexIconHtml'"), 'Patient registry must label the sex indicator column.');
patient_registry_assert(str_contains((string) $source, "'sexIconHtml' => \$sexIconHtml"), 'Patient registry must preserve the existing sex icon rendering.');
patient_registry_assert(!str_contains((string) $source, "['headerName' => 'No.', 'field' => 'rowNumber'"), 'Patient registry must not define a row-number column.');
patient_registry_assert(str_contains((string) $source, "'showRowNumber' => false"), 'Patient registry must disable the shared row-number column.');
patient_registry_assert(str_contains((string) $view, "\$showRowNumber = \$options['showRowNumber'] ?? true;"), 'Shared grids must retain row numbers by default.');
patient_registry_assert(str_contains((string) $source, "'rowUrl' => 'view.php?id=' . (int)\$patient['id']"), 'Patient registry rows must keep linking to patient profiles.');
patient_registry_assert(str_contains((string) $source, "trim((string) (\$patient['guardian_contact'] ?? '')) !== ''"), 'Patient registry contact status must check the primary contact.');
patient_registry_assert(str_contains((string) $source, "trim((string) (\$patient['secondary_contact'] ?? '')) !== ''"), 'Patient registry contact status must check the secondary contact.');
patient_registry_assert(str_contains((string) $source, "'contactStatusHtml' => \$hasContact"), 'Patient registry must render contact status instead of a contact number.');
patient_registry_assert(!str_contains((string) $source, "'guardianContact' => \$patient['guardian_contact']"), 'Patient registry must not put raw primary contact numbers in grid rows.');
patient_registry_assert(str_contains((string) $source, 'On file') && str_contains((string) $source, 'Missing'), 'Patient registry must show contact availability states.');
patient_registry_assert(str_contains((string) $profileService, 'MAX(visit_datetime) AS last_visit_at'), 'Patient registry data must include each patient\'s latest visit.');
patient_registry_assert(str_contains((string) $source, "'headerName' => 'Account Status'"), 'Patient registry must include account status.');
patient_registry_assert(str_contains((string) $source, 'No account'), 'Patient registry must handle patients without accounts.');
patient_registry_assert(str_contains((string) $source, "'headerName' => 'Last Visit'"), 'Patient registry must include last visit.');
patient_registry_assert(str_contains((string) $source, 'Never visited'), 'Patient registry must handle patients without visits.');
patient_registry_assert(str_contains((string) $source, "'sortField' => 'lastVisitSort', 'sortType' => 'date'"), 'Patient registry must sort last visits by their timestamp.');

echo "Patient registry contract passed.\n";
