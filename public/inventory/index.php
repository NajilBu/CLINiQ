<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

$allItems = cliniq_inventory_items();

$activeItems = array_values(array_filter($allItems, fn(array $item): bool => empty($item['archived_at'])));
$archivedItems = array_values(array_filter($allItems, fn(array $item): bool => !empty($item['archived_at'])));
$equipmentItems = array_values(array_filter($activeItems, fn(array $item): bool => str_contains(strtolower((string) ($item['category'] ?? '')), 'equipment')));
$medicineItems = array_values(array_filter($activeItems, fn(array $item): bool => !str_contains(strtolower((string) ($item['category'] ?? '')), 'equipment')));
$medicineStockKey = fn(array $item): string => strtolower(trim((string) $item['item_name'])) . '|' . strtolower(trim((string) ($item['category'] ?? ''))) . '|' . strtolower(trim((string) $item['unit'])) . '|' . (int) $item['reorder_level'];
$medicineStockGroups = [];
foreach ($medicineItems as $item) {
    $key = $medicineStockKey($item);
    if (!isset($medicineStockGroups[$key])) {
        $medicineStockGroups[$key] = [
            'name' => $item['item_name'],
            'quantity' => 0,
            'reorder_level' => (int) $item['reorder_level'],
        ];
    }
    $medicineStockGroups[$key]['quantity'] += (int) $item['quantity'];
}
$lowStock = array_values(array_filter($medicineStockGroups, fn(array $group): bool => (int) $group['quantity'] <= (int) $group['reorder_level']));
$lowStockKeys = array_fill_keys(array_keys(array_filter($medicineStockGroups, fn(array $group): bool => (int) $group['quantity'] <= (int) $group['reorder_level'])), true);
$expiring = array_values(array_filter($medicineItems, fn(array $item): bool => $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days')));
$outOfStock = array_values(array_filter($medicineItems, fn(array $item): bool => (int) $item['quantity'] === 0));
$medicineRestockOptions = [];
foreach ($activeItems as $item) {
    $category = (string) ($item['category'] ?? '');
    if (str_contains(strtolower($category), 'equipment')) {
        continue;
    }

    $key = strtolower(trim((string) $item['item_name'])) . '|' . strtolower(trim($category)) . '|' . strtolower(trim((string) $item['unit'])) . '|' . (int) $item['reorder_level'];
    if (!isset($medicineRestockOptions[$key])) {
        $medicineRestockOptions[$key] = $item;
    }
}
uasort($medicineRestockOptions, fn(array $a, array $b): int => strcasecmp((string) $a['item_name'], (string) $b['item_name']));

$loanRowsRaw = cliniq_inventory_db()->query('
    SELECT
        l.*, l.loan_id AS id, l.quantity AS borrowed_quantity,
        i.item_name,
        i.item_type AS category,
        i.unit,
        TRIM(CONCAT_WS(" ", bp.first_name, bp.middle_name, bp.last_name)) AS borrower_name,
        bp.id_number AS borrower_identifier,
        TRIM(CONCAT_WS(" ", released.first_name, released.middle_name, released.last_name)) AS borrowed_by_name,
        TRIM(CONCAT_WS(" ", received.first_name, received.middle_name, received.last_name)) AS returned_by_name,
        CASE
            WHEN l.remarks LIKE "Condition: %" THEN SUBSTRING_INDEX(SUBSTRING_INDEX(l.remarks, "\n", 1), ": ", -1)
            ELSE NULL
        END AS return_condition,
        CASE
            WHEN LOCATE("\n", COALESCE(l.remarks, "")) > 0 THEN SUBSTRING(l.remarks, LOCATE("\n", l.remarks) + 1)
            ELSE l.remarks
        END AS return_notes
    FROM equipment_loans l
    INNER JOIN inventory_items i ON i.item_id = l.item_id
    INNER JOIN people bp ON bp.id = l.borrower_person_id
    LEFT JOIN people released ON released.id = l.released_by_person_id
    LEFT JOIN people received ON received.id = l.received_by_person_id
    ORDER BY
        CASE WHEN l.status IN ("Borrowed", "Overdue") THEN 0 ELSE 1 END,
        COALESCE(l.returned_at, l.borrowed_at) DESC
')->fetchAll();
$activeLoans = array_values(array_filter($loanRowsRaw, fn(array $loan): bool => in_array(($loan['status'] ?? ''), ['Borrowed', 'Overdue'], true)));
$returnedToday = array_values(array_filter($loanRowsRaw, fn(array $loan): bool => !empty($loan['returned_at']) && date('Y-m-d', strtotime($loan['returned_at'])) === date('Y-m-d')));
$inventoryTransactions = cliniq_inventory_transactions();

$activeTab = $_GET['tab'] ?? 'medicine';
if (!in_array($activeTab, ['medicine', 'equipment', 'expiring', 'archived', 'activity'], true)) {
    $activeTab = 'medicine';
}
$stockFilter = $_GET['stock_status'] ?? 'all';
if (!in_array($stockFilter, ['all', 'low', 'out', 'healthy'], true)) {
    $stockFilter = 'all';
}
$highlightTarget = $_GET['highlight'] ?? '';
if (!in_array($highlightTarget, ['low-stock', 'expiring', 'active-loans'], true)) {
    $highlightTarget = '';
}

$activeColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'sortField' => 'itemSort', 'minWidth' => 230, 'flex' => 1.2],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'sortField' => 'categorySort', 'minWidth' => 150, 'flex' => 0.8],
    ['headerName' => 'Stock Level', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'sortField' => 'stockSort', 'sortType' => 'number', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Reorder At', 'field' => 'reorderLevel', 'minWidth' => 120, 'flex' => 0.6],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'sortField' => 'expirationSort', 'sortType' => 'date', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'sortType' => 'number', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 90, 'maxWidth' => 110, 'flex' => 0.4],
];

$archivedColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'sortField' => 'itemSort', 'minWidth' => 230, 'flex' => 1.2],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'sortField' => 'categorySort', 'minWidth' => 150, 'flex' => 0.8],
    ['headerName' => 'Final Stock', 'field' => 'quantityHtml', 'cellRenderer' => 'html', 'sortField' => 'quantitySort', 'sortType' => 'number', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'sortField' => 'expirationSort', 'sortType' => 'date', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Archived', 'field' => 'archivedHtml', 'cellRenderer' => 'html', 'sortField' => 'archivedSort', 'sortType' => 'date', 'minWidth' => 180, 'flex' => 0.9],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 90, 'maxWidth' => 110, 'flex' => 0.4],
];

