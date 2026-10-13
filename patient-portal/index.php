<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

header('Location: ' . student_portal_url(student_current_profile() === null ? 'login' : 'dashboard'));
exit;
