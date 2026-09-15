<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/PatientNotification.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['Pending', 'Completed', 'Cancelled'];

    if ($id > 0 && in_array($status, $allowed, true)) {
        $db = auth_db();
        try {
            $db->beginTransaction();
            $lookup = $db->prepare('SELECT referral_id, patient_person_id, referred_to, reason, status FROM referrals WHERE referral_id = ? FOR UPDATE');
            $lookup->execute([$id]);
            $referral = $lookup->fetch();
            if (!$referral) {
                throw new RuntimeException('Referral not found.');
            }
            $changed = (string) $referral['status'] !== $status;
            if ($changed) {
                $stmt = $db->prepare('UPDATE referrals SET status = ? WHERE referral_id = ?');
                $stmt->execute([$status, $id]);
                patient_notification_create(
                    $db,
                    (int) $referral['patient_person_id'],
                    (int) (current_user()['person_id'] ?? 0) ?: null,
                    'referral',
                    'Referral status updated',
                    'Your referral to ' . $referral['referred_to'] . ' is now ' . $status . '.',
                    'patient-dashboard.php',
                    'referral',
                    $id
                );
            }
            $db->commit();
            flash_message('success', $changed ? 'Referral status updated to "' . $status . '".' : 'Referral status is already up to date.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash_message('error', $e->getMessage());
        }
    }

    header('Location: index.php');
    exit;
}

header('Location: index.php');
