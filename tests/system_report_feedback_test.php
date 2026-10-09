<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');

if (array_key_exists('feedback', system_report_module_labels())
    || str_contains((string) $source, 'Clinic Feedback')
    || str_contains((string) $source, 'Average SERVPERF Scores')) {
    throw new RuntimeException('The transaction summary must exclude clinic feedback.');
}

echo "Transaction summary excludes clinic feedback. No database writes.\n";
