<?php

$root = dirname(__DIR__);
$source = file_get_contents($root . '/public/visitor-registration.php');

foreach (['Borrow Equipment', 'borrow-equipment-wrap', 'syncBorrowFlow'] as $required) {
    if (!str_contains($source, $required)) {
        throw new RuntimeException("Visitor registration must retain the equipment-loan flow: {$required}");
    }
}

if (str_contains($source, '.visit-main.is-borrowing {')) {
    throw new RuntimeException('Borrowing must not change the visitor form alignment.');
}

echo "Visitor registration borrowing layout test passed. No database writes.\n";
