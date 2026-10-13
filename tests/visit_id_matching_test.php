<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/helpers/student_id.php';

if (visit_id_number_candidates('23-00262') !== ['23-00262', '23-00262']) {
    throw new RuntimeException('Student lookups must retain their canonical hyphenated form.');
}
if (visit_id_number_candidates('0000002') !== ['0000002', '0000002']) {
    throw new RuntimeException('Leading zeros must be preserved without reformatting numeric IDs.');
}
if (is_valid_id_number('123-4567')) {
    throw new RuntimeException('Malformed IDs must be rejected.');
}
echo "Visit ID matching checks passed. No database writes.\n";
