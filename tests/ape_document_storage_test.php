<?php

// Run: php tests/ape_document_storage_test.php
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

function expect_document_storage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$storageRoot = str_replace('\\', '/', ape_document_storage_root());
expect_document_storage(
    str_ends_with($storageRoot, '/storage/documents/ape'),
    'APE documents must resolve beneath protected storage.'
);
expect_document_storage(
    ape_document_absolute_path('../../app/config/database.php') === null,
    'Traversal paths must be rejected.'
);
expect_document_storage(
    ape_document_absolute_path('storage/documents/ape/nested/file.pdf') === null,
    'Nested paths must be rejected.'
);
expect_document_storage(
    ape_document_absolute_path('unapproved/file.pdf') === null,
    'Unknown storage prefixes must be rejected.'
);

echo "APE protected document storage tests passed.\n";
