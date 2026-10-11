<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemReport.php';

$source = file_get_contents(dirname(__DIR__) . '/app/services/SystemReport.php');

if (!array_key_exists('feedback', system_report_module_labels())
    || !str_contains((string) $source, 'Clinic Feedback')
    || !str_contains((string) $source, 'Average SERVPERF Scores')
    || !str_contains((string) $source, "['axis_max' => 7]")
    || !str_contains((string) $source, "\$excellentResponses . ' excellent, ' . \$satisfactoryResponses . ' satisfactory'")
    || str_contains((string) $source, "system_report_chart('Responses by Service'")
    || !str_contains((string) $source, 'All Responses by Service')) {
    throw new RuntimeException('Clinic feedback analytics must use a fixed SERVPERF scale, retain complete service detail, and avoid duplicating it as a chart.');
}

echo "Clinic feedback analytics checks passed. No database writes.\n";
