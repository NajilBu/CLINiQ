<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/CliniqInventoryWorkflow.php');
$index = file_get_contents($root . '/public/inventory/index.php');
$archive = file_get_contents($root . '/public/inventory/archive.php');
$update = file_get_contents($root . '/public/inventory/update.php');
$audit = file_get_contents($root . '/app/services/AuditLog.php');

foreach ([
    'expired medicine is excluded from selectable inventory' => str_contains($service, "expiration_date >= CURDATE()"),
    'expired medicine has a distinct status' => str_contains($service, 'Expired</span>'),
    'inventory exposes a distinct expired-stock view' => str_contains($index, "'expired' => \$expired"),
    'notification navigation uses the app content pane' => str_contains($index, "document.querySelector('.app-content')") && str_contains($index, 'content.scrollBy'),
    'notification navigation does not force page-centering' => !str_contains($index, 'grid.scrollIntoView'),
    'archiving equipment checks open loans' => str_contains($archive, 'status IN ("Borrowed", "Overdue")'),
    'archiving records a reasoned audit event' => str_contains($archive, "'inventory_item_archived'") && str_contains($archive, 'archive_reason'),
    'item type is retained from the locked record' => str_contains($update, "\$type = (string) \$current['item_type'];"),
    'stock changes require a reason' => str_contains($update, 'Explain the quantity adjustment before saving.'),
    'stock changes are blocked during equipment loans' => str_contains($update, 'Available quantity cannot be adjusted while this equipment has an open loan.'),
    'ajax inventory forms rely on the shared loading-overlay boundary' => preg_match_all('/<form\b[^>]*\bdata-inventory-form\b/', $index) === 10 && !str_contains($index, 'data-inventory-form data-no-loading'),
    'inventory audit labels describe item changes' => str_contains($audit, "'inventory_item_updated'") && str_contains($audit, "'inventory_item_archived'"),
] as $label => $passed) {
    if (!$passed) throw new RuntimeException('Failed: ' . $label);
}

echo "Inventory safety test passed.\n";
