<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

$allItems = db()->query('
    SELECT i.*, u.name AS archived_by_name
    FROM inventory_items i
    LEFT JOIN users u ON u.id = i.archived_by
    ORDER BY COALESCE(i.archived_at, i.updated_at, i.created_at) DESC, i.item_name
')->fetchAll();

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
foreach ($allItems as $item) {
    $category = (string) ($item['category'] ?? '');
    if (str_contains(strtolower($category), 'equipment')) {
        continue;
    }

    $key = strtolower(trim((string) $item['item_name'])) . '|' . strtolower(trim($category)) . '|' . strtolower(trim((string) $item['unit'])) . '|' . (int) $item['reorder_level'];
    if (!isset($medicineRestockOptions[$key]) || empty($item['archived_at'])) {
        $medicineRestockOptions[$key] = $item;
    }
}
uasort($medicineRestockOptions, fn(array $a, array $b): int => strcasecmp((string) $a['item_name'], (string) $b['item_name']));

$loanRowsRaw = db()->query('
    SELECT
        l.*,
        i.item_name,
        i.category,
        i.unit,
        borrower.name AS borrowed_by_name,
        returner.name AS returned_by_name
    FROM inventory_loans l
    INNER JOIN inventory_items i ON i.id = l.item_id
    LEFT JOIN users borrower ON borrower.id = l.borrowed_by
    LEFT JOIN users returner ON returner.id = l.returned_by
    ORDER BY
        CASE WHEN l.status = "Borrowed" THEN 0 ELSE 1 END,
        COALESCE(l.returned_at, l.borrowed_at) DESC
    LIMIT 50
')->fetchAll();
$activeLoans = array_values(array_filter($loanRowsRaw, fn(array $loan): bool => ($loan['status'] ?? '') === 'Borrowed'));
$returnedToday = array_values(array_filter($loanRowsRaw, fn(array $loan): bool => !empty($loan['returned_at']) && date('Y-m-d', strtotime($loan['returned_at'])) === date('Y-m-d')));

$activeTab = $_GET['tab'] ?? 'medicine';
if (!in_array($activeTab, ['medicine', 'equipment', 'expiring', 'archived'], true)) {
    $activeTab = 'medicine';
}
$highlightTarget = $_GET['highlight'] ?? '';
if (!in_array($highlightTarget, ['low-stock', 'expiring', 'active-loans'], true)) {
    $highlightTarget = '';
}

$activeColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 230, 'flex' => 1.2],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'minWidth' => 150, 'flex' => 0.8],
    ['headerName' => 'Stock Level', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Reorder At', 'field' => 'reorderLevel', 'minWidth' => 120, 'flex' => 0.6],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 150, 'flex' => 0.65],
];

$archivedColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 230, 'flex' => 1.2],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'minWidth' => 150, 'flex' => 0.8],
    ['headerName' => 'Final Stock', 'field' => 'quantityHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Archived', 'field' => 'archivedHtml', 'cellRenderer' => 'html', 'minWidth' => 180, 'flex' => 0.9],
    ['headerName' => 'Reason', 'field' => 'reasonHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1.1],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 120, 'flex' => 0.55],
];

$equipmentColumns = [
    ['headerName' => 'Equipment', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 260, 'flex' => 1.3],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Available', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Minimum Available', 'field' => 'reorderLevel', 'minWidth' => 170, 'flex' => 0.75],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 120, 'flex' => 0.55],
];

$loanColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1.1],
    ['headerName' => 'Borrower', 'field' => 'borrowerHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Borrowed', 'field' => 'borrowedHtml', 'cellRenderer' => 'html', 'minWidth' => 150, 'flex' => 0.7],
    ['headerName' => 'Qty', 'field' => 'quantityHtml', 'cellRenderer' => 'html', 'minWidth' => 90, 'flex' => 0.4],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'minWidth' => 130, 'flex' => 0.6],
    ['headerName' => 'Condition', 'field' => 'conditionHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.65],
    ['headerName' => 'Actions / Notes', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 210, 'flex' => 1],
];

