<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

// Redirect old patient creation page to patient account management in Cliniq_db
header('Location: ' . app_url('patient-accounts/index.php'));
exit;