$equipmentColumns = [
    ['headerName' => 'Equipment', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'sortField' => 'itemSort', 'minWidth' => 260, 'flex' => 1.3],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'sortField' => 'categorySort', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Available', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'sortField' => 'stockSort', 'sortType' => 'number', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Minimum Available', 'field' => 'reorderLevel', 'minWidth' => 170, 'flex' => 0.75],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'sortType' => 'number', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 90, 'maxWidth' => 110, 'flex' => 0.4],
];

$loanColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'sortField' => 'itemSort', 'minWidth' => 220, 'flex' => 1.1],
    ['headerName' => 'Borrower', 'field' => 'borrowerHtml', 'cellRenderer' => 'html', 'sortField' => 'borrowerSort', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Borrowed', 'field' => 'borrowedHtml', 'cellRenderer' => 'html', 'sortField' => 'borrowedSort', 'sortType' => 'date', 'minWidth' => 150, 'flex' => 0.7],
    ['headerName' => 'Qty', 'field' => 'quantityHtml', 'cellRenderer' => 'html', 'sortField' => 'quantitySort', 'sortType' => 'number', 'minWidth' => 90, 'flex' => 0.4],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'sortType' => 'number', 'minWidth' => 130, 'flex' => 0.6],
    ['headerName' => 'Condition', 'field' => 'conditionHtml', 'cellRenderer' => 'html', 'sortField' => 'conditionSort', 'minWidth' => 140, 'flex' => 0.65],
    ['headerName' => 'Actions / Notes', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 160, 'flex' => 0.8],
];

$activityColumns = [
    ['headerName' => 'Date / Time', 'field' => 'date', 'sortField' => 'dateSort', 'sortType' => 'date', 'minWidth' => 175, 'flex' => 0.8],
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'sortField' => 'itemSort', 'minWidth' => 220, 'flex' => 1.1],
    ['headerName' => 'Activity', 'field' => 'typeHtml', 'cellRenderer' => 'html', 'sortField' => 'typeSort', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Change', 'field' => 'changeHtml', 'cellRenderer' => 'html', 'sortField' => 'changeSort', 'sortType' => 'number', 'minWidth' => 110, 'flex' => 0.5],
    ['headerName' => 'Balance', 'field' => 'balance', 'sortField' => 'balanceSort', 'sortType' => 'number', 'minWidth' => 100, 'flex' => 0.45],
    ['headerName' => 'Staff', 'field' => 'staff', 'minWidth' => 170, 'flex' => 0.8],
    ['headerName' => 'Notes', 'field' => 'notes', 'minWidth' => 240, 'flex' => 1.2],
];

$visibleItems = match ($activeTab) {
    'expiring' => $expiring,
    'equipment' => $equipmentItems,
    'archived' => $archivedItems,
    default => $medicineItems,
};
if ($activeTab !== 'activity' && $stockFilter !== 'all') {
    $visibleItems = array_values(array_filter($visibleItems, static function (array $item) use ($stockFilter, $medicineStockKey, $lowStockKeys): bool {
        $category = trim((string) ($item['category'] ?? ''));
        $isEquipment = str_contains(strtolower($category), 'equipment');
        $stockKey = !$isEquipment ? $medicineStockKey($item) : '';
        $isLow = $isEquipment
            ? (int) $item['quantity'] <= (int) $item['reorder_level']
            : isset($lowStockKeys[$stockKey]);
        return match ($stockFilter) {
            'low' => $isLow,
            'out' => (int) $item['quantity'] === 0,
            'healthy' => !$isLow && (int) $item['quantity'] > 0,
            default => true,
        };
    }));
}

