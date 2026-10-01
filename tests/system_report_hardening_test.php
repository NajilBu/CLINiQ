<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$builder = file_get_contents($root . '/app/services/SystemReport.php');
$renderer = file_get_contents($root . '/app/services/SystemReportRenderer.php');
$index = file_get_contents($root . '/public/reports/index.php');

if ($builder === false || $renderer === false || $index === false) {
    throw new RuntimeException('Unable to read the report sources.');
}

foreach (['index.php', 'preview.php', 'pdf.php', 'download.php'] as $endpoint) {
    $source = file_get_contents($root . '/public/reports/' . $endpoint);
    if ($source === false || !str_contains($source, 'require_report_access();')) {
        throw new RuntimeException("{$endpoint} must enforce report access.");
    }
}

$checks = [
    'report building does not modify alert schema' => !str_contains($builder, 'ensure_alert_workflow_schema'),
    'weekly period is seven inclusive days' => str_contains($index, "modify('-6 days')") && str_contains($index, 'anchor.getDate() - 6'),
    'dashboard retains a static chart fallback' => str_contains($renderer, '<?= render_system_report_chart($chart) ?>'),
    'date filters work without JavaScript' => str_contains($index, 'type="submit">Apply period</button>'),
    'filters replace only report analytics' => str_contains($index, 'const refreshAnalytics = async () =>')
        && str_contains($index, "nextDocument.querySelector('.report-analytics-layout')")
        && str_contains($index, 'layout.replaceWith(nextLayout)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException("Report hardening check failed: {$label}.");
    }
}

echo "System report hardening checks passed. No database writes.\n";
