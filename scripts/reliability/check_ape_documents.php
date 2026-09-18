<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';

$documents = auth_db()->query('
    SELECT document_id, ape_id, original_filename, file_path, uploaded_at
    FROM ape_documents
    ORDER BY document_id ASC
')->fetchAll();

$counts = ['available' => 0, 'invalid_path' => 0, 'missing_file' => 0];
foreach ($documents as $document) {
    $lookup = ape_document_lookup((string) $document['file_path']);
    $status = (string) $lookup['status'];
    $counts[$status]++;
    if ($status === 'available') {
        continue;
    }
    printf(
        "%s: document_id=%d ape_id=%d filename=%s stored_path=%s uploaded_at=%s\n",
        strtoupper($status),
        (int) $document['document_id'],
        (int) $document['ape_id'],
        (string) $document['original_filename'],
        (string) $document['file_path'],
        (string) $document['uploaded_at']
    );
}

printf(
    "APE document audit: total=%d available=%d invalid_path=%d missing_file=%d\n",
    count($documents),
    $counts['available'],
    $counts['invalid_path'],
    $counts['missing_file']
);

exit(($counts['invalid_path'] + $counts['missing_file']) > 0 ? 1 : 0);
