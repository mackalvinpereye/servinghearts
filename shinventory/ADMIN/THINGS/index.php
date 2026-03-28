<?php 
session_start(); 
include('../testsidebar.php');
include('../../config/db.php'); 

/* ---------------- Restriction check ---------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo '
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: "error",
                title: "Restricted",
                text: "You are restricted from this section!",
                confirmButtonText: "Go Back"
            }).then(() => {
                window.location.href = "../index.php";
            });
        });
    </script>';
    exit();
}

/* ---------------- DB Setup ---------------- */
$DB = $mysqli ?? ($conn ?? null);
if (!$DB) { die("DB connection not found from ../config/db.php"); }
$DB->set_charset('utf8mb4');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------------- Create inventory table if it doesn't exist ---------------- */
$createTableSQL = "CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    quantity INT NOT NULL DEFAULT 0,
    unit VARCHAR(50),
    minimum_stock INT DEFAULT 0,
    expiry_date DATE,
    supplier VARCHAR(255),
    location VARCHAR(100),
    status ENUM('Active', 'Inactive', 'Discontinued') DEFAULT 'Active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if (!$DB->query($createTableSQL)) {
    die("Error creating table: " . $DB->error);
}

/* ---------------- Create supply_requests table if it doesn't exist ---------------- */
$createRequestsTableSQL = "CREATE TABLE IF NOT EXISTS supply_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity_requested INT NOT NULL,
    quantity_before INT NOT NULL,
    quantity_after INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    approved_by INT,
    approved_at DATETIME,
    notes TEXT,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (item_id),
    INDEX (status)
)";

if (!$DB->query($createRequestsTableSQL)) {
    die("Error creating table: " . $DB->error);
}

