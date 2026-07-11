<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
require_login();
ensure_alert_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $redirect = $_POST['redirect'] ?? 'index.php';
    $resolutionReport = trim((string) ($_POST['resolution_report'] ?? ''));
    $allowed = ['Pending', 'In Progress', 'Resolved', 'Cancelled'];
    $allowedRedirects = ['index.php', '../dashboard.php'];
    $isAllowedViewRedirect = (bool) preg_match('/^view\.php\?id=\d+$/', $redirect);

    if ($id > 0 && in_array($status, $allowed, true)) {
        if ($status === 'Resolved') {
            if ($resolutionReport === '') {
                flash_message('error', 'Write the accident or response report before resolving this alert.');
                header('Location: view.php?id=' . $id);
                exit;
            }

            $user = current_user();
            $stmt = db()->prepare('UPDATE nurse_alerts SET status = ?, resolution_report = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?');
            $stmt->execute([$status, $resolutionReport, (int) ($user['id'] ?? 0) ?: null, $id]);
            flash_message('success', 'Alert resolved with report.');
        } else {
            $stmt = db()->prepare('UPDATE nurse_alerts SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            flash_message('success', 'Alert status updated to "' . $status . '".');
        }
    }

    header('Location: ' . (in_array($redirect, $allowedRedirects, true) || $isAllowedViewRedirect ? $redirect : 'index.php'));
    exit;
}

header('Location: index.php');