$visibleItems = match ($activeTab) {
    'expiring' => $expiring,
    'equipment' => $equipmentItems,
    'archived' => $archivedItems,
    default => $medicineItems,
};

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
    $expirationLabel = $item['expiration_date'] ? date('M Y', strtotime($item['expiration_date'])) : '-';
    $expirationClass = $isExpiring && !$isArchived ? 'text-red-600' : 'text-slate-600';
    $editArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_name'])),
        e(json_encode($item['category'])),
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
            'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">Archived inventory record</p></div>',
            'categoryHtml' => '<span class="badge badge-cancelled">' . e($category !== '' ? $category : 'Uncategorized') . '</span>',
            'quantityHtml' => '<span class="text-sm font-bold text-slate-700">' . (int) $item['quantity'] . ' ' . e($item['unit']) . '</span>',
            'expirationHtml' => '<span class="text-sm font-bold text-slate-500">' . e($expirationLabel) . '</span>',
            'archivedHtml' => '<div><strong class="text-sm text-slate-700">' . e(date('M d, Y', strtotime($item['archived_at']))) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($item['archived_by_name'] ?: 'System') . '</p></div>',
            'reasonHtml' => '<span class="text-sm font-bold text-slate-500">' . e($item['archived_reason'] ?: 'No reason recorded') . '</span>',
            'actionsHtml' => '<form method="post" action="restore.php" class="flex justify-end" data-inventory-form><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button type="submit" class="btn btn-sm btn-outline" data-confirm-submit data-confirm-type="primary" data-confirm-title="Restore inventory item?" data-confirm-message="' . $restoreMessage . '" data-confirm-toast="Restoring inventory item..."><span class="material-symbols-outlined text-[14px]">restore</span>Restore</button></form>',
        ];
        continue;
    }

    $actionsHtml = '<div class="flex justify-end gap-1">';
    if (!$isEquipment) {
        $actionsHtml .= '<button onclick="openRestockMedicine(' . $restockArgs . ')" class="btn-icon btn-icon-primary" title="Restock medicine"><span class="material-symbols-outlined">add_box</span></button>';
    }
    if ($activeTab === 'equipment' && $isEquipment && (int) $item['quantity'] > 0) {
        $actionsHtml .= '<button onclick="openBorrowItem(' . $borrowArgs . ')" class="btn-icon btn-icon-primary" title="Borrow equipment"><span class="material-symbols-outlined">assignment_ind</span></button>';
    }
    $actionsHtml .= '<button onclick="editItem(' . $editArgs . ')" class="btn-icon btn-icon-primary" title="Edit item"><span class="material-symbols-outlined">edit</span></button><button onclick="openArchiveItem(' . $archiveArgs . ')" class="btn-icon btn-icon-slate" title="Archive item"><span class="material-symbols-outlined">archive</span></button></div>';
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
        'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($category !== '' ? $category : 'No category') . '</p></div>',
        'categoryHtml' => '<span class="text-sm font-bold text-slate-600">' . e($category !== '' ? $category : '-') . '</span>',
        'stockHtml' => '<div class="flex items-center gap-2"><span class="stock-bar"><span class="stock-bar-fill ' . e($barClass) . '" style="width: ' . (int) $pct . '%"></span></span><span class="text-sm font-bold ' . ($isLow ? 'text-amber-600' : 'text-slate-600') . '">' . (int) $item['quantity'] . ' ' . e($item['unit']) . '</span></div>',
        'reorderLevel' => (int) $item['reorder_level'],
        'expirationHtml' => '<span class="text-sm font-bold ' . $expirationClass . '">' . e($expirationLabel) . '</span>',
        'statusHtml' => inventory_status_badge($displayQuantityForStatus),
        'actionsHtml' => $actionsHtml,
    ];
}

