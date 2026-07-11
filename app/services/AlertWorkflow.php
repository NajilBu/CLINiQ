<?php

function ensure_alert_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query('SHOW COLUMNS FROM nurse_alerts');
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE nurse_alerts ADD COLUMN {$name} {$definition}");
            $columns[$name] = ['Field' => $name];
        }
    };

    $addColumn('photo_path', 'VARCHAR(255) NULL AFTER details');
    $addColumn('resolution_report', 'TEXT NULL AFTER status');
    $addColumn('resolved_by', 'INT NULL AFTER resolution_report');
    $addColumn('resolved_at', 'DATETIME NULL AFTER resolved_by');

    $ready = true;
}

function save_alert_photo_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        flash_message('error', 'The alert photo could not be uploaded.');
        return null;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        flash_message('error', 'Alert photo must be 5MB or smaller.');
        return null;
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        flash_message('error', 'Alert photo must be a JPG, PNG, or WebP image.');
        return null;
    }

    $uploadDir = dirname(__DIR__, 2) . '/public/uploads/alerts';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = 'alert-' . date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
    $target = $uploadDir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        flash_message('error', 'The alert photo could not be saved.');
        return null;
    }

    return 'uploads/alerts/' . $filename;
}
