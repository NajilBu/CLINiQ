<?php

/**
 * Legacy InventoryWorkflow shim.
 *
 * All inventory functionality has been migrated to CliniqInventoryWorkflow.php
 * using the Cliniq_db database connection and schema.
 */
require_once __DIR__ . '/CliniqInventoryWorkflow.php';

function inventory_status_badge(array $item): string
{
    $quantity = (int) ($item['quantity'] ?? 0);
    $reorderLevel = (int) ($item['reorder_level'] ?? 0);
    $expirationDate = $item['expiration_date'] ?? null;
    $isExpiring = $expirationDate && strtotime((string) $expirationDate) <= strtotime('+30 days');
    $isEquipment = str_contains(strtolower((string) ($item['category'] ?? $item['item_type'] ?? '')), 'equipment');

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
