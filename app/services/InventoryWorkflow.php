<?php

function ensure_inventory_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query('SHOW COLUMNS FROM inventory_items');
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE inventory_items ADD COLUMN {$name} {$definition}");
            $columns[$name] = ['Field' => $name];
        }
    };

    $addColumn('archived_at', 'DATETIME NULL AFTER expiration_date');
    $addColumn('archived_reason', 'VARCHAR(255) NULL AFTER archived_at');
    $addColumn('archived_by', 'INT NULL AFTER archived_reason');
    $addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

    $db->exec("
        CREATE TABLE IF NOT EXISTS inventory_loans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            borrower_name VARCHAR(160) NOT NULL,
            borrower_identifier VARCHAR(80) NULL,
            borrowed_quantity INT NOT NULL DEFAULT 1,
            borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            due_at DATETIME NULL,
            status ENUM('Borrowed','Returned','Lost') NOT NULL DEFAULT 'Borrowed',
            return_condition ENUM('Good','Defective','Lost') NULL,
            return_notes TEXT NULL,
            returned_at DATETIME NULL,
            borrowed_by INT NULL,
            returned_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inventory_loans_item_status (item_id, status),
            CONSTRAINT fk_inventory_loans_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_inventory_loans_borrowed_by FOREIGN KEY (borrowed_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_inventory_loans_returned_by FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");

    $ready = true;
}

function inventory_status_badge(array $item): string
{
    $quantity = (int) ($item['quantity'] ?? 0);
    $reorderLevel = (int) ($item['reorder_level'] ?? 0);
    $expirationDate = $item['expiration_date'] ?? null;
    $isExpiring = $expirationDate && strtotime($expirationDate) <= strtotime('+30 days');
    $isEquipment = str_contains(strtolower((string) ($item['category'] ?? '')), 'equipment');

    if ($quantity === 0) {
        return '<span class="badge badge-critical">Out of Stock</span>';
    }
    if ($quantity <= $reorderLevel) {
        return '<span class="badge badge-pending">' . ($isEquipment ? 'Below Minimum' : 'Low Stock') . '</span>';
    }
    if ($isExpiring) {
        return '<span class="badge badge-high">Expiring Soon</span>';
    }

    return '<span class="badge badge-completed">In Stock</span>';
}

function inventory_loan_status_badge(string $status): string
{
    return match ($status) {
        'Returned' => '<span class="badge badge-completed">Returned</span>',
        'Lost' => '<span class="badge badge-critical">Lost</span>',
        default => '<span class="badge badge-in-progress">Borrowed</span>',
    };
}

function inventory_return_condition_badge(?string $condition): string
{
    if (!$condition) {
        return '<span class="text-xs font-bold text-slate-300 uppercase">-</span>';
    }

    return match ($condition) {
        'Good' => '<span class="badge badge-completed">Good</span>',
        'Lost' => '<span class="badge badge-critical">Lost</span>',
        default => '<span class="badge badge-pending">Defective</span>',
    };
}
