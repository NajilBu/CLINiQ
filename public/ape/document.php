<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';

require_login();
$apeUser = current_user() ?? [];
if (!in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true)) {
    http_response_code(403);
    exit('Only authorized clinic staff can view APE documents.');
}

$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = auth_db()->prepare('SELECT d.document_id, d.original_filename, d.file_path, d.document_type, p.id_number FROM ape_documents d JOIN ape_records ar ON ar.ape_id = d.ape_id JOIN people p ON p.id = ar.patient_id WHERE d.document_id = ? LIMIT 1');
$stmt->execute([$documentId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('Document record not found.');
}
if (!ape_stream_document($document)) {
    http_response_code(404);
    exit('The document record exists, but its uploaded file is missing from clinic storage. Restore the document volume from backup or ask the patient to upload it again.');
}
