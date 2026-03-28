<?php
session_start();
include('../../config/db.php');

// Restriction check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied");
}

// Get ALL filter and sort parameters
$bloodTypeFilter = isset($_GET['blood_type']) ? $_GET['blood_type'] : '';
$componentFilter = isset($_GET['component']) ? $_GET['component'] : '';
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'expiration_date';
$sortOrder = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=blood_requests_export_' . date('Y-m-d_H-i-s') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8 compatibility
fputs($output, "\xEF\xBB\xBF");

// Add CSV headers
fputcsv($output, [
    'Transaction ID', 
    'Inventory ID', 
    'Blood Type', 
    'Component', 
    'Quantity (ml)', 
    'Patient Name', 
    'Hospital', 
    'Transaction Type', 
    'Status', 
    'Created At', 
    'Created By',
    'Donor Name',
    'Collection Date',
    'Expiration Date',
    'Notes'
]);

// Build the export query - Get ALL transactions, not just 'out' type
$sql = "
SELECT 
    t.transaction_id,
    t.inventory_id,
    t.quantity,
    t.transaction_type,
    t.patient_name,
    t.hospital,
    t.notes,
    t.created_at,
    t.created_by,
    bi.blood_type,
    bi.component,
    bi.collection_date,
    bi.expiration_date,
    bi.status,
    d.full_name AS donor_name
FROM blood_transactions t
JOIN blood_inventory bi ON t.inventory_id = bi.inventory_id
LEFT JOIN donors d ON bi.donor_id = d.donor_id
WHERE 1=1  -- Remove the transaction_type filter to get ALL data
";

// Add filters if set
if (!empty($bloodTypeFilter)) {
    $sql .= " AND bi.blood_type = '" . $conn->real_escape_string($bloodTypeFilter) . "'";
}

if (!empty($componentFilter)) {
    $sql .= " AND bi.component = '" . $conn->real_escape_string($componentFilter) . "'";
}

// Add sorting with validation
$validSortColumns = ['expiration_date', 'collection_date', 'blood_type', 'component', 'created_at', 'transaction_id'];
$validSortOrders = ['ASC', 'DESC'];

if (in_array($sortBy, $validSortColumns) && in_array($sortOrder, $validSortOrders)) {
    $sql .= " ORDER BY " . $sortBy . " " . $sortOrder;
} else {
    $sql .= " ORDER BY t.created_at DESC";
}

// Debug: Uncomment to see the generated SQL
// file_put_contents('debug_sql.txt', $sql);

$result = $conn->query($sql);

if (!$result) {
    // Log error and provide feedback
    error_log("Export Query Error: " . $conn->error);
    fputcsv($output, ['Error', 'Failed to generate export. Please try again.']);
} else {
    // Add data rows
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['transaction_id'],
            $row['inventory_id'],
            $row['blood_type'],
            $row['component'],
            $row['quantity'],
            $row['patient_name'] ?: 'N/A',
            $row['hospital'] ?: 'N/A',
            ucfirst(str_replace('_', ' ', $row['transaction_type'])),
            ucfirst($row['status']),
            $row['created_at'],
            $row['created_by'] ?: 'N/A',
            $row['donor_name'] ?: 'N/A',
            $row['collection_date'],
            $row['expiration_date'],
            $row['notes'] ?: 'No notes'
        ]);
    }
}

fclose($output);
exit;
?>