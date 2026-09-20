<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/helpers/student_id.php';

foreach (['2300262', '23-00262'] as $input) {
    $candidates = visit_id_number_candidates($input);
    if ($candidates !== ['23-00262', '2300262']) {
        throw new RuntimeException('Hyphenated ID must take priority for either input format.');
    }
    foreach ([['23-00262' => 1], ['2300262' => 2], ['23-00262' => 1, '2300262' => 2], []] as $records) {
        $found = null;
        foreach ($candidates as $candidate) {
            if (isset($records[$candidate])) { $found = $records[$candidate]; break; }
        }
        $expected = $records['23-00262'] ?? $records['2300262'] ?? null;
        if ($found !== $expected) throw new RuntimeException('Wrong patient selected.');
    }
}
if (visit_id_number_candidates('0000002') !== ['00-00002', '0000002']) {
    throw new RuntimeException('Leading zeros must be preserved.');
}
if (visit_id_number_candidates('fac0001') !== ['FAC-0001', 'FAC-0001']) {
    throw new RuntimeException('Legacy prefixed IDs must remain supported.');
}
echo "Visit ID matching checks passed. No database writes.\n";
