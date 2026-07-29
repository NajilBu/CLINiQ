<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../patient-portal/passport-demo.php' . ($query !== '' ? '?' . $query : ''));
exit;
