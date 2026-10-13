<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/helpers/student_id.php';

if (visit_id_number_candidates('23-00262') !== ['23-00262', '23-00262']) {
    throw new RuntimeException('Student lookups must retain their canonical hyphenated form.');
}
if (visit_id_number_candidates('0000002') !== ['0000002', '0000002']) {
    throw new RuntimeException('Leading zeros must be preserved without reformatting numeric IDs.');
}
if (is_valid_id_number('123-4567')) {
    throw new RuntimeException('Malformed IDs must be rejected.');
}
if (visit_id_number_candidates('2300211', true) !== ['2300211', '23-00211']) {
    throw new RuntimeException('Visit lookup must try the compact student form after an exact ID.');
}
if (visit_id_number_candidates('0000002', true) !== ['0000002', '0000002']) {
    throw new RuntimeException('Leading-zero staff IDs must not be reformatted as student IDs.');
}
$visitorRegistration = file_get_contents(dirname(__DIR__) . '/public/visitor-registration.php') ?: '';
if (!str_contains($visitorRegistration, 'cliniq_visit_patient_by_id_number($identifier, true)') || !str_contains($visitorRegistration, "'id_number' => \$patient['id_number']")) {
    throw new RuntimeException('Visitor lookup must prefer an exact ID and return its canonical identifier.');
}
if (!str_contains($visitorRegistration, "header('Cache-Control: no-store')") || !str_contains($visitorRegistration, 'fullName.value = patient.name ||')) {
    throw new RuntimeException('Local kiosk lookup must be uncached and populate the verified identity fields.');
}
echo "Visit ID matching checks passed. No database writes.\n";
