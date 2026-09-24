<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/ApeWorkflow.php';

$apply = in_array('--apply', $argv, true);
$db = auth_db();
$rows = $db->query("
    SELECT d.document_id, d.document_type, d.original_filename, d.file_path, p.id_number
    FROM ape_documents d
    JOIN ape_records ar ON ar.ape_id = d.ape_id
    JOIN people p ON p.id = ar.patient_id
    ORDER BY d.document_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
$skipped = [];
foreach ($rows as $row) {
    $lookup = ape_document_lookup((string) $row['file_path']);
    if ($lookup['status'] !== 'available' || $lookup['absolute_path'] === null) {
        $skipped[] = (int) $row['document_id'];
        continue;
    }

    $extension = ape_document_safe_extension((string) $row['original_filename']);
    if ($extension === '') {
        $extension = ape_document_safe_extension((string) $row['file_path']);
    }
    if ($extension === '') {
        throw new RuntimeException('Unable to determine an extension for APE document #' . (int) $row['document_id'] . '.');
    }

    $filename = ape_document_name_base((string) $row['id_number'], (string) $row['document_type'])
        . '_existing-' . (int) $row['document_id'] . $extension;
    $targetPath = ape_document_storage_root() . DIRECTORY_SEPARATOR . $filename;
    $currentPath = (string) $lookup['absolute_path'];
    $normalizedFilePath = 'storage/documents/ape/' . $filename;
    if ($currentPath !== $targetPath && is_file($targetPath)) {
        throw new RuntimeException('Target already exists for APE document #' . (int) $row['document_id'] . ': ' . $filename);
    }
    if ($currentPath === $targetPath && (string) $row['file_path'] === $normalizedFilePath) {
        continue;
    }
    $plan[] = [
        'document_id' => (int) $row['document_id'],
        'source' => $currentPath,
        'target' => $targetPath,
        'previous_file_path' => (string) $row['file_path'],
        'file_path' => $normalizedFilePath,
    ];
}

if (!$apply) {
    foreach ($plan as $item) {
        echo $item['document_id'], ': ', basename($item['source']), ' -> ', basename($item['target']), PHP_EOL;
    }
    echo 'Planned: ', count($plan), '; skipped missing: ', implode(', ', $skipped) ?: 'none', PHP_EOL;
    exit(0);
}

$renamed = [];
try {
    $db->beginTransaction();
    $update = $db->prepare('UPDATE ape_documents SET file_path = ? WHERE document_id = ? AND file_path = ?');
    foreach ($plan as $item) {
        if ($item['source'] !== $item['target'] && !rename($item['source'], $item['target'])) {
            throw new RuntimeException('Could not rename APE document #' . $item['document_id'] . '.');
        }
        $renamed[] = $item;
        $update->execute([$item['file_path'], $item['document_id'], $item['previous_file_path']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('APE document #' . $item['document_id'] . ' changed while normalizing filenames.');
        }
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach (array_reverse($renamed) as $item) {
        if ($item['source'] !== $item['target'] && is_file($item['target'])) {
            @rename($item['target'], $item['source']);
        }
    }
    throw $error;
}

echo 'Normalized ', count($plan), ' available APE document filename(s); skipped missing: ', implode(', ', $skipped) ?: 'none', PHP_EOL;
