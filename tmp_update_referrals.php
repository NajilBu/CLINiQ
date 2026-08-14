<?php

require_once __DIR__ . '/app/config/database.php';

$db = auth_db();
$db->exec("UPDATE referrals SET status = 'Completed' WHERE status = 'Pending' OR status IS NULL OR status = ''");
$db->exec("ALTER TABLE referrals MODIFY status VARCHAR(40) NOT NULL DEFAULT 'Completed'");

echo "Database updated successfully.\n";
$rows = $db->query("SELECT status, COUNT(*) AS count FROM referrals GROUP BY status")->fetchAll();
print_r($rows);
