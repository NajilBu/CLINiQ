<?php

$root = dirname(__DIR__);
$source = file_get_contents($root . '/public/visitor-registration.php');

foreach (['Borrow Equipment', 'borrow-equipment-wrap', 'syncBorrowFlow', 'name="borrow_item_id[]"', 'name="borrowed_quantity[]"', 'id="addBorrowItem"', 'ksort($borrowItemIds)', '$lockedItems', '#borrowEquipmentRows', 'max-height: 7rem', 'overflow-y: auto', 'overscroll-behavior: contain', "VISITOR_REGISTERED_ONLY_REASONS = [VISITOR_REASON_BORROW_EQUIPMENT, 'APE']", 'data-registered-only', 'option.hidden = isGuest'] as $required) {
    if (!str_contains($source, $required)) {
        throw new RuntimeException("Visitor registration must retain the equipment-loan flow: {$required}");
    }
}

if (str_contains($source, '.visit-main.is-borrowing {')) {
    throw new RuntimeException('Borrowing must not change the visitor form alignment.');
}

echo "Visitor registration borrowing layout test passed. No database writes.\n";
