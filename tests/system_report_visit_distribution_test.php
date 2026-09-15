<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReportRenderer.php';
require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

$visitsStart = strpos($source, "if (in_array('visits', \$modules, true))");
$appointmentsStart = strpos($source, "if (in_array('appointments', \$modules, true))");
if ($visitsStart === false || $appointmentsStart === false) {
    throw new RuntimeException('Expected report sections were not found.');
}

$visitsSection = substr($source, $visitsStart, $appointmentsStart - $visitsStart);

foreach (['Visits by Recorded Sex', 'APE Clearance Status (from APE records)', 'APE Finding Result (from APE findings)'] as $title) {
    if (!str_contains($visitsSection, "system_report_chart('{$title}'")) {
        throw new RuntimeException("Visits report is missing {$title}.");
    }
    if (system_report_chart_type($title) !== 'donut') {
        throw new RuntimeException("{$title} should use a donut chart.");
    }
}

if (array_key_exists('ape', system_report_module_labels())
    || str_contains($source, "if (in_array('ape', \$modules, true))")
    || str_contains($source, "\$sections['ape']")) {
    throw new RuntimeException('APE should not appear as a standalone report section.');
}

if (!str_contains($visitsSection, 'JOIN people p ON p.id = v.patient_person_id')
    || !str_contains($visitsSection, "COALESCE(NULLIF(p.sex, ''), 'Not specified') label")
    || !str_contains($visitsSection, 'The APE charts below summarize APE records separately')) {
    throw new RuntimeException('Visit sex source or APE distinction is missing.');
}

echo "System report visit distribution checks passed. No database writes.\n";
