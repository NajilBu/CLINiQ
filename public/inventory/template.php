<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$filename = 'cliniq-inventory-import-template.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'wb');
fputcsv($output, ['Action', 'Inventory Type', 'Item ID or Item Name', 'Description', 'Unit', 'Quantity', 'Reorder Level', 'Expiration Date']);
fputcsv($output, ['ADD', 'Medicine', '', 'Optional description', 'pcs', '0', '10', '2027-12-31']);
fputcsv($output, ['ADD', 'Equipment', '', 'Optional description', 'unit', '0', '1', '']);
fputcsv($output, ['RESTOCK', 'Medicine', 'Paracetamol 500mg', '', '', '25', '', '2027-12-31']);
fputcsv($output, ['RESTOCK', 'Equipment', 'Pulse Oximeter', '', '', '5', '', '']);
fclose($output);
exit;
