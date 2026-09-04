<?php
// Static, read-only contract test for the placeholder Settings page.
$source = file_get_contents(__DIR__ . '/../public/settings/index.php');
function expect_backup(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
expect_backup(str_contains($source, "'backup', 'maintenance'"), 'Backup is an allowed Settings tab.');
expect_backup(str_contains($source, 'index.php?tab=backup'), 'Backup navigation link exists.');
$start = strpos($source, '<div id="settings-backup"');
$end = strpos($source, '<div id="settings-maintenance"', $start);
expect_backup($start !== false && $end !== false && $end > $start, 'Backup panel exists before Maintenance.');
$panel = substr($source, $start, $end - $start);
foreach (['System Backup', 'Not configured', 'Once daily', '6:00 PM', 'Internal drive', 'No backup yet', 'CLINiQ database', 'Uploaded files'] as $copy) {
    expect_backup(str_contains($panel, $copy), 'Placeholder includes: ' . $copy);
}
expect_backup(substr_count($panel, 'Coming soon') === 2, 'Both future controls say Coming soon.');
expect_backup(substr_count($panel, '<button') === 2 && substr_count($panel, 'disabled') >= 2, 'Only two disabled buttons are present.');
expect_backup(!str_contains($panel, '<form') && !str_contains($panel, 'name="action"'), 'Placeholder cannot submit an action.');
expect_backup(str_contains($panel, 'No automatic backup is running'), 'Page clearly warns that backup is not active.');
echo "PASS: Backup tab routing, plan summary, coverage, disabled controls, and non-operational warning. No backup or database writes.\n";
