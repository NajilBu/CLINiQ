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
expect_document_storage(
    ape_document_relative_name('storage/documents/ape/patient-ape_1234_abcd.pdf') === 'patient-ape_1234_abcd.pdf',
    'Current protected document paths must be recognized.'
);
expect_document_storage(
    ape_document_relative_name('uploads/ape/ape_1234_abcd.png') === 'ape_1234_abcd.png',
    'Legacy upload paths must be recognized.'
);
expect_document_storage(
    ape_document_relative_name('public/uploads/ape/ape_1234_abcd.jpg') === 'ape_1234_abcd.jpg',
    'Legacy public upload paths must be recognized.'
);
expect_document_storage(
    ape_document_relative_name('/var/www/html/storage/documents/ape/patient-ape_1234_abcd.pdf') === 'patient-ape_1234_abcd.pdf',
    'Absolute container paths must be normalized safely.'
);
expect_document_storage(
    ape_document_relative_name('C:\\xampp\\htdocs\\CLINiQ\\public\\uploads\\ape\\ape_1234_abcd.png') === 'ape_1234_abcd.png',
    'Historical Windows paths must be normalized safely.'
);
expect_document_storage(
    ape_document_relative_name('patient-ape_1234_abcd.pdf') === 'patient-ape_1234_abcd.pdf',
    'Bare generated filenames must be recognized.'
);
expect_document_storage(
    ape_document_relative_name('storage/documents/ape/../../database.php') === null,
    'Traversal segments must remain rejected after legacy normalization.'
);
expect_document_storage(
    (new ReflectionFunction('ape_assert_documents_available'))->getNumberOfParameters() === 4,
    'APE workflow must expose a storage-integrity guard for approval transitions.'
);

echo "APE protected document storage tests passed.\n";
