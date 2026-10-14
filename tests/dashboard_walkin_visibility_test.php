<?php

declare(strict_types=1);

$dashboard = file_get_contents(__DIR__ . '/../public/dashboard.php') ?: '';

if (!str_contains($dashboard, "FROM visits WHERE DATE(visit_datetime) = CURDATE()\"")) {
    throw new RuntimeException('Dashboard must count every visit logged today.');
}

if (!str_contains($dashboard, "FROM visits WHERE DATE(visit_datetime) = CURDATE() AND status IN ('Unaddressed', 'Active')")) {
    throw new RuntimeException('Dashboard must count all active or unaddressed patients logged today.');
}

if (str_contains($dashboard, "TIME(visit_datetime) BETWEEN '08:00:00' AND '17:00:00'")) {
    throw new RuntimeException('Dashboard must not hide after-hours walk-ins.');
}

echo "Dashboard walk-in visibility checks passed.\n";
