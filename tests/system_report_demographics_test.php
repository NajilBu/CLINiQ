<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';
require_once dirname(__DIR__) . '/app/services/SystemReportRenderer.php';

$labels = system_report_module_labels();
if (($labels['demographics'] ?? null) !== 'Patient Demographics'
    || normalize_system_report_modules(['demographics']) !== ['demographics']) {
    throw new RuntimeException('Patient Demographics is not a selectable report section.');
}

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

$sectionStart = strpos($source, "if (in_array('demographics', \$modules, true))");
$nextSection = strpos($source, "if (in_array('appointments', \$modules, true))");
if ($sectionStart === false || $nextSection === false || $nextSection <= $sectionStart) {
    throw new RuntimeException('Patient Demographics report section is missing.');
}
$section = substr($source, $sectionStart, $nextSection - $sectionStart);

foreach ([
    'SELECT person_id AS patient_person_id FROM patients',
    'All registered patients, counted once.',
    'Registered Patients',
    "system_report_metric('Students'",
    "system_report_metric('Faculty'",
    "system_report_metric('NTP'",
    'Birthdate Recorded',
    'Sex Recorded',
    'Patients by Age Group',
    'Patients by Recorded Sex',
    'Patients by Type',
    'Students by College',
    'Faculty and Personnel by Department',
    'TIMESTAMPDIFF(YEAR, p.birthdate, ?)',
    'LEFT JOIN departments d ON d.id = pr.department_id',
    'LEFT JOIN departments d ON d.id = se.department_id',
] as $expected) {
    if (!str_contains($section, $expected)) {
        throw new RuntimeException("Patient Demographics is missing {$expected}.");
    }
}

if (str_contains($section, 'FROM visits') || str_contains($section, 'JOIN visits')) {
    throw new RuntimeException('Patient Demographics must not depend on clinic visits.');
}

if (system_report_chart_type('Patients by Recorded Sex') !== 'donut') {
    throw new RuntimeException('The recorded-sex breakdown should use a donut chart.');
}

echo "System report demographics checks passed. No database writes.\n";
