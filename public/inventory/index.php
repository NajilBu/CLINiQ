<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$items = db()->query('SELECT * FROM inventory_items ORDER BY item_name')->fetchAll();
$lowStock = array_filter($items, fn($item) => (int) $item['quantity'] <= (int) $item['reorder_level']);
$expiring = array_filter($items, fn($item) => $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days'));

render_header('Inventory');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Inventory & Tracking</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Manage clinic medicines, supplies, and low-stock warnings.</p>
    </div>
    <div class="flex gap-3">
        <button class="px-5 py-3 bg-primary text-white rounded-2xl flex items-center gap-2 text-sm font-bold shadow-lg shadow-primary/20" type="button">
            <span class="material-symbols-outlined text-[20px]">medication</span>
            + Medicine
        </button>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <span class="material-symbols-outlined">check_circle</span>
        </div>
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase">Inventory Items</p>
            <p class="text-2xl font-headline font-extrabold text-slate-800"><?= count($items) ?></p>
        </div>
    </div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <span class="material-symbols-outlined">warning</span>
        </div>
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase">Low Stock</p>
            <p class="text-2xl font-headline font-extrabold text-slate-800"><?= count($lowStock) ?></p>
        </div>
    </div>
    <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center">
            <span class="material-symbols-outlined">event_busy</span>
        </div>
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase">Expiring Soon</p>
            <p class="text-2xl font-headline font-extrabold text-slate-800"><?= count($expiring) ?></p>
        </div>
    </div>
</div>

<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Medicine Inventory</h2>
        <p class="text-xs font-bold text-slate-500 mb-0">Stock levels and expiration monitoring.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead><tr class="bg-slate-50 text-[10px] uppercase font-black text-slate-400"><th class="p-4">Item</th><th class="p-4">Category</th><th class="p-4">Quantity</th><th class="p-4">Reorder Level</th><th class="p-4">Expiration</th><th class="p-4">Status</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php $isLow = (int) $item['quantity'] <= (int) $item['reorder_level']; ?>
                <tr class="border-t border-slate-100">
                    <td class="p-4"><strong class="text-sm text-slate-800"><?= e($item['item_name']) ?></strong></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($item['category']) ?></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= (int) $item['quantity'] ?> <?= e($item['unit']) ?></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= (int) $item['reorder_level'] ?></td>
                    <td class="p-4 text-sm font-bold text-slate-600"><?= e($item['expiration_date']) ?: 'None' ?></td>
                    <td class="p-4"><span class="px-3 py-1 rounded-full text-xs font-black <?= $isLow ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' ?>"><?= $isLow ? 'Low Stock' : 'In Stock' ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="6" class="p-6 text-sm font-bold text-slate-500">No inventory items yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