$inventoryRows = [];
$highlightedLowStockKeys = [];
foreach ($visibleItems as $item) {
    $isArchived = !empty($item['archived_at']);
    $isExpiring = $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days');
    $category = trim((string) ($item['category'] ?? ''));
    $isEquipment = str_contains(strtolower($category), 'equipment');
    $stockKey = !$isEquipment ? $medicineStockKey($item) : '';
    $isLow = $isEquipment
        ? (int) $item['quantity'] <= (int) $item['reorder_level']
        : isset($lowStockKeys[$stockKey]);
    $displayQuantityForStatus = $item;
    if (!$isEquipment && isset($medicineStockGroups[$stockKey])) {
        $displayQuantityForStatus['quantity'] = (int) $medicineStockGroups[$stockKey]['quantity'];
    }
    $maxQty = max((int) $item['reorder_level'] * 4, (int) $item['quantity'], 1);
    $pct = min(100, round(((int) $item['quantity'] / $maxQty) * 100));
    $barClass = $isLow ? ($pct <= 20 ? 'stock-critical' : 'stock-warning') : ($pct <= 20 ? 'stock-warning' : 'stock-healthy');
    $expirationLabel = $item['expiration_date'] ? date('M d, Y', strtotime($item['expiration_date'])) : '-';
    $expirationClass = $isExpiring && !$isArchived ? 'text-red-600' : 'text-slate-600';
    $editArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_code'])),
        e(json_encode($item['item_name'])),
        e(json_encode($item['category'])),
        e(json_encode($item['description'])),
        (int) $item['quantity'],
        e(json_encode($item['unit'])),
        (int) $item['reorder_level'],
        e(json_encode($item['expiration_date'])),
    ]);
    $archiveArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_name'])),
        (int) $item['quantity'],
        e(json_encode($item['unit'])),
    ]);
    $borrowArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_name'])),
        (int) $item['quantity'],
        e(json_encode($item['unit'])),
    ]);
    $restockArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_name'])),
    ]);
    if ($isArchived) {
        $restoreMessage = e('Restore ' . $item['item_name'] . ' to active inventory?');
        $inventoryRows[] = [
            'highlightKeys' => [],
            'itemSort' => $item['item_name'],
            'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">Archived inventory record</p></div>',
            'categorySort' => $category,
            'categoryHtml' => '<span class="badge badge-cancelled">' . e($category !== '' ? $category : 'Uncategorized') . '</span>',
            'quantitySort' => (int) $item['quantity'],
            'quantityHtml' => '<span class="text-sm font-bold text-slate-700">' . (int) $item['quantity'] . ' ' . e($item['unit']) . '</span>',
            'expirationSort' => $item['expiration_date'],
            'expirationHtml' => '<span class="text-sm font-bold text-slate-500">' . e($expirationLabel) . '</span>',
            'archivedSort' => $item['archived_at'],
            'archivedHtml' => '<div><strong class="text-sm text-slate-700">' . e(date('M d, Y', strtotime($item['archived_at']))) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($item['archived_by_name'] ?: 'System') . '</p></div>',
            'reasonHtml' => '<span class="text-sm font-bold text-slate-500">' . e($item['archived_reason'] ?: 'No reason recorded') . '</span>',
            'actionsHtml' => row_actions_button('Inventory actions', '<form method="post" action="restore.php" data-inventory-form><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button type="submit" class="btn btn-sm btn-outline" data-confirm-submit data-confirm-type="primary" data-confirm-title="Restore inventory item?" data-confirm-message="' . $restoreMessage . '" data-confirm-toast="Restoring inventory item..."><span class="material-symbols-outlined text-[14px]">restore</span>Restore</button></form>'),
        ];
        continue;
    }

    $actionsHtml = '<div class="row-actions-list">';
    if (!$isEquipment) {
        $actionsHtml .= '<button onclick="closeModal(\'rowActionsModal\'); openRestockMedicine(' . $restockArgs . ')" class="btn btn-sm btn-primary" title="Restock medicine"><span class="material-symbols-outlined text-[14px]">add_box</span>Restock</button>';
    }
    if ($activeTab === 'equipment' && $isEquipment && (int) $item['quantity'] > 0) {
        $actionsHtml .= '<button onclick="closeModal(\'rowActionsModal\'); openBorrowItem(' . $borrowArgs . ')" class="btn btn-sm btn-primary" title="Borrow equipment"><span class="material-symbols-outlined text-[14px]">assignment_ind</span>Borrow</button>';
    }
    $actionsHtml .= '<button onclick="closeModal(\'rowActionsModal\'); editItem(' . $editArgs . ')" class="btn btn-sm btn-outline" title="Edit item"><span class="material-symbols-outlined text-[14px]">edit</span>Edit</button><button onclick="closeModal(\'rowActionsModal\'); openArchiveItem(' . $archiveArgs . ')" class="btn btn-sm btn-ghost" title="Deactivate item"><span class="material-symbols-outlined text-[14px]">archive</span>Deactivate</button></div>';
    $highlightKeys = [];
    if ($isLow && !$isEquipment && !isset($highlightedLowStockKeys[$stockKey])) {
        $highlightKeys[] = 'low-stock';
        $highlightedLowStockKeys[$stockKey] = true;
    }
    if ($isExpiring && !$isEquipment) {
        $highlightKeys[] = 'expiring';
    }

    $inventoryRows[] = [
        'highlightKeys' => $highlightKeys,
        'itemSort' => $item['item_name'],
        'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($item['item_code'] . ' / ' . ($category !== '' ? $category : 'No type')) . '</p></div>',
        'categorySort' => $category,
        'categoryHtml' => '<span class="text-sm font-bold text-slate-600">' . e($category !== '' ? $category : '-') . '</span>',
        'stockSort' => (int) $item['quantity'],
        'stockHtml' => '<div class="flex flex-col items-start gap-1"><span class="text-sm font-bold ' . ($isLow ? 'text-amber-600' : 'text-slate-600') . '">' . (int) $item['quantity'] . ' ' . e($item['unit']) . '</span><span class="stock-bar"><span class="stock-bar-fill ' . e($barClass) . '" style="width: ' . (int) $pct . '%"></span></span></div>',
        'reorderLevel' => (int) $item['reorder_level'],
        'expirationSort' => $item['expiration_date'],
        'expirationHtml' => '<span class="text-sm font-bold ' . $expirationClass . '">' . e($expirationLabel) . '</span>',
        'statusSort' => $isLow ? 0 : ($isExpiring ? 1 : 2),
        'statusHtml' => inventory_status_badge($displayQuantityForStatus),
        'actionsHtml' => row_actions_button('Inventory actions', $actionsHtml),
    ];
}

$activityRows = [];
foreach ($inventoryTransactions as $transaction) {
    $change = (int) $transaction['quantity_change'];
    $activityRows[] = [
        'dateSort' => $transaction['created_at'],
        'date' => date('M d, Y g:i A', strtotime($transaction['created_at'])),
        'itemSort' => $transaction['item_name'],
        'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($transaction['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($transaction['item_code'] . ' / ' . $transaction['item_type']) . '</p></div>',
        'typeSort' => $transaction['transaction_type'],
        'typeHtml' => '<span class="badge ' . ($change < 0 ? 'badge-pending' : 'badge-completed') . '">' . e($transaction['transaction_type']) . '</span>',
        'changeSort' => $change,
        'changeHtml' => '<strong class="text-sm ' . ($change < 0 ? 'text-red-600' : 'text-emerald-700') . '">' . ($change > 0 ? '+' : '') . $change . ' ' . e($transaction['unit']) . '</strong>',
        'balanceSort' => (int) $transaction['balance_after'],
        'balance' => (int) $transaction['balance_after'] . ' ' . $transaction['unit'],
        'staff' => $transaction['performed_by_name'] ?: 'System',
        'notes' => $transaction['notes'] ?: '-',
    ];
}

