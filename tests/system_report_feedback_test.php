<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$labels = system_report_module_labels();
$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');

if (($labels['feedback'] ?? null) !== 'Clinic Feedback') {
    throw new RuntimeException('Clinic Feedback is missing from the report module list.');
}

foreach ([
    "in_array('feedback', \$modules, true)",
    'Average Overall Score',
    'Average SERVPERF Scores',
    'Responses by Service',
    'Feedback Performance',
    'Feedback by Day',
    'Written Comments',
    "'Average SERVPERF Scores', \$feedbackDimensions, 'No feedback was submitted during this period.', 2",
] as $expected) {
    if (!str_contains((string) $source, $expected)) {
        throw new RuntimeException("Feedback report is missing {$expected}.");
    }
}

echo "System report feedback checks passed. No database writes.\n";
