<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqInventoryWorkflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

/** @return list<list<string>> */
function cliniq_inventory_import_rows(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a valid Excel or CSV file to import.');
    }
    if ((int) ($upload['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('Import files must be 8 MB or smaller.');
    }
    $path = (string) ($upload['tmp_name'] ?? '');
    $name = strtolower((string) ($upload['name'] ?? ''));
    if ($path === '' || !is_uploaded_file($path)) {
        throw new InvalidArgumentException('The selected import file could not be verified.');
    }
    if (str_ends_with($name, '.csv')) {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new InvalidArgumentException('The CSV file could not be opened.');
        $rows = [];
        $first = fgets($handle);
        if ($first === false) return [];
        $delimiter = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',';
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) $rows[] = array_map(static fn($v) => trim((string) $v), $row);
        fclose($handle);
        return $rows;
    }
    if (!str_ends_with($name, '.xlsx')) {
        throw new InvalidArgumentException('Use an .xlsx or .csv file.');
    }
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel import is unavailable because ZIP support is not enabled.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new InvalidArgumentException('The Excel file could not be opened.');
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $doc = simplexml_load_string($xml);
        foreach ($doc->si as $item) {
            $text = '';
            foreach ($item->xpath('.//t') ?: [] as $part) $text .= (string) $part;
            $shared[] = $text;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) throw new InvalidArgumentException('The Excel file has no first worksheet.');
    $doc = simplexml_load_string($sheet);
    $rows = [];
    foreach ($doc->sheetData->row ?: [] as $row) {
        $values = [];
        foreach ($row->c ?: [] as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            preg_match('/([A-Z]+)\d+/', $ref, $match);
            $column = 0;
            foreach (str_split($match[1] ?? '') as $letter) $column = $column * 26 + ord($letter) - 64;
            $raw = (string) ($cell->v ?? '');
            if ((string) ($cell['t'] ?? '') === 's') $raw = $shared[(int) $raw] ?? '';
            if ((string) ($cell['t'] ?? '') === 'inlineStr') {
                $raw = '';
                foreach ($cell->is->t ?? [] as $part) $raw .= (string) $part;
            }
            $values[$column - 1] = $raw;
        }
        if ($values !== []) {
            ksort($values);
            $rows[] = array_map(static fn($v) => trim((string) $v), array_pad($values, max(0, count($values)), ''));
        }
    }
    return $rows;
}

function cliniq_inventory_import_header(string $value): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/', '_', trim($value)) ?? '');
}

function cliniq_inventory_import_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    if (is_numeric($value) && (float) $value >= 1 && (float) $value < 100000) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', '1899-12-30')->modify('+' . (int) floor((float) $value) . ' days');
        return $date->format('Y-m-d');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: date_create($value);
    return $date ? $date->format('Y-m-d') : null;
}

try {
    $rows = cliniq_inventory_import_rows($_FILES['import_file'] ?? []);
    if (count($rows) < 2) throw new InvalidArgumentException('The spreadsheet must include a header row and at least one data row.');
    if (count($rows) > 5001) throw new InvalidArgumentException('Import up to 5,000 rows at a time.');
    $headers = array_map('cliniq_inventory_import_header', array_shift($rows));
    $columns = array_flip($headers);
    $get = static function (array $row, array $columns, array $names): string {
        foreach ($names as $name) if (isset($columns[$name])) return trim((string) ($row[$columns[$name]] ?? ''));
        return '';
    };
    if (!isset($columns['action']) || !isset($columns['inventory_type'])) {
        throw new InvalidArgumentException('The spreadsheet must include Action and Inventory Type columns. Download the template for the required format.');
    }
    $db = cliniq_inventory_db();
    $staffId = cliniq_inventory_staff_person_id();
    $db->beginTransaction();
    $created = 0;
    $insertNew = $db->prepare('INSERT INTO inventory_items (item_name, item_type, description, unit, quantity, reorder_level, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $lookup = $db->prepare('SELECT item_id, item_name, item_type, description, unit, reorder_level FROM inventory_items WHERE is_active = 1 AND item_type = ? AND (item_id = ? OR LOWER(item_name) = LOWER(?)) ORDER BY item_id LIMIT 1');
    $insertBatch = $db->prepare('INSERT INTO inventory_items (item_name, item_type, description, unit, quantity, reorder_level, expiration_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($rows as $number => $row) {
        if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) continue;
        $line = $number + 2;
        $action = strtoupper($get($row, $columns, ['action']));
        $rawType = ucfirst(strtolower($get($row, $columns, ['inventory_type', 'type'])));
        if (!in_array($action, ['ADD', 'RESTOCK'], true)) throw new InvalidArgumentException('Row ' . $line . ': Action must be ADD or RESTOCK.');
        if (!in_array($rawType, ['Medicine', 'Equipment'], true)) throw new InvalidArgumentException('Row ' . $line . ': Inventory Type must be Medicine or Equipment.');
        $identity = $get($row, $columns, ['item_id_or_item_name', 'item_id', 'item_name', 'name']);
        $quantity = (int) $get($row, $columns, ['quantity', 'received_quantity']);
        $expiration = $get($row, $columns, ['expiration_date', 'expiry_date', 'expiration']);
        if ($action === 'ADD') {
            $name = $identity;
            $unit = $get($row, $columns, ['unit', 'units']);
            if ($name === '' || $unit === '') throw new InvalidArgumentException('Row ' . $line . ': item name and unit are required for ADD.');
            $parsedExpiration = $rawType === 'Medicine' && $expiration !== '' ? cliniq_inventory_import_date($expiration) : null;
            if ($rawType === 'Medicine' && $expiration !== '' && $parsedExpiration === null) throw new InvalidArgumentException('Row ' . $line . ': invalid expiration date.');
            $insertNew->execute([$name, $rawType, $get($row, $columns, ['description', 'details']) ?: null, $unit, max(0, $quantity), max(0, (int) $get($row, $columns, ['reorder_level', 'minimum_available'])), $parsedExpiration]);
            $itemId = (int) $db->lastInsertId();
            if ($quantity > 0) cliniq_inventory_record_transaction($db, $itemId, 'Stock In', $quantity, $quantity, $staffId, null, null, 'Excel inventory import');
        } else {
            if ($identity === '' || $quantity < 1) throw new InvalidArgumentException('Row ' . $line . ': item ID/name and a positive quantity are required for RESTOCK.');
            $lookup->execute([$rawType, ctype_digit($identity) ? (int) $identity : 0, $identity]);
            $item = $lookup->fetch();
            if (!$item) throw new InvalidArgumentException('Row ' . $line . ': active ' . strtolower($rawType) . ' item not found.');
            $parsedExpiration = $rawType === 'Medicine' ? cliniq_inventory_import_date($expiration) : null;
            if ($rawType === 'Medicine' && $parsedExpiration === null) throw new InvalidArgumentException('Row ' . $line . ': valid expiration date is required for RESTOCK.');
            $insertBatch->execute([$item['item_name'], $item['item_type'], $item['description'], $item['unit'], $quantity, (int) $item['reorder_level'], $parsedExpiration]);
            $batchId = (int) $db->lastInsertId();
            cliniq_inventory_record_transaction($db, $batchId, 'Stock In', $quantity, $quantity, $staffId, null, null, 'Excel restock import');
        }
        $created++;
    }
    if ($created < 1) throw new InvalidArgumentException('No importable rows were found.');
    $db->commit();
    flash_message('success', $created . ' mixed inventory row' . ($created === 1 ? '' : 's') . ' imported successfully.');
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
}

header('Location: index.php');
exit;