$loanRows = [];
foreach ($loanRowsRaw as $loan) {
    $isBorrowed = in_array(($loan['status'] ?? ''), ['Borrowed', 'Overdue'], true);
    $returnArgs = implode(', ', [
        (int) $loan['id'],
        e(json_encode($loan['item_name'])),
        e(json_encode($loan['borrower_name'])),
        e(json_encode($loan['borrower_identifier'])),
        e(json_encode(date('M d, g:i A', strtotime($loan['borrowed_at'])))),
        (int) $loan['borrowed_quantity'],
    ]);
    $returnSummary = '';
    if (!$isBorrowed) {
        $returnSummary = '<div class="text-right"><p class="text-xs font-bold text-slate-500 mb-0">' . e($loan['returned_at'] ? 'Returned ' . date('M d, g:i A', strtotime($loan['returned_at'])) : 'Return recorded') . '</p>'
            . '<p class="text-xs font-bold text-slate-400 mb-0 truncate">' . e($loan['return_notes'] ?: ($loan['returned_by_name'] ? 'By ' . $loan['returned_by_name'] : 'No notes')) . '</p></div>';
    }

    $loanRows[] = [
        'highlightKeys' => $isBorrowed ? ['active-loans'] : [],
        'itemSort' => $loan['item_name'],
        'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($loan['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($loan['category'] ?: 'Equipment') . '</p></div>',
        'borrowerSort' => $loan['borrower_name'],
        'borrowerHtml' => '<div><strong class="text-sm text-slate-700">' . e($loan['borrower_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($loan['borrower_identifier'] ?: 'No ID recorded') . '</p></div>',
        'borrowedSort' => $loan['borrowed_at'],
        'borrowedHtml' => '<div><strong class="text-sm text-slate-700">' . e(date('M d, g:i A', strtotime($loan['borrowed_at']))) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($loan['borrowed_by_name'] ?: 'System') . '</p></div>',
        'quantitySort' => (int) $loan['borrowed_quantity'],
        'quantityHtml' => '<span class="text-sm font-bold text-slate-700">' . (int) $loan['borrowed_quantity'] . ' ' . e($loan['unit'] ?: 'unit') . '</span>',
        'statusSort' => array_search((string) $loan['status'], ['Overdue', 'Borrowed', 'Returned', 'Cancelled'], true),
        'statusHtml' => inventory_loan_status_badge((string) $loan['status']),
        'conditionSort' => $loan['return_condition'] ?? '',
        'conditionHtml' => inventory_return_condition_badge($loan['return_condition'] ?? null),
        'actionsHtml' => $isBorrowed
            ? row_actions_button('Loan actions', '<button onclick="closeModal(\'rowActionsModal\'); openReturnLoan(' . $returnArgs . ')" class="btn btn-sm btn-outline"><span class="material-symbols-outlined text-[14px]">assignment_return</span>Return</button>')
            : $returnSummary,
    ];
}

$tableTitle = match ($activeTab) {
    'equipment' => 'Equipment Tracking',
    'expiring' => 'Expiring Soon',
    'archived' => 'Archived Inventory',
    'activity' => 'Inventory Activity',
    default => 'Medicine Inventory',
};
$tableDescription = match ($activeTab) {
    'equipment' => count($equipmentItems) . ' active equipment item(s) available for clinic use.',
    'expiring' => count($expiring) . ' active item(s) expiring within 30 days.',
    'archived' => count($archivedItems) . ' item(s) removed from active inventory.',
    'activity' => count($inventoryTransactions) . ' recent stock transaction(s).',
    default => count($medicineItems) . ' active medicine item(s).',
};
$gridColumns = match ($activeTab) {
    'archived' => $archivedColumns,
    'equipment' => $equipmentColumns,
    'activity' => $activityColumns,
    default => $activeColumns,
};
if ($activeTab === 'activity') {
    $inventoryRows = $activityRows;
}

$inventoryNotices = [];
if (count($lowStock) > 0) {
    $inventoryNotices[] = [
        'icon' => 'warning',
        'label' => 'Low Stock',
        'count' => count($lowStock),
        'detail' => count($lowStock) . ' medicine item(s) are at or below reorder level.',
        'href' => '?tab=medicine&highlight=low-stock',
        'tone' => 'amber',
    ];
}
if (count($expiring) > 0) {
    $inventoryNotices[] = [
        'icon' => 'event_busy',
        'label' => 'Expiring Soon',
        'count' => count($expiring),
        'detail' => count($expiring) . ' medicine item(s) expire within 30 days.',
        'href' => '?tab=expiring&highlight=expiring',
        'tone' => 'red',
    ];
}
if (count($activeLoans) > 0) {
    $inventoryNotices[] = [
        'icon' => 'assignment_ind',
        'label' => 'Active Borrowers',
        'count' => count($activeLoans),
        'detail' => count($activeLoans) . ' active borrower record(s) need return tracking.',
        'href' => '?tab=equipment&highlight=active-loans',
        'tone' => 'blue',
    ];
}

render_header('Inventory');

render_clinic_command_header(
    'Medicines',
    'Inventory & Tracking',
    'Manage clinic medicines, expiring stock, equipment loans, and archived records.',
    '<button onclick="openAddMedicineModal()" class="btn btn-primary justify-center"><span class="material-symbols-outlined text-[20px]">medication</span>+ Medicine</button><button onclick="showModal(\'addEquipmentModal\')" class="btn btn-outline justify-center"><span class="material-symbols-outlined text-[20px]">medical_services</span>+ Equipment</button>'
);
?>

<div id="inventoryLiveRegion" data-inventory-live-region>
<?php if (!empty($inventoryNotices)): ?>
    <section class="clinic-card overflow-hidden mb-8">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[20px]">notifications_active</span>
                </div>
                <div>
                    <h2 class="font-headline text-base font-extrabold text-[#17261d] m-0">Inventory Notifications</h2>
                    <p class="text-xs font-bold text-slate-500 m-0">Medicines below threshold, expiring stock, and active equipment borrowers.</p>
                </div>
            </div>
        </div>
        <div class="p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <?php foreach ($inventoryNotices as $notice): ?>
                <?php
                    $toneClass = match ($notice['tone']) {
                        'red' => 'border-l-red-500 bg-red-50/30 text-red-600',
                        'blue' => 'border-l-primary bg-primary-fixed/30 text-primary',
                        default => 'border-l-amber-500 bg-amber-50/40 text-amber-600',
                    };
                ?>
                <a href="<?= e($notice['href']) ?>" data-inventory-nav class="text-decoration-none rounded-xl border border-slate-100 border-l-4 <?= e($toneClass) ?> bg-white p-4 flex items-center justify-between gap-4 hover:shadow-sm transition-shadow">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="material-symbols-outlined text-[22px] shrink-0"><?= e($notice['icon']) ?></span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1"><?= e($notice['label']) ?></p>
                            <p class="text-sm font-bold text-slate-700 mb-0 truncate"><?= e($notice['detail']) ?></p>
                        </div>
                    </div>
                    <span class="font-headline text-2xl font-extrabold text-[#17261d] shrink-0"><?= (int) $notice['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= e($tableTitle) ?></h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= e($tableDescription) ?></p>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full lg:w-auto">
                <div class="search-input-wrap w-full lg:w-[360px] shrink-0">
                    <span class="search-icon material-symbols-outlined">search</span>
                    <input id="inventoryGridSearch" type="text" placeholder="Search inventory..." class="search-input">
                </div>
                <button type="button" onclick="showModal('inventoryAdvancedFilterModal')" class="btn btn-outline w-full sm:w-auto">
                    <span class="material-symbols-outlined text-[18px]">filter_list</span>
                    Filters
                </button>
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <a href="?tab=medicine" data-inventory-nav class="status-tab <?= $activeTab === 'medicine' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">pill</span>
                Medicine Inventory
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'medicine' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($medicineItems) ?></span>
            </a>
            <a href="?tab=equipment" data-inventory-nav class="status-tab <?= $activeTab === 'equipment' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">medical_services</span>
                Equipment Tracking
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'equipment' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($equipmentItems) ?></span>
            </a>
            <a href="?tab=expiring" data-inventory-nav class="status-tab <?= $activeTab === 'expiring' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">event_busy</span>
                Expiring Soon
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'expiring' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($expiring) ?></span>
            </a>
            <a href="?tab=archived" data-inventory-nav class="status-tab <?= $activeTab === 'archived' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">archive</span>
                Archived
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'archived' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($archivedItems) ?></span>
            </a>
            <a href="?tab=activity" data-inventory-nav class="status-tab <?= $activeTab === 'activity' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">history</span>
                Activity
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'activity' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($inventoryTransactions) ?></span>
            </a>
        </div>
    </div>
    <form method="get" id="inventoryAdvancedFilterModal" class="modal-backdrop">
        <div class="modal-content bg-white rounded-[2rem] w-full max-w-2xl p-8 shadow-2xl border border-outline-variant/10">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary-fixed text-primary rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">filter_alt</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#1c2a59] m-0">Advanced Filters</h3>
                </div>
                <button type="button" onclick="closeModal('inventoryAdvancedFilterModal')" class="btn-icon btn-icon-slate">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="clinic-label">Section</label>
                    <select class="clinic-select" name="tab">
                        <option value="medicine" <?= $activeTab === 'medicine' ? 'selected' : '' ?>>Medicine Inventory</option>
                        <option value="equipment" <?= $activeTab === 'equipment' ? 'selected' : '' ?>>Equipment Tracking</option>
                        <option value="expiring" <?= $activeTab === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
                        <option value="archived" <?= $activeTab === 'archived' ? 'selected' : '' ?>>Archived</option>
                        <option value="activity" <?= $activeTab === 'activity' ? 'selected' : '' ?>>Activity</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Stock Status</label>
                    <select class="clinic-select" name="stock_status">
                        <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                        <option value="healthy" <?= $stockFilter === 'healthy' ? 'selected' : '' ?>>Healthy Stock</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 pt-6 mt-6 border-t border-slate-100">
                <a href="index.php" data-inventory-nav class="btn btn-ghost flex-1 text-decoration-none">Reset All</a>
                <button class="btn btn-primary flex-1">
                    <span class="material-symbols-outlined text-[18px]">check</span>
                    Apply Filters
                </button>
            </div>
        </div>
    </form>

    <?php render_ag_grid('inventoryGrid', $gridColumns, $inventoryRows, [
        'searchInput' => 'inventoryGridSearch',
        'pageSize' => 10,
        'pagination' => true,
        'paginationControls' => 'inventoryPagination',
        'emptyTitle' => match ($activeTab) {
            'equipment' => 'No equipment items',
            'expiring' => 'No expiring items',
            'archived' => 'No archived records',
            'activity' => 'No inventory activity',
            default => 'No inventory items',
        },
        'emptyText' => match ($activeTab) {
            'equipment' => 'Add clinic equipment to track active stock and availability.',
            'expiring' => 'No active items are expiring within the next 30 days.',
            'archived' => 'Archived medicine and equipment records will appear here.',
            'activity' => 'Stock-in, dispensing, borrowing, returns, and adjustments will appear here.',
            default => 'Add medicines to start tracking stock.',
        },
    ]); ?>
    <nav id="inventoryPagination" class="pagination" aria-label="Inventory pages"></nav>
</section>

<?php if ($activeTab === 'equipment'): ?>
    <section class="clinic-card overflow-hidden mt-8">
        <div class="p-6 border-b border-slate-100">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div class="flex items-start gap-4">
                    <span class="w-11 h-11 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined">history</span>
                    <div>
                        <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Active Loans & Borrowing History</h2>
                        <p class="text-xs font-bold text-slate-500 mb-0">Track borrowed equipment, process returns, and keep condition notes.</p>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full md:w-auto">
                    <div class="search-input-wrap w-full sm:w-[320px] shrink-0">
                        <span class="search-icon material-symbols-outlined">search</span>
                        <input id="inventoryLoansSearch" type="text" placeholder="Search loans..." class="search-input">
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge badge-in-progress"><?= count($activeLoans) ?> borrowed</span>
                        <span class="badge badge-completed"><?= count($returnedToday) ?> returned today</span>
                    </div>
                </div>
            </div>
        </div>

        <?php render_ag_grid('inventoryLoansGrid', $loanColumns, $loanRows, [
            'searchInput' => 'inventoryLoansSearch',
            'pageSize' => 10,
            'pagination' => true,
            'paginationControls' => 'inventoryLoansPagination',
            'height' => 'compact',
            'emptyTitle' => 'No equipment loans yet',
            'emptyText' => 'Borrowed equipment and completed returns will appear here.',
        ]); ?>
        <nav id="inventoryLoansPagination" class="pagination" aria-label="Equipment loan pages"></nav>
    </section>
<?php endif; ?>
</div>

<script>
    (function () {
        const initialHighlightTarget = <?= json_encode($highlightTarget) ?>;

        function targetGridId(targetKey) {
            return targetKey === 'active-loans' ? 'inventoryLoansGrid' : 'inventoryGrid';
        }

        function rowHasTarget(data, targetKey) {
            return Array.isArray(data && data.highlightKeys) && data.highlightKeys.includes(targetKey);
        }

        function findTargetNodes(api, targetKey) {
            const matches = [];
            const visitNode = (node) => {
                if (rowHasTarget(node.data, targetKey)) {
                    matches.push(node);
                }
            };

            if (api.forEachNodeAfterFilterAndSort) {
                api.forEachNodeAfterFilterAndSort(visitNode);
            } else if (api.forEachNode) {
                api.forEachNode(visitNode);
            }

            return matches;
        }

        function cleanAttentionClass(rowClass) {
            return String(rowClass || '').replace(/\binventory-row-attention\b/g, '').replace(/\s+/g, ' ').trim();
        }

        function setTargetRowsAttention(api, targetNodes, shouldPulse) {
            targetNodes.forEach((node) => {
                if (!node.data) return;
                const baseClass = cleanAttentionClass(node.data.rowClass);
                node.data.rowClass = shouldPulse
                    ? `${baseClass} inventory-row-attention`.trim()
                    : baseClass;
            });

            if (api.redrawRows) {
                api.redrawRows({ rowNodes: targetNodes });
            }
        }

        function pulseTargetRows(gridId, api, targetKey) {
            const grid = document.getElementById(gridId);
            if (!grid || !api || !targetKey) return;

            const targetNodes = findTargetNodes(api, targetKey);
            if (targetNodes.length === 0) return;

            if (api.ensureIndexVisible) {
                api.ensureIndexVisible(targetNodes[0].rowIndex, 'middle');
            }

            grid.scrollIntoView({ behavior: 'smooth', block: 'center' });

            window.setTimeout(() => {
                setTargetRowsAttention(api, targetNodes, false);
                window.requestAnimationFrame(() => {
                    setTargetRowsAttention(api, targetNodes, true);
                    window.setTimeout(() => setTargetRowsAttention(api, targetNodes, false), 2400);
                });
            }, 450);
        }

        function pulseInventoryTarget(targetKey) {
            if (!targetKey) return;
            const gridId = targetGridId(targetKey);
            const api = window.cliniqAgGrids && window.cliniqAgGrids[gridId];
            if (api) {
                pulseTargetRows(gridId, api, targetKey);
                return;
            }

            const handler = (event) => {
                if (event.detail && event.detail.id === gridId) {
                    window.removeEventListener('cliniq:ag-grid-ready', handler);
                    pulseTargetRows(gridId, event.detail.api, targetKey);
                }
            };
            window.addEventListener('cliniq:ag-grid-ready', handler);
        }

        function showFetchedFlashes(doc) {
            doc.querySelectorAll('#flash-toasts [data-flash]').forEach((flash) => {
                if (typeof showToast === 'function') {
                    showToast(flash.dataset.message || '', flash.dataset.flash || 'info');
                }
            });
        }

        function renderInventoryHtml(html, finalUrl, pushHistory) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextRegion = doc.querySelector('[data-inventory-live-region]');
            const currentRegion = document.querySelector('[data-inventory-live-region]');
            if (!nextRegion || !currentRegion) {
                window.location.assign(finalUrl);
                return;
            }

            currentRegion.replaceWith(nextRegion);
            if (window.cliniqAgGrids) {
                delete window.cliniqAgGrids.inventoryGrid;
                delete window.cliniqAgGrids.inventoryLoansGrid;
            }
            if (typeof window.cliniqInitAgGrids === 'function') {
                window.cliniqInitAgGrids(nextRegion);
            }

            showFetchedFlashes(doc);

            const url = new URL(finalUrl, window.location.origin);
            if (pushHistory) {
                window.history.pushState({ inventory: true }, '', url);
            } else {
                window.history.replaceState({ inventory: true }, '', url);
            }

            pulseInventoryTarget(url.searchParams.get('highlight'));
        }

        async function loadInventoryUrl(url, pushHistory) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            });
            renderInventoryHtml(await response.text(), response.url || url, pushHistory);
        }

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-inventory-nav]');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

            event.preventDefault();
            loadInventoryUrl(link.href, true).catch(() => window.location.assign(link.href));
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!form.matches('[data-inventory-form]') || event.defaultPrevented) return;
            if (form.matches('[data-confirm-submit]') && form.dataset.confirmed !== '1') return;
            if (form.dataset.confirmed !== '1') {
                const confirmButton = form.querySelector('button[data-confirm-submit]');
                if (confirmButton) {
                    event.preventDefault();
                    submitConfirmableAction(confirmButton);
                    return;
                }
            }

            event.preventDefault();
            fetch(form.action, {
                method: form.method || 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(async (response) => {
                document.querySelectorAll('.modal-backdrop.show').forEach((modal) => closeModal(modal.id));
                renderInventoryHtml(await response.text(), response.url || window.location.href, false);
            }).catch(() => {
                form.submit();
            });
        });

        window.addEventListener('popstate', () => {
            loadInventoryUrl(window.location.href, false).catch(() => window.location.reload());
        });

        window.cliniqInventoryPulseTarget = pulseInventoryTarget;
        document.addEventListener('DOMContentLoaded', () => pulseInventoryTarget(initialHighlightTarget));
    })();
