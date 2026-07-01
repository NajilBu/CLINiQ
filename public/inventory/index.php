<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$items = db()->query('SELECT * FROM inventory_items ORDER BY item_name')->fetchAll();

render_header('Inventory');
?>
<h1 class="h3 mb-4">Medicine and Clinical Item Inventory</h1>
<section class="content-panel">
    <p class="text-secondary">Inventory list placeholder. Add create/edit screens after patient and visit workflows are stable.</p>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Item</th><th>Category</th><th>Quantity</th><th>Reorder Level</th><th>Expiration</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['item_name']) ?></td>
                    <td><?= e($item['category']) ?></td>
                    <td><?= (int) $item['quantity'] ?> <?= e($item['unit']) ?></td>
                    <td><?= (int) $item['reorder_level'] ?></td>
                    <td><?= e($item['expiration_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="5" class="text-secondary">No inventory items yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