$loanRows = [];
foreach ($loanRowsRaw as $loan) {
    $isBorrowed = ($loan['status'] ?? '') === 'Borrowed';
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
        'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($loan['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($loan['category'] ?: 'Equipment') . '</p></div>',
        'borrowerHtml' => '<div><strong class="text-sm text-slate-700">' . e($loan['borrower_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($loan['borrower_identifier'] ?: 'No ID recorded') . '</p></div>',
        'borrowedHtml' => '<div><strong class="text-sm text-slate-700">' . e(date('M d, g:i A', strtotime($loan['borrowed_at']))) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($loan['borrowed_by_name'] ?: 'System') . '</p></div>',
        'quantityHtml' => '<span class="text-sm font-bold text-slate-700">' . (int) $loan['borrowed_quantity'] . ' ' . e($loan['unit'] ?: 'unit') . '</span>',
        'statusHtml' => inventory_loan_status_badge((string) $loan['status']),
        'conditionHtml' => inventory_return_condition_badge($loan['return_condition'] ?? null),
        'actionsHtml' => $isBorrowed
            ? '<button onclick="openReturnLoan(' . $returnArgs . ')" class="btn btn-sm btn-outline"><span class="material-symbols-outlined text-[14px]">assignment_return</span>Return</button>'
            : $returnSummary,
    ];
}

$tableTitle = match ($activeTab) {
    'equipment' => 'Equipment Tracking',
    'expiring' => 'Expiring Soon',
    'archived' => 'Archived Inventory',
    default => 'Medicine Inventory',
};
$tableDescription = match ($activeTab) {
    'equipment' => count($equipmentItems) . ' active equipment item(s) available for clinic use.',
    'expiring' => count($expiring) . ' active item(s) expiring within 30 days.',
    'archived' => count($archivedItems) . ' item(s) removed from active inventory.',
    default => count($medicineItems) . ' active medicine item(s).',
};
$gridColumns = match ($activeTab) {
    'archived' => $archivedColumns,
    'equipment' => $equipmentColumns,
    default => $activeColumns,
};

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
        </div>
    </div>

    <?php render_ag_grid('inventoryGrid', $gridColumns, $inventoryRows, [
        'searchInput' => 'inventoryGridSearch',
        'emptyTitle' => match ($activeTab) {
            'equipment' => 'No equipment items',
            'expiring' => 'No expiring items',
            'archived' => 'No archived records',
            default => 'No inventory items',
        },
        'emptyText' => match ($activeTab) {
            'equipment' => 'Add clinic equipment to track active stock and availability.',
            'expiring' => 'No active items are expiring within the next 30 days.',
            'archived' => 'Archived medicine and equipment records will appear here.',
            default => 'Add medicines to start tracking stock.',
        },
    ]); ?>
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
            'height' => 'compact',
            'emptyTitle' => 'No equipment loans yet',
            'emptyText' => 'Borrowed equipment and completed returns will appear here.',
        ]); ?>
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
            <div data-medicine-panel="new" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="clinic-label">Item Name</label>
                    <input class="clinic-input" name="item_name" required placeholder="e.g. Paracetamol 500mg">
                </div>
                <div>
                    <label class="clinic-label">Category</label>
                    <input class="clinic-input" name="category" placeholder="e.g. Analgesic">
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
                        <label class="clinic-label">Expiration Date</label>
                        <input class="clinic-input" name="expiration_date" type="date" required>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addMedicineModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Restock this medicine?" data-confirm-message="Matching expiry batches will be updated. Different expiry dates will create a new batch row." data-confirm-toast="Restocking medicine...">
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
                <div class="md:col-span-2">
                    <label class="clinic-label">Equipment Name</label>
                    <input class="clinic-input" name="item_name" required placeholder="e.g. Pulse Oximeter">
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
                <div class="md:col-span-2">
                    <label class="clinic-label">Item Name</label>
                    <input class="clinic-input" name="item_name" id="editItemName" required>
                </div>
                <div>
                    <label class="clinic-label">Category</label>
                    <input class="clinic-input" name="category" id="editItemCategory">
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
                <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Archive Inventory Item</h3>
                <p class="text-sm font-bold text-slate-500 mt-1" id="archiveItemName">Move this item out of active inventory.</p>
            </div>
            <button onclick="closeModal('archiveItemModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="archive.php" id="archiveItemForm" data-inventory-form>
            <input type="hidden" name="id" id="archiveItemId">
            <div class="mb-4">
                <label class="clinic-label">Archive Quantity</label>
                <div class="flex items-center gap-3">
                    <input class="clinic-input" name="archive_quantity" id="archiveItemQuantity" type="number" min="1" value="1" required>
                    <span class="text-xs font-bold text-slate-500 shrink-0" id="archiveItemAvailable">Available: -</span>
                    <button type="button" class="btn btn-sm btn-outline shrink-0" onclick="selectAllArchiveQuantity()">All</button>
                </div>
            </div>
            <label class="clinic-label">Archive Reason</label>
            <textarea class="clinic-textarea" name="archived_reason" rows="4" required placeholder="e.g. Expired batch, damaged item, duplicate record, depleted supply"></textarea>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('archiveItemModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Archive selected quantity?" data-confirm-message="This will move only the selected quantity to archived records and keep any remaining stock active." data-confirm-toast="Archiving inventory item...">
                    <span class="material-symbols-outlined text-[18px]">archive</span>
                    Archive Item
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
                    <label class="clinic-label">Borrower Name</label>
                    <input class="clinic-input" name="borrower_name" required placeholder="Student, staff, or department name">
                </div>
                <div>
                    <label class="clinic-label">Borrower ID / Contact</label>
                    <input class="clinic-input" name="borrower_identifier" placeholder="Student ID, staff ID, or contact">
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="clinic-input" name="borrowed_quantity" id="borrowQuantity" type="number" min="1" value="1" required>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Expected Return</label>
                    <input class="clinic-input" name="due_at" type="datetime-local">
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
function editItem(id, name, category, quantity, unit, reorder, expiry) {
    document.getElementById('editItemId').value = id;
    document.getElementById('editItemName').value = name;
    document.getElementById('editItemCategory').value = category || '';
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
    const quantityInput = document.getElementById('archiveItemQuantity');
    const availableText = document.getElementById('archiveItemAvailable');
    const available = Math.max(0, Number(quantity || 0));
    quantityInput.max = String(available);
    quantityInput.value = available > 0 ? '1' : '0';
    availableText.textContent = `Available: ${available} ${unit || 'unit'}`;
    document.getElementById('archiveItemName').textContent = name ? `Archive damaged, broken, expired, or removed quantity from ${name}.` : 'Move quantity out of active inventory.';
    showModal('archiveItemModal');
}

function selectAllArchiveQuantity() {
    const quantityInput = document.getElementById('archiveItemQuantity');
    if (!quantityInput) return;
    quantityInput.value = quantityInput.max || quantityInput.value || '1';
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