</script>

<div id="addMedicineModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Add Medicine</h3>
            <button onclick="closeModal('addMedicineModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="grid grid-cols-2 gap-2 p-1 rounded-2xl bg-slate-100 mb-6">
            <button type="button" class="status-tab active justify-center" data-medicine-mode="new" onclick="switchMedicineModalMode('new')">
                New Medicine
            </button>
            <button type="button" class="status-tab justify-center" data-medicine-mode="restock" onclick="switchMedicineModalMode('restock')">
                Restock Existing
            </button>
        </div>
        <form method="post" action="create.php" data-inventory-form>
            <input type="hidden" name="category" value="Medicine">
            <div data-medicine-panel="new" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="clinic-label">Item Code</label>
                    <input class="clinic-input uppercase" name="item_code" required placeholder="e.g. MED-001">
                </div>
                <div>
                    <label class="clinic-label">Item Name</label>
                    <input class="clinic-input" name="item_name" required placeholder="e.g. Paracetamol 500mg">
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Description</label>
                    <input class="clinic-input" name="description" placeholder="Optional medicine description">
                </div>
                <div>
                    <label class="clinic-label">Unit</label>
                    <input class="clinic-input" name="unit" value="pcs" required placeholder="e.g. Tablets, Capsules">
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="clinic-input" name="quantity" type="number" min="0" required value="0">
                </div>
                <div>
                    <label class="clinic-label">Reorder Level</label>
                    <input class="clinic-input" name="reorder_level" type="number" min="0" required value="10">
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Expiration Date</label>
                    <input class="clinic-input" name="expiration_date" type="date">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addMedicineModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add this medicine?" data-confirm-message="This will save the new medicine to active inventory." data-confirm-toast="Adding medicine...">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Add Item
                </button>
            </div>
        </form>
        <form method="post" action="restock.php" data-inventory-form class="hidden">
            <div data-medicine-panel="restock" class="space-y-4">
                <div>
                    <label class="clinic-label">Medicine</label>
                    <select class="clinic-select" name="source_id" id="restockMedicineSource" required>
                        <option value="">Select medicine</option>
                        <?php foreach ($medicineRestockOptions as $option): ?>
                            <option value="<?= (int) $option['id'] ?>">
                                <?= e($option['item_name']) ?><?= trim((string) ($option['category'] ?? '')) !== '' ? ' - ' . e($option['category']) : '' ?><?= trim((string) ($option['unit'] ?? '')) !== '' ? ' (' . e($option['unit']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="clinic-label">Received Quantity</label>
                        <input class="clinic-input" name="quantity" type="number" min="1" required value="1">
                    </div>
                    <div>
                        <label class="clinic-label">Batch Expiration Date</label>
                        <input class="clinic-input" name="expiration_date" type="date" required>
                        <p class="settings-help mt-2 mb-0">Each restock is saved as a separate batch so existing expiry dates and quantities remain unchanged.</p>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addMedicineModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Create this medicine batch?" data-confirm-message="This will save the received stock as a separate batch with its own expiration date." data-confirm-toast="Creating medicine batch...">
                    <span class="material-symbols-outlined text-[18px]">add_box</span>
                    Restock Medicine
                </button>
            </div>
        </form>
    </div>
</div>

<div id="addEquipmentModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Add Equipment</h3>
            <button onclick="closeModal('addEquipmentModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="create.php" data-inventory-form>
            <input type="hidden" name="category" value="Equipment">
            <input type="hidden" name="expiration_date" value="">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="clinic-label">Item Code</label>
                    <input class="clinic-input uppercase" name="item_code" required placeholder="e.g. EQP-001">
                </div>
                <div>
                    <label class="clinic-label">Equipment Name</label>
                    <input class="clinic-input" name="item_name" required placeholder="e.g. Pulse Oximeter">
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Description</label>
                    <input class="clinic-input" name="description" placeholder="Optional equipment description">
                </div>
                <div>
                    <label class="clinic-label">Unit</label>
                    <input class="clinic-input" name="unit" value="unit" required placeholder="e.g. unit, set, pcs">
                </div>
                <div>
                    <label class="clinic-label">Available Quantity</label>
                    <input class="clinic-input" name="quantity" type="number" min="0" required value="0">
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Minimum Available</label>
                    <input class="clinic-input" name="reorder_level" type="number" min="0" required value="1">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addEquipmentModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add this equipment?" data-confirm-message="This will save the equipment item to active inventory." data-confirm-toast="Adding equipment...">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Add Equipment
                </button>
            </div>
        </form>
    </div>
</div>

<div id="editMedicineModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Edit Item</h3>
            <button onclick="closeModal('editMedicineModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="update.php" id="editItemForm" data-inventory-form>
            <input type="hidden" name="id" id="editItemId">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="clinic-label">Item Code</label>
                    <input class="clinic-input uppercase" name="item_code" id="editItemCode" required>
                </div>
                <div>
                    <label class="clinic-label">Item Name</label>
                    <input class="clinic-input" name="item_name" id="editItemName" required>
                </div>
                <input type="hidden" name="category" id="editItemCategory">
                <div class="md:col-span-2">
                    <label class="clinic-label">Description</label>
                    <input class="clinic-input" name="description" id="editItemDescription">
                </div>
                <div>
                    <label class="clinic-label">Unit</label>
                    <input class="clinic-input" name="unit" id="editItemUnit" required>
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="clinic-input" name="quantity" id="editItemQuantity" type="number" min="0" required>
                </div>
                <div>
                    <label class="clinic-label" id="editItemThresholdLabel">Reorder Level</label>
                    <input class="clinic-input" name="reorder_level" id="editItemReorder" type="number" min="0" required>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Expiration Date</label>
                    <input class="clinic-input" name="expiration_date" id="editItemExpiry" type="date">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editMedicineModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save inventory changes?" data-confirm-message="This will update the selected inventory item." data-confirm-toast="Saving inventory changes...">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<div id="archiveItemModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Deactivate Inventory Item</h3>
                <p class="text-sm font-bold text-slate-500 mt-1" id="archiveItemName">Remove this item from active inventory selections.</p>
            </div>
            <button onclick="closeModal('archiveItemModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="archive.php" id="archiveItemForm" data-inventory-form>
            <input type="hidden" name="id" id="archiveItemId">
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                The item and its history will remain in Cliniq_db, but it will no longer be selectable for dispensing or loans.
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('archiveItemModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Deactivate this inventory item?" data-confirm-message="This will hide the item from active dispensing and equipment loan selections." data-confirm-toast="Deactivating inventory item...">
                    <span class="material-symbols-outlined text-[18px]">archive</span>
                    Deactivate Item
                </button>
            </div>
        </form>
    </div>
</div>

<div id="borrowEquipmentModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Borrow Equipment</h3>
                <p class="text-sm font-bold text-slate-500 mt-1" id="borrowEquipmentSummary">Record an equipment loan.</p>
            </div>
            <button onclick="closeModal('borrowEquipmentModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="borrow.php" data-inventory-form>
            <input type="hidden" name="item_id" id="borrowItemId">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="clinic-label">Existing Patient ID</label>
                    <input class="clinic-input uppercase" name="borrower_identifier" required placeholder="Student, faculty, or personnel ID">
                    <p class="settings-help mt-2 mb-0">The ID must already exist in the Cliniq_db patient list.</p>
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="clinic-input" name="borrowed_quantity" id="borrowQuantity" type="number" min="1" value="1" required>
                </div>
                <div class="md:col-span-2">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="clinic-label">Expected Return Date<input class="clinic-input" name="return_date" value="<?= e(date('Y-m-d')) ?>" type="date" min="<?= e(date('Y-m-d')) ?>" required></label>
                        <label class="clinic-label">Expected Return Time (8:00 AM–5:00 PM)<span class="flex gap-2" data-return-clock><input class="clinic-select" style="min-width:0;flex:1;" name="return_time" required type="text" inputmode="numeric" maxlength="5" data-equipment-return-time placeholder="h:mm" autocomplete="off" aria-label="Return time"><select class="clinic-select" style="width:100px;" name="return_period" data-return-period required aria-label="AM or PM"><option value="AM">AM</option><option value="PM">PM</option></select></span></label>
                    </div>
                    <p class="settings-help mt-2 mb-0">Choose a future return date and time before recording this loan.</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('borrowEquipmentModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Record this equipment loan?" data-confirm-message="This will reduce the available equipment quantity until the item is returned." data-confirm-toast="Recording equipment loan...">
                    <span class="material-symbols-outlined text-[18px]">assignment_ind</span>
                    Record Loan
                </button>
            </div>
        </form>
    </div>
</div>

<div id="returnEquipmentModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Return Equipment</h3>
                <p class="text-sm font-bold text-slate-500 mt-1">Confirm the returned item condition.</p>
            </div>
            <button onclick="closeModal('returnEquipmentModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="return.php" data-inventory-form>
            <input type="hidden" name="loan_id" id="returnLoanId">
            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 mb-5 space-y-2">
                <div class="flex justify-between gap-3">
                    <span class="text-xs font-black text-slate-400 uppercase tracking-widest">Item</span>
                    <span class="text-sm font-bold text-slate-800 text-right" id="returnItemName">Equipment</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-xs font-black text-slate-400 uppercase tracking-widest">Borrower</span>
                    <span class="text-sm font-bold text-slate-800 text-right" id="returnBorrowerName">Borrower</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-xs font-black text-slate-400 uppercase tracking-widest">Borrowed</span>
                    <span class="text-sm font-bold text-slate-600 text-right" id="returnBorrowedAt">-</span>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="clinic-label">Equipment Condition</label>
                    <select class="clinic-select" name="return_condition">
                        <?php foreach (dropdown_options('inventory_return_condition') as $condition): ?>
                            <option value="<?= e($condition) ?>"><?= e($condition) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Return Notes</label>
                    <textarea class="clinic-textarea" name="return_notes" rows="3" placeholder="Condition notes, damage details, or follow-up action."></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('returnEquipmentModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Process this return?" data-confirm-message="This will close the active loan and record the return condition." data-confirm-toast="Processing equipment return...">
                    <span class="material-symbols-outlined text-[18px]">assignment_return</span>
                    Process Return
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editItem(id, code, name, category, description, quantity, unit, reorder, expiry) {
    document.getElementById('editItemId').value = id;
    document.getElementById('editItemCode').value = code || '';
    document.getElementById('editItemName').value = name;
    document.getElementById('editItemCategory').value = category || '';
    document.getElementById('editItemDescription').value = description || '';
    document.getElementById('editItemQuantity').value = quantity;
    document.getElementById('editItemUnit').value = unit;
    document.getElementById('editItemReorder').value = reorder;
    document.getElementById('editItemExpiry').value = expiry || '';
    const thresholdLabel = document.getElementById('editItemThresholdLabel');
    if (thresholdLabel) {
        thresholdLabel.textContent = String(category || '').toLowerCase().includes('equipment') ? 'Minimum Available' : 'Reorder Level';
    }
    showModal('editMedicineModal');
}

function openAddMedicineModal() {
    switchMedicineModalMode('new');
    showModal('addMedicineModal');
}

function openArchiveItem(id, name, quantity, unit) {
    document.getElementById('archiveItemId').value = id;
    document.getElementById('archiveItemName').textContent = name ? `Deactivate ${name} from active inventory.` : 'Remove this item from active inventory selections.';
    showModal('archiveItemModal');
}

function openRestockMedicine(id, name) {
    const select = document.getElementById('restockMedicineSource');
    switchMedicineModalMode('restock');
    if (select) {
        select.value = String(id);
    }
    showModal('addMedicineModal');
}

function switchMedicineModalMode(mode) {
    const modal = document.getElementById('addMedicineModal');
    if (!modal) return;

    const isRestock = mode === 'restock';
    modal.querySelectorAll('[data-medicine-mode]').forEach((button) => {
        button.classList.toggle('active', button.dataset.medicineMode === mode);
    });
    modal.querySelectorAll('form').forEach((form) => {
        const panel = form.querySelector('[data-medicine-panel]');
        form.classList.toggle('hidden', !panel || panel.dataset.medicinePanel !== mode);
    });

    const title = modal.querySelector('h3');
    if (title) {
        title.textContent = isRestock ? 'Restock Medicine' : 'Add Medicine';
    }
}

function openBorrowItem(id, name, available, unit) {
    document.getElementById('borrowItemId').value = id;
    document.getElementById('borrowQuantity').max = available;
    document.getElementById('borrowQuantity').value = available > 0 ? 1 : 0;
    document.getElementById('borrowEquipmentSummary').textContent = `${name} has ${available} ${unit || 'unit'} available.`;
    showModal('borrowEquipmentModal');
}

function openReturnLoan(id, itemName, borrowerName, borrowerIdentifier, borrowedAt, quantity) {
    document.getElementById('returnLoanId').value = id;
    document.getElementById('returnItemName').textContent = itemName;
    document.getElementById('returnBorrowerName').textContent = borrowerIdentifier ? `${borrowerName} (${borrowerIdentifier})` : borrowerName;
    document.getElementById('returnBorrowedAt').textContent = `${borrowedAt} · ${quantity} borrowed`;
    showModal('returnEquipmentModal');
}
</script>

<?php render_footer(); ?>
