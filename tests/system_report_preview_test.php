<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/reports/pdf.php');

if ($source === false) {
    throw new RuntimeException('Unable to read the report preview source.');
}

$checks = [
    'multi-page mode is enabled by default' => str_contains($source, 'system-report-standalone report-preview-multi-page'),
    'responsive multi-page grid exists' => str_contains($source, 'grid-template-columns: repeat(auto-fit, minmax(31rem, 1fr))'),
    'report body participates in the page grid' => str_contains($source, '.report-body {') && str_contains($source, 'display: contents'),
    'layout toggle exists' => str_contains($source, 'reportPreviewLayoutToggle'),
    'single-page label exists' => str_contains($source, 'Single Page View'),
    'multiple-page label exists' => str_contains($source, 'Multiple Pages View'),
    'preview reports its page count' => str_contains($source, 'pages shown in the printable page layout'),
    'print mode restores full scale' => str_contains($source, 'zoom: 1'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Report preview check failed: ' . $label);
    }
}

echo "System report multi-page preview checks passed.\n";
