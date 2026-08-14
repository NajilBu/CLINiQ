<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../patient-portal/patient-login.php' . ($query !== '' ? '?' . $query : ''));
exit;
