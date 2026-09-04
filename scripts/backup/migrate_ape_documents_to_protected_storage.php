<?php

require_once __DIR__ . '/../../app/services/ApeWorkflow.php';

$sourceDirectory = dirname(__DIR__, 2) . '/public/uploads/ape';
$targetDirectory = ape_document_storage_root();

if (!is_dir($sourceDirectory)) {
    fwrite(STDOUT, "No legacy APE document directory exists. Nothing to migrate.\n");
    exit(0);
}

if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
    fwrite(STDERR, "Unable to create protected APE document directory.\n");
    exit(1);
}

$migrated = 0;
$alreadyProtected = 0;
$entries = new DirectoryIterator($sourceDirectory);

foreach ($entries as $entry) {
    if (!$entry->isFile() || $entry->isDot()) {
        continue;
    }

    $sourcePath = $entry->getPathname();
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $entry->getFilename();

    if (is_file($targetPath)) {
        if (!hash_equals(hash_file('sha256', $sourcePath), hash_file('sha256', $targetPath))) {
            fwrite(STDERR, "Conflicting protected file: {$entry->getFilename()}\n");
            exit(1);
        }
        if (!unlink($sourcePath)) {
            fwrite(STDERR, "Unable to remove verified legacy duplicate: {$entry->getFilename()}\n");
            exit(1);
        }
        $alreadyProtected++;
        continue;
    }

    if (!rename($sourcePath, $targetPath)) {
        fwrite(STDERR, "Unable to move APE document: {$entry->getFilename()}\n");
        exit(1);
    }
    $migrated++;
}

fwrite(STDOUT, "Migrated {$migrated} APE document(s); removed {$alreadyProtected} verified duplicate(s).\n");
