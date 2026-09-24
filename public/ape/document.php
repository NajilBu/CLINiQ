<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';

require_login();

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
$documentLookup = ape_document_lookup((string) $document['file_path']);
$absolutePath = $documentLookup['absolute_path'];
if ($absolutePath === null) {
    http_response_code(404);
    exit('The document record exists, but its uploaded file is missing from clinic storage. Restore the document volume from backup or ask the patient to upload it again.');
}

$detectedType = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
$allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
$mimeType = in_array($detectedType, $allowedTypes, true) ? $detectedType : 'application/octet-stream';
$originalName = trim((string) ($document['original_filename'] ?? '')) ?: basename($absolutePath);
$downloadName = ape_document_download_name((string) ($document['id_number'] ?? ''), (string) ($document['document_type'] ?? ''), $originalName);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: inline; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($absolutePath);
exit;
