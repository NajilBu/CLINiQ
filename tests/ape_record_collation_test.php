<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/services/ApeWorkflow.php');
if ($source === false) {
    throw new RuntimeException('APE workflow service must be readable.');
}

foreach ([
    'function ape_record_select_sql(): string',
    'TRIM(CONCAT({$programCode}, \'-\', {$yearLevel}, UPPER({$section}))) COLLATE utf8mb4_unicode_ci',
    "_utf8mb4'' COLLATE utf8mb4_unicode_ci",
    "'pr.program_code', 's.year_level', 's.section'",
    "'history_program.program_code',",
    'school_year_history.academic_year COLLATE utf8mb4_unicode_ci',
    'ar.academic_year COLLATE utf8mb4_unicode_ci',
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("APE record query must normalize course-section collations: {$expected}");
    }
}

echo "APE record collation guard test passed.\n";
