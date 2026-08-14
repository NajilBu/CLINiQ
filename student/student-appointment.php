<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../patient-portal/patient-appointment.php' . ($query !== '' ? '?' . $query : ''));
exit;
