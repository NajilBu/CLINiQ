<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_report_access();

header('Location: index.php?view=tables&' . http_build_query(array_intersect_key($_GET, array_flip(['from', 'to', 'period', 'semester']))));
exit;
