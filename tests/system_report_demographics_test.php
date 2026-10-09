<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';
require_once dirname(__DIR__) . '/app/services/SystemReportRenderer.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the report builder.');
}

if (array_key_exists('demographics', system_report_module_labels())
    || str_contains($source, 'Patient Demographics')
    || str_contains($source, 'Patients by Recorded Sex')) {
    throw new RuntimeException('The transaction summary must exclude patient demographics.');
}

echo "Transaction summary excludes patient demographics. No database writes.\n";
