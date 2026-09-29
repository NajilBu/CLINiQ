<?php

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

$profile = student_require_login();
$patientId = (int) $profile['person_id'];
$documentId = (int) ($_GET['id'] ?? 0);

if ($documentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = auth_db()->prepare('SELECT d.original_filename, d.file_path, d.document_type, p.id_number FROM ape_documents d JOIN ape_records ar ON ar.ape_id = d.ape_id JOIN people p ON p.id = ar.patient_id WHERE d.document_id = ? AND ar.patient_id = ? LIMIT 1');
$stmt->execute([$documentId, $patientId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('Document record not found.');
}
if (!ape_stream_document($document)) {
    http_response_code(404);
    exit('The uploaded document file is missing from clinic storage. Please contact the clinic so it can be restored or uploaded again.');
}