/* ---------------- Handle form submissions ---------------- */
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new item
        if ($_POST['action'] === 'add_item') {
            $item_name = trim($_POST['item_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $quantity = intval($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $minimum_stock = intval($_POST['minimum_stock'] ?? 0);
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $supplier = trim($_POST['supplier'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $status = $_POST['status'] ?? 'Active';
            $notes = trim($_POST['notes'] ?? '');

            if (!empty($item_name)) {
                $stmt = $DB->prepare("INSERT INTO inventory_items 
                    (item_name, category, quantity, unit, minimum_stock, expiry_date, supplier, location, status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssisssssss", 
                    $item_name, $category, $quantity, $unit, $minimum_stock, 
                    $expiry_date, $supplier, $location, $status, $notes
                );
                
                if ($stmt->execute()) {
                    $message = "Item added successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error adding item: " . $DB->error;
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Item name is required!";
                $message_type = "error";
            }
        }
        
        // Update item
        elseif ($_POST['action'] === 'update_item') {
            $id = intval($_POST['item_id'] ?? 0);
            $item_name = trim($_POST['item_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $quantity = intval($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $minimum_stock = intval($_POST['minimum_stock'] ?? 0);
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $supplier = trim($_POST['supplier'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $status = $_POST['status'] ?? 'Active';
            $notes = trim($_POST['notes'] ?? '');

            if ($id > 0 && !empty($item_name)) {
                $stmt = $DB->prepare("UPDATE inventory_items SET 
                    item_name = ?, category = ?, quantity = ?, unit = ?, minimum_stock = ?,
                    expiry_date = ?, supplier = ?, location = ?, status = ?, notes = ?
                    WHERE id = ?");
                $stmt->bind_param("ssisssssssi", 
                    $item_name, $category, $quantity, $unit, $minimum_stock,
                    $expiry_date, $supplier, $location, $status, $notes, $id
                );
                
                if ($stmt->execute()) {
                    $message = "Item updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating item: " . $DB->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
        }
        
        // Delete item
        elseif ($_POST['action'] === 'delete_item') {
            $id = intval($_POST['item_id'] ?? 0);
            if ($id > 0) {
                $stmt = $DB->prepare("DELETE FROM inventory_items WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $message = "Item deleted successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error deleting item!";
                    $message_type = "error";
                }
                $stmt->close();
            }
        }
        
        // Adjust quantity (add/subtract)
        elseif ($_POST['action'] === 'adjust_quantity') {
            $id = intval($_POST['item_id'] ?? 0);
            $adjustment = intval($_POST['adjustment'] ?? 0);
            $adjust_type = $_POST['adjust_type'] ?? 'add';
            
            if ($id > 0 && $adjustment > 0) {
                if ($adjust_type === 'add') {
                    $stmt = $DB->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?");
                } else {
                    $stmt = $DB->prepare("UPDATE inventory_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
                }
                $stmt->bind_param("ii", $adjustment, $id);
                
                if ($stmt->execute()) {
                    $message = "Quantity adjusted successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error adjusting quantity!";
                    $message_type = "error";
                }
                $stmt->close();
            }
        }
        
        // Approve request
        elseif ($_POST['action'] === 'approve_request') {
            $request_id = intval($_POST['request_id'] ?? 0);
            $admin_id = $_SESSION['user_id'];
            
            // Get request details
            $stmt = $DB->prepare("SELECT * FROM supply_requests WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($request) {
                // Begin transaction
                $DB->begin_transaction();
                
                try {
                    // Update request status
                    $update = $DB->prepare("UPDATE supply_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $update->bind_param("ii", $admin_id, $request_id);
                    $update->execute();
                    
                    // Update inventory (subtract the quantity)
                    $inventory = $DB->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?");
                    $inventory->bind_param("ii", $request['quantity_requested'], $request['item_id']);
                    $inventory->execute();
                    
                    $DB->commit();
                    
                    $message = "Request #$request_id has been approved and inventory updated.";
                    $message_type = "success";
                } catch (Exception $e) {
                    $DB->rollback();
                    $message = "Error approving request: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }
        
        // Reject request
        elseif ($_POST['action'] === 'reject_request') {
            $request_id = intval($_POST['request_id'] ?? 0);
            $admin_id = $_SESSION['user_id'];
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            
            $stmt = $DB->prepare("UPDATE supply_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = CONCAT(IFNULL(notes,''), '\nRejection Reason: ', ?) WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("isi", $admin_id, $rejection_reason, $request_id);
            
            if ($stmt->execute()) {
                $message = "Request #$request_id has been rejected.";
                $message_type = "success";
            } else {
                $message = "Error rejecting request.";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ---------------- Get filter parameters ---------------- */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$stock_filter = isset($_GET['stock']) ? trim($_GET['stock']) : '';
$show_requests = isset($_GET['show_requests']) ? trim($_GET['show_requests']) : '';

/* ---------------- Build inventory query with filters ---------------- */
$query = "SELECT * FROM inventory_items WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (item_name LIKE ? OR category LIKE ? OR supplier LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($category_filter)) {
    $query .= " AND category = ?";
    $params[] = $category_filter;
    $types .= "s";
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($stock_filter === 'low') {
    $query .= " AND quantity <= minimum_stock AND minimum_stock > 0";
} elseif ($stock_filter === 'out') {
    $query .= " AND quantity = 0";
} elseif ($stock_filter === 'expiring') {
    $query .= " AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$query .= " ORDER BY 
    CASE 
        WHEN quantity <= minimum_stock AND minimum_stock > 0 THEN 1 
        WHEN quantity = 0 THEN 2 
        ELSE 3 
    END, 
    item_name ASC";

/* ---------------- Get all items ---------------- */
$items = [];
$stmt = $DB->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

/* ---------------- Get pending requests ---------------- */
$pending_requests = [];
$request_query = "SELECT sr.*, u.username as requester_username 
                  FROM supply_requests sr
                  JOIN users u ON sr.user_id = u.id
                  WHERE sr.status = 'pending' 
                  ORDER BY sr.request_date DESC";
$request_result = $DB->query($request_query);
while ($row = $request_result->fetch_assoc()) {
    $pending_requests[] = $row;
}

/* ---------------- Get unique categories for filter ---------------- */
$categories = [];
$cat_result = $DB->query("SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL AND category != '' ORDER BY category");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row['category'];
}

/* ---------------- Calculate inventory statistics ---------------- */
$total_items = count($items);
$total_quantity = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;
$expiring_count = 0;
$pending_count = count($pending_requests);

foreach ($items as $item) {
    $total_quantity += $item['quantity'];
    if ($item['quantity'] <= $item['minimum_stock'] && $item['minimum_stock'] > 0) {
        $low_stock_count++;
    }
    if ($item['quantity'] == 0) {
        $out_of_stock_count++;
    }
    if (!empty($item['expiry_date']) && strtotime($item['expiry_date']) <= strtotime('+30 days')) {
        $expiring_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SH | Inventory Management</title>
<link rel="icon" type="image/png" href="../files/emblem.png?v=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    body { 
        background:#fff; 
        margin:0;
        display:flex; 
        flex-direction: column;
        min-height:100vh; 
        overflow-y: scroll; 
    }
    
    .main-content { 
        margin:5px 10px 10px 10px; 
        padding:10px; 
        flex:1; 
        background:#fff; 
    }
    
    .content-container { 
        width:100%; 
        max-width:1400px; 
        margin:0 auto;
    }
    
    .overview-stats { 
        background: linear-gradient(135deg, #e62929 0%, #b31616 100%); 
        padding:20px; 
        border-radius:16px; 
        margin-bottom:15px; 
        position:relative; 
        overflow:hidden; 
        box-shadow:0 10px 30px rgba(230,41,41,0.2); 
    }
    
    .overview-stats h1 { 
        color:#fff; 
        font-size:2rem; 
        margin:0 0 10px 0;
        position: relative;
        z-index: 2;
    }
    
    .overview-stats p { 
        color:#fff; 
        font-size:1rem;
        font-weight:400; 
        max-width:500px; 
        margin:0;
        position: relative;
        z-index: 2;
    }
    
    .shape-circle, .shape-triangle, .shape-square { 
        position:absolute; 
        z-index:1; 
    }
    
    .shape-circle { 
        width:180px; 
        height:180px; 
        border-radius:50%; 
        background:rgba(255,255,255,0.15); 
        right:-40px; 
        top:-40px; 
    }
    
    .shape-triangle { 
        border-left:100px solid transparent; 
        border-right:100px solid transparent; 
        border-bottom:170px solid rgba(255,255,255,0.1); 
        transform:rotate(45deg); 
        left:-50px; 
        bottom:-80px; 
    }
    
    .shape-square { 
        width:100px; 
        height:100px; 
        background:rgba(255,255,255,0.1); 
        transform:rotate(25deg); 
        right:100px; 
        bottom:30px; 
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        border: 1px solid #f0f0f0;
        cursor: pointer;
        transition: transform 0.2s;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    }
    
    .stat-card .stat-value {
        font-size: 2rem;
        font-weight: bold;
        color: #e62929;
        margin-bottom: 5px;
    }
    
    .stat-card .stat-label {
        color: #666;
        font-size: 0.9rem;
    }
    
    .stat-card.warning {
        border-left: 4px solid #ffc107;
    }
    
    .stat-card.danger {
        border-left: 4px solid #dc3545;
    }
    
    .stat-card.info {
        border-left: 4px solid #17a2b8;
    }
    
    .stat-card.pending {
        border-left: 4px solid #ffc107;
        background: #fff3cd;
    }
    
    .stat-card.pending .stat-value {
        color: #856404;
    }
    
    .action-bar {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: center;
        justify-content: space-between;
    }
    
    .search-box {
        display: flex;
        gap: 10px;
        flex: 1;
        min-width: 300px;
    }
    
    .search-box input {
        flex: 1;
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 0.95rem;
    }
    
    .filter-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .filter-select {
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 0.95rem;
        background: #fff;
        min-width: 150px;
    }
    
    .btn { 
        background:#e62929; 
        color:#fff; 
        border:none; 
        border-radius:8px; 
        padding:5px 20px; 
        cursor:pointer; 
        transition:0.2s; 
        font-size:0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn:hover { 
        background: #b31616; 
    }
    
    .btn-secondary {
        background: #6c757d;
    }
    
    .btn-secondary:hover {
        background: #545b62;
    }
    
    .btn-success {
        background: #28a745;
    }
    
    .btn-success:hover {
        background: #218838;
    }
    
    .btn-danger {
        background: #dc3545;
    }
    
    .btn-danger:hover {
        background: #c82333;
    }
    
    .btn-warning {
        background: #ffc107;
        color: #333;
    }
    
    .btn-warning:hover {
        background: #e0a800;
    }
    
    .btn-outline {
        background: #fff;
        color: #e62929;
        border: 1px solid #e62929;
    }
    
    .btn-outline:hover {
        background: #e62929;
        color: #fff;
    }
    
    .card { 
        background: #fff; 
        border-radius: 12px; 
        overflow: hidden; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.08); 
        margin-bottom: 20px;
    }
    
    .card-header { 
        margin:0; 
        background:#FFCDD2; 
        color:#e62929; 
        padding:15px 20px; 
        font-size:1.1rem; 
        font-weight:600; 
        display:flex; 
        align-items:center; 
        justify-content: space-between;
        gap:8px; 
    }
    
    .card-header h3 {
        margin: 0;
        font-size: 1.2rem;
    }
    
    .table-responsive { 
        overflow-x: auto; 
        padding: 0;
    }
    
    .inventory-table { 
        width: 100%; 
        border-collapse: collapse; 
        font-size:0.9rem; 
    }
    
    .inventory-table th { 
        background:#f8f9fa; 
        padding:12px; 
        font-weight:600; 
        border-bottom:2px solid #dee2e6; 
        text-align: left;
        white-space: nowrap;
    }
    
    .inventory-table td { 
        padding:12px; 
        border-bottom:1px solid #f2f2f2; 
    }
    
    .inventory-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .low-stock {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .out-of-stock {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .expiring {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-block;
    }
    
    .badge-success {
        background: #d4edda;
        color: #155724;
    }
    
    .badge-warning {
        background: #fff3cd;
        color: #856404;
    }
    
    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }
    
    .badge-info {
        background: #d1ecf1;
        color: #0c5460;
    }
    
    .badge-pending {
        background: #ffc107;
        color: #333;
    }
    
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }
    
    .icon-btn {
        background: none;
        border: none;
        color: #666;
        cursor: pointer;
        padding: 5px;
        border-radius: 4px;
        transition: 0.2s;
    }
    
    .icon-btn:hover {
        background: #f0f0f0;
        color: #e62929;
    }
    
    .icon-btn.delete:hover {
        color: #dc3545;
    }
    
    .icon-btn.approve {
        background: #28a745;
        color: #fff;
    }
    
    .icon-btn.approve:hover {
        background: #218838;
    }
    
    .icon-btn.reject {
        background: #dc3545;
        color: #fff;
    }
    
    .icon-btn.reject:hover {
        background: #c82333;
    }
    
    .icon-btn.view {
        background: #17a2b8;
        color: #fff;
    }
    
    .icon-btn.view:hover {
        background: #138496;
    }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        overflow-y: auto;
    }
    
    .modal-content {
        background: #fff;
        max-width: 600px;
        margin: 50px auto;
        border-radius: 12px;
        overflow: hidden;
    }
    
    .modal-header {
        background: #e62929;
        color: #fff;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h4 {
        margin: 0;
        font-size: 1.2rem;
    }
    
    .modal-header .close {
        background: none;
        border: none;
        color: #fff;
        font-size: 1.5rem;
        cursor: pointer;
    }
    
    .modal-body {
        padding: 20px;
    }
    
    .modal-footer {
        padding: 15px 20px;
        background: #f8f9fa;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #333;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 0.95rem;
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .request-details {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .detail-row {
        display: flex;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px dashed #dee2e6;
    }
    
    .detail-row:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .detail-label {
        width: 120px;
        font-weight: 600;
        color: #666;
    }
    
    .detail-value {
        flex: 1;
        color: #333;
    }
    
    .pending-requests-bar {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .pending-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .pending-icon {
        width: 50px;
        height: 50px;
        background: #ffc107;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: #333;
    }
    
    .pending-text h4 {
        margin: 0 0 5px 0;
        color: #856404;
    }
    
    .pending-text p {
        margin: 0;
        color: #856404;
    }
    
    .requests-list {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .request-item {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        border-left: 4px solid #ffc107;
    }
    
    .request-item:hover {
        background: #f0f0f0;
    }
    
    .request-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .request-title {
        font-weight: bold;
        font-size: 1.1rem;
    }
    
    .request-meta {
        display: flex;
        gap: 15px;
        color: #666;
        font-size: 0.9rem;
    }
    
    .request-reason {
        color: #666;
        margin-bottom: 10px;
        padding: 10px;
        background: #fff;
        border-radius: 4px;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        color: #999;
    }
    
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 15px;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .action-bar {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-box {
            min-width: auto;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }
        
        .modal-content {
            margin: 20px;
        }
        
        .detail-row {
            flex-direction: column;
        }
        
        .detail-label {
            width: 100%;
            margin-bottom: 5px;
        }
        
        .pending-info {
            flex-direction: column;
            text-align: center;
        }
    }
</style>
</head>
<body>
<main class="main-content">
<div class="content-container">

    <!-- Overview Banner -->
    <div class="overview-stats">
        <div class="shape-circle"></div>
        <div class="shape-triangle"></div>
        <div class="shape-square"></div>
        <h1><i class="fa-solid fa-warehouse"></i> Inventory Management</h1>
        <p>Manage your medical supplies and track inventory levels. Add, edit, or remove items as needed.</p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card" onclick="showInventory()">
            <div class="stat-value"><?= number_format($total_items) ?></div>
            <div class="stat-label">Total Items</div>
        </div>
        <div class="stat-card" onclick="showInventory()">
            <div class="stat-value"><?= number_format($total_quantity) ?></div>
            <div class="stat-label">Total Quantity</div>
        </div>
        <div class="stat-card warning" onclick="filterStock('low')">
            <div class="stat-value"><?= number_format($low_stock_count) ?></div>
            <div class="stat-label">Low Stock Items</div>
        </div>
        <div class="stat-card danger" onclick="filterStock('out')">
            <div class="stat-value"><?= number_format($out_of_stock_count) ?></div>
            <div class="stat-label">Out of Stock</div>
        </div>
        <div class="stat-card warning" onclick="filterStock('expiring')">
            <div class="stat-value"><?= number_format($expiring_count) ?></div>
            <div class="stat-label">Expiring Soon</div>
        </div>
        <div class="stat-card pending" onclick="openRequestsModal()">
            <div class="stat-value"><?= number_format($pending_count) ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
    </div>

    <!-- Pending Requests Bar (shown if there are pending requests) -->
    <?php if ($pending_count > 0): ?>
    <div class="pending-requests-bar">
        <div class="pending-info">
            <div class="pending-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="pending-text">
                <h4><i class="fas fa-bell"></i> <?= $pending_count ?> Pending Request(s)</h4>
                <p>There are supply requests waiting for your approval</p>
            </div>
        </div>
        <button class="btn btn-warning" onclick="openRequestsModal()">
            <i class="fas fa-eye"></i> View Requests
        </button>
    </div>
    <?php endif; ?>

    <!-- Action Bar -->
    <div class="action-bar">
        <form class="search-box" method="get">
            <input type="text" name="search" placeholder="Search by item name, category, or supplier..." value="<?= h($search) ?>">
            <button type="submit" class="btn"><i class="fas fa-search"></i> Search</button>
        </form>
        
        <div class="filter-group">
            <select class="filter-select" name="category" onchange="applyFilter('category', this.value)">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat) ?>" <?= $category_filter == $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                <?php endforeach; ?>
            </select>
            
            <select class="filter-select" name="status" onchange="applyFilter('status', this.value)">
                <option value="">All Status</option>
                <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="Discontinued" <?= $status_filter == 'Discontinued' ? 'selected' : '' ?>>Discontinued</option>
            </select>
            
            <select class="filter-select" name="stock" onchange="applyFilter('stock', this.value)">
                <option value="">All Stock</option>
                <option value="low" <?= $stock_filter == 'low' ? 'selected' : '' ?>>Low Stock</option>
                <option value="out" <?= $stock_filter == 'out' ? 'selected' : '' ?>>Out of Stock</option>
                <option value="expiring" <?= $stock_filter == 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
            </select>
            
            <button class="btn" onclick="clearFilters()"><i class="fas fa-times"></i> Clear</button>
            <button class="btn btn-success" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Item</button>
        </div>
    </div>

    <!-- Inventory Table Card -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-boxes"></i> Medical Supplies Inventory</h3>
            <span>Total: <?= count($items) ?> items</span>
        </div>
        
        <div class="table-responsive">
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h4>No items found</h4>
                    <p>Click the "Add Item" button to start adding medical supplies to your inventory.</p>
                    <button class="btn" onclick="openAddModal()"><i class="fas fa-plus"></i> Add First Item</button>
                </div>
            <?php else: ?>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Min Stock</th>
                            <th>Status</th>
                            <th>Expiry Date</th>
                            <th>Location</th>
                            <th>Supplier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): 
                            $row_class = '';
                            if ($item['quantity'] == 0) {
                                $row_class = 'out-of-stock';
                            } elseif ($item['quantity'] <= $item['minimum_stock'] && $item['minimum_stock'] > 0) {
                                $row_class = 'low-stock';
                            }
                            
                            $is_expiring = !empty($item['expiry_date']) && strtotime($item['expiry_date']) <= strtotime('+30 days');
                            $expiry_class = $is_expiring ? 'expiring' : '';
                        ?>
                            <tr class="<?= $row_class ?>">
                                <td><strong><?= h($item['item_name']) ?></strong></td>
                                <td><?= h($item['category'] ?: '-') ?></td>
                                <td>
                                    <?= number_format($item['quantity']) ?>
                                    <?php if ($item['quantity'] == 0): ?>
                                        <span class="badge badge-danger">Out</span>
                                    <?php elseif ($item['quantity'] <= $item['minimum_stock'] && $item['minimum_stock'] > 0): ?>
                                        <span class="badge badge-warning">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($item['unit'] ?: '-') ?></td>
                                <td><?= number_format($item['minimum_stock']) ?></td>
                                <td>
                                    <span class="badge badge-<?= 
                                        $item['status'] == 'Active' ? 'success' : 
                                        ($item['status'] == 'Inactive' ? 'warning' : 'danger') 
                                    ?>">
                                        <?= h($item['status']) ?>
                                    </span>
                                </td>
                                <td class="<?= $expiry_class ?>">
                                    <?= !empty($item['expiry_date']) ? date('M d, Y', strtotime($item['expiry_date'])) : '-' ?>
                                    <?php if ($is_expiring): ?>
                                        <span class="badge badge-warning">Expiring</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($item['location'] ?: '-') ?></td>
                                <td><?= h($item['supplier'] ?: '-') ?></td>
                                <td class="action-buttons">
                                    <button class="icon-btn" onclick="openAdjustModal(<?= $item['id'] ?>, '<?= h($item['item_name']) ?>', <?= $item['quantity'] ?>)" title="Adjust Quantity">
                                        <i class="fas fa-balance-scale"></i>
                                    </button>
                                    <button class="icon-btn" onclick="openEditModal(<?= htmlspecialchars(json_encode($item)) ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="icon-btn delete" onclick="deleteItem(<?= $item['id'] ?>, '<?= h($item['item_name']) ?>')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-plus-circle"></i> Add New Medical Supply</h4>
            <button class="close" onclick="closeAddModal()">&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_item">
                
                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" name="item_name" required placeholder="e.g., Paracetamol 500mg">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" placeholder="e.g., Medicines">
                    </div>
                    <div class="form-group">
                        <label>Unit</label>
                        <input type="text" name="unit" placeholder="e.g., pcs, boxes, bottles">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Initial Quantity</label>
                        <input type="number" name="quantity" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Minimum Stock Level</label>
                        <input type="number" name="minimum_stock" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Discontinued">Discontinued</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Supplier</label>
                        <input type="text" name="supplier" placeholder="e.g., PharmaCorp">
                    </div>
                    <div class="form-group">
                        <label>Storage Location</label>
                        <input type="text" name="location" placeholder="e.g., Shelf A-12">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Additional information..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-edit"></i> Edit Medical Supply</h4>
            <button class="close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="post" id="editForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                
                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" name="item_name" id="edit_item_name" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" id="edit_category">
                    </div>
                    <div class="form-group">
                        <label>Unit</label>
                        <input type="text" name="unit" id="edit_unit">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" id="edit_quantity" min="0">
                    </div>
                    <div class="form-group">
                        <label>Minimum Stock Level</label>
                        <input type="number" name="minimum_stock" id="edit_minimum_stock" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry_date">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Discontinued">Discontinued</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Supplier</label>
                        <input type="text" name="supplier" id="edit_supplier">
                    </div>
                    <div class="form-group">
                        <label>Storage Location</label>
                        <input type="text" name="location" id="edit_location">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="edit_notes"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn">Update Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Quantity Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-balance-scale"></i> Adjust Quantity</h4>
            <button class="close" onclick="closeAdjustModal()">&times;</button>
        </div>
        <form method="post" id="adjustForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_quantity">
                <input type="hidden" name="item_id" id="adjust_item_id">
                
                <p id="adjust_item_name" style="font-weight: bold; margin-bottom: 15px;"></p>
                <p id="current_quantity" style="margin-bottom: 15px;"></p>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Adjustment Type</label>
                        <select name="adjust_type" id="adjust_type">
                            <option value="add">Add Quantity</option>
                            <option value="subtract">Subtract Quantity</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Adjust</label>
                        <input type="number" name="adjustment" id="adjustment_value" min="1" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" class="btn">Apply Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- Pending Requests Modal -->
<div id="requestsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-clock"></i> Pending Supply Requests</h4>
            <button class="close" onclick="closeRequestsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (empty($pending_requests)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h4>No Pending Requests</h4>
                    <p>All requests have been processed.</p>
                </div>
            <?php else: ?>
                <div class="requests-list">
                    <?php foreach ($pending_requests as $request): ?>
                        <div class="request-item" id="request-<?= $request['id'] ?>">
                            <div class="request-header">
                                <span class="request-title">
                                    <i class="fas fa-box"></i> <?= h($request['item_name']) ?>
                                </span>
                                <span class="badge badge-pending">Pending</span>
                            </div>
                            
                            <div class="request-meta">
                                <span><i class="fas fa-user"></i> <?= h($request['requester_username']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= date('M d, Y g:i A', strtotime($request['request_date'])) ?></span>
                                <span><i class="fas fa-cubes"></i> Qty: <?= $request['quantity_requested'] ?></span>
                            </div>
                            
                            <div class="request-reason">
                                <i class="fas fa-comment"></i> <?= h($request['reason']) ?>
                            </div>
                            
                            <div class="action-buttons" style="justify-content: flex-end;">
                                <button class="icon-btn view" onclick="viewRequestDetails(<?= htmlspecialchars(json_encode($request)) ?>)" title="View Details">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                                <button class="icon-btn approve" onclick="approveRequest(<?= $request['id'] ?>)" title="Approve">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="icon-btn reject" onclick="rejectRequest(<?= $request['id'] ?>)" title="Reject">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRequestsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Request Details Modal -->
<div id="requestDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-clipboard-list"></i> Request Details</h4>
            <button class="close" onclick="closeRequestDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="request-details" id="requestDetails"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRequestDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Reject Request Modal -->
<div id="rejectRequestModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-times-circle"></i> Reject Request</h4>
            <button class="close" onclick="closeRejectRequestModal()">&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="reject_request">
                <input type="hidden" name="request_id" id="reject_request_id">
                
                <p>Please provide a reason for rejection:</p>
                
                <div class="form-group">
                    <textarea name="rejection_reason" id="rejection_reason" rows="4" required placeholder="Enter rejection reason..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRejectRequestModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Approve Form (hidden) -->
<form method="post" id="approveForm" style="display: none;">
    <input type="hidden" name="action" value="approve_request">
    <input type="hidden" name="request_id" id="approve_request_id">
</form>

<script>
// Show message if any
<?php if (!empty($message)): ?>
    Swal.fire({
        icon: '<?= $message_type ?>',
        title: '<?= $message_type == "success" ? "Success!" : "Error!" ?>',
        text: '<?= h($message) ?>',
        timer: 2000,
        showConfirmButton: false
    });
<?php endif; ?>

// Modal functions
function openAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
}

function openEditModal(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_category').value = item.category || '';
    document.getElementById('edit_unit').value = item.unit || '';
    document.getElementById('edit_quantity').value = item.quantity;
    document.getElementById('edit_minimum_stock').value = item.minimum_stock;
    document.getElementById('edit_expiry_date').value = item.expiry_date || '';
    document.getElementById('edit_status').value = item.status;
    document.getElementById('edit_supplier').value = item.supplier || '';
    document.getElementById('edit_location').value = item.location || '';
    document.getElementById('edit_notes').value = item.notes || '';
    
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openAdjustModal(id, name, currentQty) {
    document.getElementById('adjust_item_id').value = id;
    document.getElementById('adjust_item_name').innerHTML = '<i class="fas fa-box"></i> ' + name;
    document.getElementById('current_quantity').innerHTML = '<strong>Current Quantity:</strong> ' + currentQty;
    document.getElementById('adjustment_value').value = '';
    document.getElementById('adjustModal').style.display = 'block';
}

function closeAdjustModal() {
    document.getElementById('adjustModal').style.display = 'none';
}

function openRequestsModal() {
    document.getElementById('requestsModal').style.display = 'block';
}

function closeRequestsModal() {
    document.getElementById('requestsModal').style.display = 'none';
}

function closeRequestDetailsModal() {
    document.getElementById('requestDetailsModal').style.display = 'none';
}

function closeRejectRequestModal() {
    document.getElementById('rejectRequestModal').style.display = 'none';
    document.getElementById('rejection_reason').value = '';
}

// Request functions
function viewRequestDetails(request) {
    const details = document.getElementById('requestDetails');
    
    details.innerHTML = `
        <div class="detail-row">
            <span class="detail-label">Request ID:</span>
            <span class="detail-value">#${request.id}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Date:</span>
            <span class="detail-value">${new Date(request.request_date).toLocaleString()}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Requester:</span>
            <span class="detail-value">${request.requester_username}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Item:</span>
            <span class="detail-value">
                <strong>${request.item_name}</strong>
            </span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Quantity:</span>
            <span class="detail-value">
                <strong style="color: #e62929;">${request.quantity_requested}</strong>
            </span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Stock Levels:</span>
            <span class="detail-value">
                Before: ${request.quantity_before} → After: ${request.quantity_after}
            </span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Reason:</span>
            <span class="detail-value">${request.reason}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Status:</span>
            <span class="detail-value">
                <span class="badge badge-pending">${request.status}</span>
            </span>
        </div>
    `;
    
    document.getElementById('requestDetailsModal').style.display = 'block';
}

function approveRequest(id) {
    Swal.fire({
        title: 'Approve Request?',
        text: 'Are you sure you want to approve this request? The inventory will be updated.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, approve it!'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('approve_request_id').value = id;
            document.getElementById('approveForm').submit();
        }
    });
}

function rejectRequest(id) {
    document.getElementById('reject_request_id').value = id;
    document.getElementById('rejectRequestModal').style.display = 'block';
}

function deleteItem(id, name) {
    Swal.fire({
        title: 'Delete Item?',
        html: `Are you sure you want to delete <strong>${name}</strong>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e62929',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function applyFilter(type, value) {
    const url = new URL(window.location.href);
    if (value) {
        url.searchParams.set(type, value);
    } else {
        url.searchParams.delete(type);
    }
    url.searchParams.delete('show_requests');
    window.location.href = url.toString();
}

function clearFilters() {
    window.location.href = window.location.pathname;
}

function filterStock(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('stock', type);
    url.searchParams.delete('show_requests');
    window.location.href = url.toString();
}

function showInventory() {
    const url = new URL(window.location.href);
    url.searchParams.delete('stock');
    url.searchParams.delete('category');
    url.searchParams.delete('status');
    url.searchParams.delete('search');
    window.location.href = url.toString();
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.style.display = 'none';
        });
    }
});

// Auto-refresh every 5 minutes to check for new requests
setTimeout(function() {
    location.reload();
}, 300000);
</script>

</main>
</body>
</html>