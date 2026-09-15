<?php

declare(strict_types=1);

$dashboard = file_get_contents(__DIR__ . '/../public/dashboard.php');
$styles = file_get_contents(__DIR__ . '/../public/assets/css/app.css');

function expect_dashboard_unavailable(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect_dashboard_unavailable(
    str_contains($dashboard, 'FROM appointment_availability_blocks')
        && str_contains($dashboard, 'WHERE block_date = ?'),
    'The dashboard must load unavailable periods for today only.'
);
expect_dashboard_unavailable(
    str_contains($dashboard, "empty(\$block['start_time']) || empty(\$block['end_time'])"),
    'Full-day unavailable periods must be supported.'
);
expect_dashboard_unavailable(
    str_contains($dashboard, "max(\$startMinute, \$scheduleStartMinutes)")
        && str_contains($dashboard, "min(\$endMinute, \$scheduleEndMinutes)"),
    'Unavailable periods must be clipped to the visible schedule.'
);
expect_dashboard_unavailable(
    str_contains($dashboard, 'count($scheduleUnavailableItems) ?> unavailable'),
    'The schedule header must report the unavailable-period count.'
);
expect_dashboard_unavailable(
    str_contains($dashboard, 'dashboard-calendar-unavailable-layer')
        && str_contains($dashboard, "appointments/index.php#clinic-availability"),
    'Unavailable periods must render in the timeline and link to clinic availability.'
);
expect_dashboard_unavailable(
    str_contains($styles, '.dashboard-calendar-unavailable')
        && str_contains($styles, 'repeating-linear-gradient')
        && str_contains($styles, '.dashboard-calendar-events'),
    'Unavailable periods need a distinct layer while appointment cards remain visible.'
);

echo "Dashboard unavailable schedule test passed.\n";
