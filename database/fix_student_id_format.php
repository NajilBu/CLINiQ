<?php

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../app/config/env.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/student_id.php';

$db = db();
$databaseName = env_value('DB_NAME', 'cliniq');
$oldStudentIdPattern = '/20(\d{2})-(\d{5})/';

function normalize_legacy_student_ids(string $value): string
{
    global $oldStudentIdPattern;

    return preg_replace_callback(
        $oldStudentIdPattern,
        static fn(array $match): string => $match[1] . '-' . $match[2],
        $value
    );
}

$tableStmt = $db->prepare(
    "SELECT TABLE_NAME
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = ?
       AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME"
);
$tableStmt->execute([$databaseName]);
$tables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);

$pkStmt = $db->prepare(
    "SELECT COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = ?
       AND TABLE_NAME = ?
       AND CONSTRAINT_NAME = 'PRIMARY'
     ORDER BY ORDINAL_POSITION"
);

$columnStmt = $db->prepare(
    "SELECT COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ?
       AND TABLE_NAME = ?
       AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
     ORDER BY ORDINAL_POSITION"
);

$totalUpdated = 0;
$skippedTables = [];
$failedRows = [];

foreach ($tables as $table) {
    $pkStmt->execute([$databaseName, $table]);
    $primaryKeys = $pkStmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($primaryKeys) !== 1) {
        $skippedTables[] = $table;
        continue;
    }

    $primaryKey = $primaryKeys[0];
    $columnStmt->execute([$databaseName, $table]);
    $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($columns as $column) {
        $selectSql = sprintf(
            'SELECT `%s`, `%s` FROM `%s` WHERE `%s` REGEXP ?',
            str_replace('`', '``', $primaryKey),
            str_replace('`', '``', $column),
            str_replace('`', '``', $table),
            str_replace('`', '``', $column)
        );
        $selectStmt = $db->prepare($selectSql);
        $selectStmt->execute(['20[0-9]{2}-[0-9]{5}']);
        $rows = $selectStmt->fetchAll();

        if (!$rows) {
            continue;
        }

        $updateSql = sprintf(
            'UPDATE `%s` SET `%s` = ? WHERE `%s` = ?',
            str_replace('`', '``', $table),
            str_replace('`', '``', $column),
            str_replace('`', '``', $primaryKey)
        );
        $updateStmt = $db->prepare($updateSql);

        foreach ($rows as $row) {
            $currentValue = (string) $row[$column];
            $newValue = normalize_legacy_student_ids($currentValue);

            if ($newValue === $currentValue) {
                continue;
            }

            try {
                $updateStmt->execute([$newValue, $row[$primaryKey]]);
                $totalUpdated += $updateStmt->rowCount();
                echo "{$table}.{$column} #{$row[$primaryKey]}: {$currentValue} -> {$newValue}\n";
            } catch (Throwable $exception) {
                $failedRows[] = "{$table}.{$column} #{$row[$primaryKey]}: {$exception->getMessage()}";
            }
        }
    }
}

echo "Updated rows: {$totalUpdated}\n";

if ($skippedTables) {
    echo 'Skipped tables without a single-column primary key: ' . implode(', ', $skippedTables) . "\n";
}

if ($failedRows) {
    echo "Failed rows:\n";
    foreach ($failedRows as $failure) {
        echo "- {$failure}\n";
    }
    exit(1);
}

echo 'Student ID format is now standardized as ' . STUDENT_ID_FORMAT_LABEL . ".\n";
