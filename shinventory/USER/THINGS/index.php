<?php 
session_start(); 
include('../../ADMIN/header.php');
include('../../ADMIN/testsidebar.php');
include('../../config/db.php');

/* ---------------- DB Setup ---------------- */
$DB = $mysqli ?? ($conn ?? null);
if (!$DB) { die("DB connection not found from ../config/db.php"); }
$DB->set_charset('utf8mb4');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------------- Check if user is logged in ---------------- */
if (!isset($_SESSION['user_id'])) {
    echo '
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: "error",
                title: "Access Denied",
                text: "Please log in first!",
                confirmButtonText: "Go to Login"
            }).then(() => {
                window.location.href = "../index.php";
            });
        });
    </script>';
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

/* ---------------- Create request logs table if it doesn't exist ---------------- */
$createTableSQL = "CREATE TABLE IF NOT EXISTS supply_requests (
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
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (item_id),
    INDEX (status)
)";

if (!$DB->query($createTableSQL)) {
    die("Error creating table: " . $DB->error);
}

/* ---------------- Get all available inventory items ---------------- */
$items = [];
$item_query = "SELECT * FROM inventory_items WHERE status = 'Active' AND quantity > 0 ORDER BY item_name ASC";
$result = $DB->query($item_query);
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

/* ---------------- Get user's request history ---------------- */
$user_requests = [];
$request_query = "SELECT * FROM supply_requests WHERE user_id = ? ORDER BY request_date DESC LIMIT 50";
$stmt = $DB->prepare($request_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$req_result = $stmt->get_result();
while ($row = $req_result->fetch_assoc()) {
    $user_requests[] = $row;
}
$stmt->close();

/* ---------------- Handle supply request ---------------- */
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'request_supply') {
        $item_id = intval($_POST['item_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Verify password
        $stmt = $DB->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user && password_verify($password, $user['password'])) {
            // Password correct, process request
            if ($item_id > 0 && $quantity > 0 && !empty($reason)) {
                // Get item details
                $stmt = $DB->prepare("SELECT * FROM inventory_items WHERE id = ? AND status = 'Active'");
                $stmt->bind_param("i", $item_id);
                $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if ($item) {
                    if ($quantity <= $item['quantity']) {
                        // Calculate what the new quantity WOULD be (for logging purposes only)
                        $quantity_before = $item['quantity'];
                        $quantity_after = $quantity_before - $quantity; // This is just for record keeping
                        
                        // Begin transaction
                        $DB->begin_transaction();
                        
                        try {
                            // REMOVED: The inventory update - NO LONGER DEDUCTING HERE
                            // $update_stmt = $DB->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
                            // $update_stmt->bind_param("ii", $quantity_after, $item_id);
                            // $update_stmt->execute();
                            
                            // Log the request with status = 'pending'
                            $log_stmt = $DB->prepare("INSERT INTO supply_requests 
                                (user_id, username, item_id, item_name, quantity_requested, quantity_before, quantity_after, reason, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                            $log_stmt->bind_param("issiiiss", 
                                $user_id, $username, $item_id, $item['item_name'], 
                                $quantity, $quantity_before, $quantity_after, $reason
                            );
                            $log_stmt->execute();
                            
                            $DB->commit();
                            
                            $message = "Request submitted successfully! Waiting for admin approval.";
                            $message_type = "success";
                            
                            // Refresh items list (quantity remains the same since we didn't deduct)
                            $items = [];
                            $result = $DB->query($item_query);
                            while ($row = $result->fetch_assoc()) {
                                $items[] = $row;
                            }
                            
                            // Refresh user requests
                            $user_requests = [];
                            $stmt = $DB->prepare($request_query);
                            $stmt->bind_param("i", $user_id);
                            $stmt->execute();
                            $req_result = $stmt->get_result();
                            while ($row = $req_result->fetch_assoc()) {
                                $user_requests[] = $row;
                            }
                            $stmt->close();
                            
                        } catch (Exception $e) {
                            $DB->rollback();
                            $message = "Error submitting request: " . $e->getMessage();
                            $message_type = "error";
                        }
                    } else {
                        $message = "Insufficient quantity available. Only " . $item['quantity'] . " " . $item['unit'] . " available.";
                        $message_type = "error";
                    }
                } else {
                    $message = "Item not found or unavailable.";
                    $message_type = "error";
                }
            } else {
                $message = "Please fill in all fields correctly.";
                $message_type = "error";
            }
        } else {
            $message = "Incorrect password. Please try again.";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SH | Request Medical Supplies</title>
<link rel="icon" type="image/png" href="../files/emblem.png?v=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    /* Copy all your existing styles here - they remain the same */
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
    
    .cards { 
        display: grid; 
        grid-template-columns: 2fr 1fr; 
        gap: 20px; 
    }
    
    @media (max-width: 768px) {
        .cards {
            grid-template-columns: 1fr;
        }
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
    
    .card-body {
        padding: 20px;
    }
    
    .user-info {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .user-avatar {
        width: 60px;
        height: 60px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
    }
    
    .user-details h2 {
        margin: 0 0 5px 0;
        font-size: 1.5rem;
    }
    
    .user-details p {
        margin: 0;
        opacity: 0.9;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
    }
    
    .form-group label i {
        color: #e62929;
        margin-right: 5px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 1rem;
        transition: border-color 0.3s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: #e62929;
        outline: none;
    }
    
    .form-group input[type="password"] {
        font-family: monospace;
    }
    
    .item-select {
        position: relative;
    }
    
    .item-select select {
        appearance: none;
        background: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") no-repeat right 15px center;
        background-size: 15px;
    }
    
    .item-info {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
        display: none;
    }
    
    .item-info.show {
        display: block;
    }
    
    .item-info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        padding-bottom: 8px;
        border-bottom: 1px dashed #dee2e6;
    }
    
    .item-info-row:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .item-info-label {
        color: #666;
        font-weight: 600;
    }
    
    .item-info-value {
        color: #e62929;
        font-weight: bold;
    }
    
    .quantity-input-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .quantity-input-group input {
        flex: 1;
    }
    
    .max-quantity {
        background: #e9ecef;
        padding: 8px 15px;
        border-radius: 6px;
        color: #495057;
        font-size: 0.9rem;
        cursor: pointer;
        transition: background 0.3s;
    }
    
    .max-quantity:hover {
        background: #dee2e6;
    }
    
    .btn { 
        background:#e62929; 
        color:#fff; 
        border:none; 
        border-radius:8px; 
        padding:12px 25px; 
        cursor:pointer; 
        transition:0.2s; 
        font-size:1rem;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        justify-content: center;
        font-weight: 600;
    }
    
    .btn:hover { 
        background: #b31616; 
    }
    
    .btn:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    
    .warning-box {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        color: #856404;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .warning-box i {
        font-size: 1.5rem;
    }
    
    .table-responsive { 
        overflow-x: auto; 
    }
    
    .requests-table { 
        width: 100%; 
        border-collapse: collapse; 
        font-size:0.9rem; 
    }
    
    .requests-table th { 
        background:#f8f9fa; 
        padding:12px; 
        font-weight:600; 
        border-bottom:2px solid #dee2e6; 
        text-align: left;
        white-space: nowrap;
    }
    
    .requests-table td { 
        padding:12px; 
        border-bottom:1px solid #f2f2f2; 
    }
    
    .requests-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-block;
    }
    
    .status-pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .status-approved {
        background: #d4edda;
        color: #155724;
    }
    
    .status-rejected {
        background: #f8d7da;
        color: #721c24;
    }
    
    .status-completed {
        background: #d1ecf1;
        color: #0c5460;
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
    
    .stock-indicator {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-right: 5px;
    }
    
    .stock-high {
        background: #28a745;
    }
    
    .stock-medium {
        background: #ffc107;
    }
    
    .stock-low {
        background: #dc3545;
    }
    
    .password-strength {
        margin-top: 5px;
        font-size: 0.85rem;
    }
    
    .reason-counter {
        text-align: right;
        font-size: 0.85rem;
        color: #999;
        margin-top: 5px;
    }
    
    /* New style for pending badge */
    .badge-info {
        background: #17a2b8;
        color: #fff;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
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
        <h1><i class="fa-solid fa-hand-holding-medical"></i> Request Medical Supplies</h1>
        <p>Request medical supplies from inventory. All requests require password verification and will be pending admin approval.</p>
    </div>

    <!-- User Info Card -->
    <div class="user-info">
        <div class="user-avatar">
            <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-details">
            <h2>Welcome, <?= h($username) ?>!</h2>
            <p><i class="fas fa-user-tag"></i> Role: <?= ucfirst(h($role)) ?> | <i class="fas fa-clock"></i> <?= date('F j, Y g:i A') ?></p>
        </div>
    </div>

    <div class="cards">
        <!-- Request Form Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clipboard-list"></i> New Supply Request</h3>
                <span class="badge-info">Pending Approval</span>
            </div>
            <div class="card-body">
                <?php if (empty($items)): ?>
                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>No items available!</strong><br>
                            There are currently no active items in inventory with sufficient stock. Please check back later.
                        </div>
                    </div>
                <?php else: ?>
                    <form method="post" id="requestForm">
                        <input type="hidden" name="action" value="request_supply">
                        
                        <div class="form-group">
                            <label><i class="fas fa-box"></i> Select Item</label>
                            <div class="item-select">
                                <select name="item_id" id="itemSelect" required>
                                    <option value="">-- Choose an item --</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= $item['id'] ?>" 
                                                data-quantity="<?= $item['quantity'] ?>"
                                                data-unit="<?= h($item['unit']) ?>"
                                                data-location="<?= h($item['location']) ?>"
                                                data-expiry="<?= !empty($item['expiry_date']) ? date('M d, Y', strtotime($item['expiry_date'])) : 'N/A' ?>">
                                            <?= h($item['item_name']) ?> (<?= $item['quantity'] ?> <?= h($item['unit']) ?> available)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Item Info Display -->
                        <div id="itemInfo" class="item-info">
                            <div class="item-info-row">
                                <span class="item-info-label"><i class="fas fa-boxes"></i> Available Stock:</span>
                                <span class="item-info-value" id="availableStock">-</span>
                            </div>
                            <div class="item-info-row">
                                <span class="item-info-label"><i class="fas fa-map-marker-alt"></i> Location:</span>
                                <span class="item-info-value" id="itemLocation">-</span>
                            </div>
                            <div class="item-info-row">
                                <span class="item-info-label"><i class="fas fa-calendar-alt"></i> Expiry:</span>
                                <span class="item-info-value" id="itemExpiry">-</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-sort-amount-up"></i> Quantity Needed</label>
                            <div class="quantity-input-group">
                                <input type="number" name="quantity" id="quantity" min="1" required placeholder="Enter quantity">
                                <div class="max-quantity" onclick="setMaxQuantity()" title="Request maximum available">
                                    <i class="fas fa-arrow-up"></i> Max
                                </div>
                            </div>
                            <small id="quantityHelp" style="color: #666;"></small>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-comment"></i> Reason for Request</label>
                            <textarea name="reason" id="reason" rows="3" required placeholder="Please provide a detailed reason for this request..."></textarea>
                            <div class="reason-counter">
                                <span id="charCount">0</span>/500 characters
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Confirm Password</label>
                            <input type="password" name="password" id="password" required placeholder="Enter your password to confirm">
                            <div class="password-strength" id="passwordHelp">
                                <i class="fas fa-info-circle"></i> Your password is required for security verification.
                            </div>
                        </div>
                        
                        <div class="warning-box">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Important Notice:</strong> Your request will be pending admin approval. 
                                Inventory will only be deducted upon approval.
                            </div>
                        </div>
                        
                        <button type="submit" class="btn" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Submit Request for Approval
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Request History Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Your Request History</h3>
                <span class="badge-info">Last 50 Requests</span>
            </div>
            <div class="card-body">
                <?php if (empty($user_requests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard"></i>
                        <h4>No requests yet</h4>
                        <p>Your request history will appear here once you make your first request.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_requests as $request): ?>
                                    <tr>
                                        <td><?= date('M d, Y g:i A', strtotime($request['request_date'])) ?></td>
                                        <td>
                                            <strong><?= h($request['item_name']) ?></strong>
                                            <br>
                                            <small>Requested: <?= $request['quantity_requested'] ?></small>
                                        </td>
                                        <td>
                                            <span style="font-weight: bold; color: #e62929;">
                                                <?= $request['quantity_requested'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch($request['status']) {
                                                case 'pending':
                                                    $status_class = 'status-pending';
                                                    break;
                                                case 'approved':
                                                    $status_class = 'status-approved';
                                                    break;
                                                case 'rejected':
                                                    $status_class = 'status-rejected';
                                                    break;
                                                case 'completed':
                                                    $status_class = 'status-completed';
                                                    break;
                                            }
                                            ?>
                                            <span class="status-badge <?= $status_class ?>">
                                                <?= ucfirst($request['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span title="<?= h($request['reason']) ?>">
                                                <?= strlen($request['reason']) > 30 ? substr(h($request['reason']), 0, 30) . '...' : h($request['reason']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats Card -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-pie"></i> Quick Statistics</h3>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                <div style="text-align: center;">
                    <div style="font-size: 2rem; font-weight: bold; color: #e62929;"><?= count($user_requests) ?></div>
                    <div style="color: #666;">Total Requests</div>
                </div>
                <div style="text-align: center;">
                    <?php 
                    $pending_count = 0;
                    $approved_count = 0;
                    $rejected_count = 0;
                    foreach ($user_requests as $req) {
                        if ($req['status'] == 'pending') $pending_count++;
                        if ($req['status'] == 'approved') $approved_count++;
                        if ($req['status'] == 'rejected') $rejected_count++;
                    }
                    ?>
                    <div style="font-size: 2rem; font-weight: bold; color: #ffc107;"><?= $pending_count ?></div>
                    <div style="color: #666;">Pending</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 2rem; font-weight: bold; color: #28a745;"><?= $approved_count ?></div>
                    <div style="color: #666;">Approved</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 2rem; font-weight: bold; color: #dc3545;"><?= $rejected_count ?></div>
                    <div style="color: #666;">Rejected</div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Show message if any
<?php if (!empty($message)): ?>
    Swal.fire({
        icon: '<?= $message_type ?>',
        title: '<?= $message_type == "success" ? "Success!" : "Error!" ?>',
        text: '<?= h($message) ?>',
        timer: <?= $message_type == "success" ? "3000" : "3000" ?>,
        showConfirmButton: false
    });
<?php endif; ?>

// Item selection handler
document.getElementById('itemSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const itemInfo = document.getElementById('itemInfo');
    const quantityInput = document.getElementById('quantity');
    
    if (this.value) {
        const quantity = selected.dataset.quantity;
        const unit = selected.dataset.unit;
        const location = selected.dataset.location;
        const expiry = selected.dataset.expiry;
        
        document.getElementById('availableStock').innerHTML = quantity + ' ' + unit;
        document.getElementById('itemLocation').innerHTML = location || 'Not specified';
        document.getElementById('itemExpiry').innerHTML = expiry;
        
        itemInfo.classList.add('show');
        quantityInput.max = quantity;
        document.getElementById('quantityHelp').innerHTML = 'Maximum available: ' + quantity + ' ' + unit;
        
        // Stock indicator color
        if (quantity <= 10) {
            document.getElementById('availableStock').style.color = '#dc3545';
        } else if (quantity <= 50) {
            document.getElementById('availableStock').style.color = '#ffc107';
        } else {
            document.getElementById('availableStock').style.color = '#28a745';
        }
    } else {
        itemInfo.classList.remove('show');
        quantityInput.max = '';
        document.getElementById('quantityHelp').innerHTML = '';
    }
});

// Set max quantity
function setMaxQuantity() {
    const select = document.getElementById('itemSelect');
    if (select.value) {
        const max = select.options[select.selectedIndex].dataset.quantity;
        document.getElementById('quantity').value = max;
    }
}

// Character counter for reason
document.getElementById('reason').addEventListener('input', function() {
    const count = this.value.length;
    document.getElementById('charCount').textContent = count;
    
    if (count > 500) {
        this.value = this.value.substring(0, 500);
        document.getElementById('charCount').textContent = 500;
    }
});

// Form validation
document.getElementById('requestForm').addEventListener('submit', function(e) {
    const quantity = parseInt(document.getElementById('quantity').value);
    const max = parseInt(document.getElementById('itemSelect').options[document.getElementById('itemSelect').selectedIndex]?.dataset.quantity || 0);
    const reason = document.getElementById('reason').value.trim();
    const password = document.getElementById('password').value;
    
    if (!document.getElementById('itemSelect').value) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'No Item Selected',
            text: 'Please select an item to request.'
        });
        return;
    }
    
    if (quantity > max) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Invalid Quantity',
            text: 'Requested quantity exceeds available stock.'
        });
        return;
    }
    
    if (reason.length < 10) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Reason Too Short',
            text: 'Please provide a more detailed reason (at least 10 characters).'
        });
        return;
    }
    
    if (password.length < 4) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Password Required',
            text: 'Please enter your password to confirm the request.'
        });
        return;
    }
});

// Double-click confirmation for submit button
document.getElementById('submitBtn').addEventListener('dblclick', function(e) {
    this.disabled = true;
    setTimeout(() => {
        this.disabled = false;
    }, 3000);
});
</script>

</main>
</body>
</html>