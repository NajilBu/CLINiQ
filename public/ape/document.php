<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';

require_login();

$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = auth_db()->prepare('SELECT document_id, original_filename, file_path FROM ape_documents WHERE document_id = ? LIMIT 1');
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
$fallbackName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'document';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: inline; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($absolutePath);
exit;
