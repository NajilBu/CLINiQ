<?php

function ensure_appointment_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'status'");
    $statusColumn = $stmt->fetch();
    $type = $statusColumn['Type'] ?? '';

    if (!str_contains($type, "'Pending'")) {
        $db->exec("ALTER TABLE appointments MODIFY status ENUM('Pending', 'Scheduled', 'Completed', 'Cancelled', 'No Show') NOT NULL DEFAULT 'Pending'");
    }

    $ready = true;
}

function appointment_status_badge_class(string $status): string
{
    return match ($status) {
        'Pending' => 'badge-pending',
        'Scheduled' => 'badge-in-progress',
        'Completed' => 'badge-completed',
        'Cancelled', 'No Show' => 'badge-cancelled',
        default => 'badge-pending',
    };
}
