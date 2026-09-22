<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

header('Location: ' . (student_current_profile() === null ? 'patient-login.php' : 'patient-dashboard.php'));
exit;
