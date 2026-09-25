<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = cliniq_inventory_db();
    $type = cliniq_inventory_item_type((string) ($_POST['category'] ?? 'Medicine'));
    try {
        $fieldRows = static function (string $field): array {
            $value = $_POST[$field] ?? [];
            return is_array($value) ? array_values($value) : [$value];
        };
        $names = $fieldRows('item_name');
        $units = $fieldRows('unit');
        $descriptions = $fieldRows('description');
        $quantities = $fieldRows('quantity');
        $reorderLevels = $fieldRows('reorder_level');
        $expirations = $fieldRows('expiration_date');
        $rowCount = count($names);
        if ($rowCount < 1 || $rowCount > 100) {
            throw new InvalidArgumentException('Add between 1 and 100 inventory rows.');
        }

        $staffId = cliniq_inventory_staff_person_id();
        $db->beginTransaction();
        $stmt = $db->prepare('
            INSERT INTO inventory_items (
                item_name, item_type, description, unit,
                quantity, reorder_level, expiration_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $created = 0;
        for ($index = 0; $index < $rowCount; $index++) {
            $name = trim((string) ($names[$index] ?? ''));
            $unit = trim((string) ($units[$index] ?? ''));
            $quantity = max(0, (int) ($quantities[$index] ?? 0));
            $reorderLevel = max(0, (int) ($reorderLevels[$index] ?? 0));
            if ($name === '' || $unit === '') {
                throw new InvalidArgumentException('Item name and unit are required for every row.');
            }
            $expiration = trim((string) ($expirations[$index] ?? '')) ?: null;
            if ($type === 'Medicine' && $expiration !== null) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $expiration);
                if (!$parsed || $parsed->format('Y-m-d') !== $expiration) {
                    throw new InvalidArgumentException('Enter a valid expiration date for every medicine row.');
                }
            } else {
                $expiration = null;
            }
            $stmt->execute([$name, $type, trim((string) ($descriptions[$index] ?? '')) ?: null, $unit, $quantity, $reorderLevel, $expiration]);
            $itemId = (int) $db->lastInsertId();
            if ($quantity > 0) {
                cliniq_inventory_record_transaction($db, $itemId, 'Stock In', $quantity, $quantity, $staffId, null, null, 'Initial inventory quantity');
            }
            $created++;
        }
        $db->commit();
        flash_message('success', $created . ' inventory item' . ($created === 1 ? '' : 's') . ' added to Cliniq_db inventory.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
    }
    header('Location: index.php?tab=' . ($type === 'Equipment' ? 'equipment' : 'medicine'));
    exit;
}

header('Location: index.php');
