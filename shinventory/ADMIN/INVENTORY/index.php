<?php
session_start();
include('../testsidebar.php');
include('../../config/db.php');

// Restriction check
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
                window.location.href = "/servinghearts/shinventory/index.php";
            });
        });
    </script>';
    exit();
}

// Check for success/error messages from process_request.php
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Calculate statistics for the cards
$pendingCountSql = "SELECT COUNT(*) as count FROM blood_transactions t 
                    JOIN blood_inventory bi ON t.inventory_id = bi.inventory_id 
                    WHERE t.transaction_type = 'out' AND bi.status = 'pending'";
$pendingResult = $conn->query($pendingCountSql);
$pendingCount = $pendingResult->fetch_assoc()['count'];

$expiringSoonSql = "SELECT COUNT(*) as count FROM blood_transactions t 
                    JOIN blood_inventory bi ON t.inventory_id = bi.inventory_id 
                    WHERE t.transaction_type = 'out' AND bi.status = 'pending' 
                    AND bi.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
$expiringResult = $conn->query($expiringSoonSql);
$expiringCount = $expiringResult->fetch_assoc()['count'];

$monthlyTotalSql = "SELECT COUNT(*) as count FROM blood_transactions 
                    WHERE transaction_type = 'out' 
                    AND MONTH(created_at) = MONTH(CURDATE()) 
                    AND YEAR(created_at) = YEAR(CURDATE())";
$monthlyResult = $conn->query($monthlyTotalSql);
$monthlyCount = $monthlyResult->fetch_assoc()['count'];

$approvalRateSql = "SELECT 
                    (SELECT COUNT(*) FROM blood_transactions WHERE transaction_type = 'approved_out') / 
                    GREATEST((SELECT COUNT(*) FROM blood_transactions WHERE transaction_type IN ('approved_out', 'rejected_out')), 1) * 100 as rate";
$approvalResult = $conn->query($approvalRateSql);
$approvalRate = round($approvalResult->fetch_assoc()['rate'], 0);

// Handle filtering and sorting
$bloodTypeFilter = isset($_GET['blood_type']) ? $_GET['blood_type'] : '';
$componentFilter = isset($_GET['component']) ? $_GET['component'] : '';
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'expiration_date';
$sortOrder = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

// Build the query with filters and sorting
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
WHERE t.transaction_type = 'out' AND bi.status = 'pending'
";

// Add filters if set
if (!empty($bloodTypeFilter)) {
    $sql .= " AND bi.blood_type = '" . $conn->real_escape_string($bloodTypeFilter) . "'";
}

if (!empty($componentFilter)) {
    $sql .= " AND bi.component = '" . $conn->real_escape_string($componentFilter) . "'";
}

// Add sorting
$validSortColumns = ['expiration_date', 'collection_date', 'blood_type', 'component'];
$validSortOrders = ['ASC', 'DESC'];

if (in_array($sortBy, $validSortColumns) && in_array($sortOrder, $validSortOrders)) {
    $sql .= " ORDER BY " . $sortBy . " " . $sortOrder;
} else {
    $sql .= " ORDER BY expiration_date ASC";
}

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SH | Blood Request Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    html, body {
        background-color: #ffffff;
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        overflow-y: scroll;  /* always show vertical scrollbar */
    }

    :root {
        --primary: #e53935;
        --primary-light: #ffcdd2;
        --primary-dark: #b71c1c;
        --secondary: #455a64;
        --light: #f5f5f5;
        --grey: #eeeeee;
        --dark: #263238;
        --success: #4caf50;
        --warning: #ff9800;
        --danger: #f44336;
        --white: #ffffff;
        --info: #007bff;
    }

    .main-content {
        margin-left: 250px;
        margin-top: 70px;
        padding: 30px;
        background: #fff;
        min-height: calc(100vh - 70px);
        width: calc(100% - 280px);
        transition: all 0.3s ease;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--primary-light);
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-title {
        color: var(--primary-dark);
        margin: 0;
        font-size: 24px;
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: var(--white);
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.1);
    }

    .stat-card.primary { border-top: 4px solid var(--primary); }
    .stat-card.warning { border-top: 4px solid var(--warning); }
    .stat-card.total { border-top: 4px solid var(--success); }
    .stat-card.approved { border-top: 4px solid var(--info); }

    .stat-number {
        font-size: 28px;
        font-weight: bold;
        color: var(--primary-dark);
        margin: 10px 0;
    }

    .stat-label {
        color: var(--secondary);
        font-size: 14px;
    }

    .requests-table-container {
        background: var(--white);
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        overflow: auto;
    }

    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .table-title {
        color: var(--primary-dark);
        margin: 0;
    }

    .filter-toolbar {
        display: flex;
        justify-content: flex-end;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
        padding: 8px 0;
    }

    .filter-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .filter-group label {
        color: var(--primary-dark);
        font-size: 14px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .filter-toolbar select {
        padding: 6px 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: var(--white);
        font-size: 14px;
        min-width: 120px;
    }

    .filter-toolbar .sort-btn {
        background: var(--primary);
        color: var(--white);
        border: none;
        padding: 6px 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .filter-toolbar .sort-btn:hover { 
        background: var(--primary-dark); 
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px; /* Ensure table has minimum width */
    }

    table th {
        background-color: #e62929;
        color: #fff;
        font-weight: 600;
        padding: 12px 15px;
        text-align: left;
        white-space: nowrap;
    }

    table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--grey);
    }

    table tr:hover { 
        background-color: rgba(229, 57, 53, 0.05); 
    }

    .status-badge {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: inline-block;
    }

    .status-pending {
        background-color: rgba(255, 152, 0, 0.2);
        color: var(--warning);
    }

    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        font-size: 14px;
        text-decoration: none;
    }

    .btn-sm { 
        padding: 6px 12px; 
        font-size: 14px; 
    }
    
    .btn-primary { 
        background-color: var(--primary); 
        color: var(--white); 
    }
    
    .btn-primary:hover { 
        background-color: var(--primary-dark); 
    }
    
    .btn-success { 
        background-color: var(--success); 
        color: var(--white); 
    }
    
    .btn-success:hover { 
        background-color: #3d8b40; 
    }
    
    .btn-danger { 
        background-color: var(--danger); 
        color: var(--white); 
    }
    
    .btn-danger:hover { 
        background-color: #d32f2f; 
    }
    
    .btn-outline { 
        background-color: transparent; 
        border: 2px solid var(--primary); 
        color: var(--primary); 
    }
    
    .btn-outline:hover { 
        background-color: var(--primary); 
        color: var(--white); 
    }

    .action-buttons {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0; 
        top: 0;
        width: 100%; 
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5);
    }

    .modal-content {
        background-color: var(--white);
        margin: 5% auto;
        border-radius: 10px;
        width: 90%;
        max-width: 600px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        animation: modalopen 0.4s;
    }

    @keyframes modalopen {
        from { 
            opacity: 0; 
            transform: translateY(-60px); 
        }
        to { 
            opacity: 1; 
            transform: translateY(0); 
        }
    }

    .modal-header {
        padding: 15px 20px;
        background: var(--primary);
        color: var(--white);
        border-top-left-radius: 10px;
        border-top-right-radius: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 { 
        margin: 0; 
        font-size: 20px; 
    }
    
    .close { 
        color: var(--white); 
        font-size: 28px; 
        font-weight: bold; 
        cursor: pointer; 
        transition: color 0.3s ease;
    }
    
    .close:hover { 
        color: var(--primary-light); 
    }

    .modal-body { 
        padding: 20px; 
    }

    .patient-details {
        background: #f9f9f9;
        padding: 15px;
        border-radius: 6px;
        margin: 10px 0;
        border-left: 4px solid var(--primary);
    }

    .patient-details p {
        margin: 8px 0;
        display: flex;
        flex-wrap: wrap;
    }

    .patient-details strong {
        min-width: 150px;
        display: inline-block;
        color: var(--secondary);
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--secondary);
    }

    .empty-state i {
        font-size: 48px;
        color: var(--primary-light);
        margin-bottom: 15px;
    }

    .empty-state p { 
        margin: 0; 
        font-size: 16px; 
    }

    /* ===== RESPONSIVE DESIGN ===== */

    /* Large screens (1200px and up) */
    @media (min-width: 1200px) {
        .main-content {
            margin-left: 250px;
            width: calc(100% - 280px);
        }
    }

    /* Medium screens (992px to 1199px) */
    @media (max-width: 1199px) {
        .main-content {
            margin-left: 220px;
            width: calc(100% - 240px);
            padding: 25px;
        }
        
        .stats-cards {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }
        
        .stat-number {
            font-size: 24px;
        }
    }

    /* Small screens (768px to 991px) */
    @media (max-width: 991px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 20px;
            margin-top: 60px;
        }
        
        .stats-cards {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .table-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .filter-toolbar {
            justify-content: flex-start;
            width: 100%;
        }
        
        .modal-content {
            width: 95%;
            margin: 10% auto;
        }
        
        table th,
        table td {
            padding: 10px 12px;
        }
    }

    /* Extra small screens (576px to 767px) */
    @media (max-width: 767px) {
        .main-content {
            padding: 15px;
            margin-top: 60px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .page-title {
            font-size: 20px;
        }
        
        .stats-cards {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .stat-card {
            padding: 15px;
        }
        
        .stat-number {
            font-size: 22px;
        }
        
        .requests-table-container {
            padding: 15px;
        }
        
        .filter-toolbar {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
        }
        
        .filter-group {
            width: 100%;
            justify-content: space-between;
        }
        
        .filter-toolbar select {
            min-width: 140px;
        }
        
        .action-buttons {
            flex-direction: column;
            width: 100%;
        }
        
        .action-buttons .btn {
            width: 100%;
            justify-content: center;
        }
        
        .patient-details p {
            flex-direction: column;
            gap: 5px;
        }
        
        .patient-details strong {
            min-width: auto;
            margin-bottom: 2px;
        }
        
        .modal-content {
            width: 98%;
            margin: 5% auto;
        }
        
        .modal-body {
            padding: 15px;
        }
    }

    /* Mobile phones (575px and below) */
    @media (max-width: 575px) {
        .main-content {
            padding: 10px;
            margin-top: 50px;
        }
        
        .page-title {
            font-size: 18px;
        }
        
        .stat-card {
            padding: 12px;
        }
        
        .stat-number {
            font-size: 20px;
        }
        
        .stat-label {
            font-size: 12px;
        }
        
        .requests-table-container {
            padding: 10px;
            border-radius: 8px;
        }
        
        .table-title {
            font-size: 16px;
        }
        
        table th,
        table td {
            padding: 8px 10px;
            font-size: 14px;
        }
        
        .btn {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .modal-header {
            padding: 12px 15px;
        }
        
        .modal-header h3 {
            font-size: 18px;
        }
        
        .close {
            font-size: 24px;
        }
        
        .empty-state {
            padding: 30px 15px;
        }
        
        .empty-state i {
            font-size: 36px;
        }
        
        .empty-state p {
            font-size: 14px;
        }
    }

    /* Very small screens (400px and below) */
    @media (max-width: 400px) {
        .main-content {
            padding: 8px;
        }
        
        .stats-cards {
            gap: 10px;
        }
        
        .stat-card {
            padding: 10px;
        }
        
        .filter-group {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
        
        .filter-toolbar select {
            width: 100%;
        }
        
        .status-badge {
            font-size: 11px;
            padding: 4px 8px;
        }
    }

    /* Print styles */
    @media print {
        .main-content {
            margin: 0;
            width: 100%;
            padding: 0;
        }
        
        .btn, .filter-toolbar, .page-header {
            display: none !important;
        }
        
        .stats-cards {
            display: none;
        }
        
        .requests-table-container {
            box-shadow: none;
            padding: 0;
        }
        
        table {
            min-width: auto;
        }
        
        table tr {
            break-inside: avoid;
        }
    }

    /* High DPI screens */
    @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
        .stat-card,
        .requests-table-container {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    }

    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        /* This would need additional dark mode variables */
    }
</style>
</head>
<body>
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Blood Request Management</h1>
            <button class="btn btn-outline" onclick="exportReport()">
                <i class="fas fa-download"></i> Export Report
            </button>
        </div>

        <!-- SweetAlert message -->
        <?php if (!empty($message)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '<?php echo $message_type; ?>',
                    title: '<?php echo ucfirst($message_type); ?>',
                    text: '<?php echo $message; ?>',
                    confirmButtonText: 'OK'
                });
            });
        </script>
        <?php endif; ?>

        <!-- Stats cards -->
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-number"><?php echo $pendingCount; ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $expiringCount; ?></div>
                <div class="stat-label">Expiring Soon</div>
            </div>
            <div class="stat-card total">
                <div class="stat-number"><?php echo $monthlyCount; ?></div>
                <div class="stat-label">Total This Month</div>
            </div>
            <div class="stat-card approved">
                <div class="stat-number"><?php echo $approvalRate; ?>%</div>
                <div class="stat-label">Approval Rate</div>
            </div>
        </div>

        <!-- Table -->
        <div class="requests-table-container">
            <div class="table-header">
                <h2 class="table-title">Pending Blood Requests</h2>
                <form method="GET" action="" id="filterForm" class="filter-toolbar">
                    <div class="filter-group">
                        <label><i class="fas fa-tint"></i></label>
                        <select name="blood_type" onchange="this.form.submit()">
                            <option value="">All Blood Types</option>
                            <option value="O+" <?php echo ($bloodTypeFilter == 'O+') ? 'selected' : ''; ?>>O+</option>
                            <option value="O-" <?php echo ($bloodTypeFilter == 'O-') ? 'selected' : ''; ?>>O-</option>
                            <option value="A+" <?php echo ($bloodTypeFilter == 'A+') ? 'selected' : ''; ?>>A+</option>
                            <option value="A-" <?php echo ($bloodTypeFilter == 'A-') ? 'selected' : ''; ?>>A-</option>
                            <option value="B+" <?php echo ($bloodTypeFilter == 'B+') ? 'selected' : ''; ?>>B+</option>
                            <option value="B-" <?php echo ($bloodTypeFilter == 'B-') ? 'selected' : ''; ?>>B-</option>
                            <option value="AB+" <?php echo ($bloodTypeFilter == 'AB+') ? 'selected' : ''; ?>>AB+</option>
                            <option value="AB-" <?php echo ($bloodTypeFilter == 'AB-') ? 'selected' : ''; ?>>AB-</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-vials"></i></label>
                        <select name="component" onchange="this.form.submit()">
                            <option value="">All Components</option>
                            <option value="Whole Blood" <?php echo ($componentFilter == 'Whole Blood') ? 'selected' : ''; ?>>Whole Blood</option>
                            <option value="RBC" <?php echo ($componentFilter == 'RBC') ? 'selected' : ''; ?>>RBC</option>
                            <option value="Plasma" <?php echo ($componentFilter == 'Plasma') ? 'selected' : ''; ?>>Plasma</option>
                            <option value="Platelets" <?php echo ($componentFilter == 'Platelets') ? 'selected' : ''; ?>>Platelets</option>
                            <option value="Cryoprecipitate" <?php echo ($componentFilter == 'Cryoprecipitate') ? 'selected' : ''; ?>>Cryoprecipitate</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-sort"></i> Sort by</label>
                        <select name="sort_by" onchange="this.form.submit()">
                            <option value="expiration_date" <?php echo ($sortBy == 'expiration_date') ? 'selected' : ''; ?>>Expiration Date</option>
                            <option value="collection_date" <?php echo ($sortBy == 'collection_date') ? 'selected' : ''; ?>>Collection Date</option>
                            <option value="blood_type" <?php echo ($sortBy == 'blood_type') ? 'selected' : ''; ?>>Blood Type</option>
                            <option value="component" <?php echo ($sortBy == 'component') ? 'selected' : ''; ?>>Component</option>
                        </select>
                        <button type="submit" name="sort_order" value="<?php echo $sortOrder == 'ASC' ? 'DESC' : 'ASC'; ?>" class="sort-btn">
                            <i class="fas fa-sort-<?php echo strtolower($sortOrder) == 'asc' ? 'amount-down-alt' : 'amount-up-alt'; ?>"></i>
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($result && $result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Donor</th>
                        <th>Blood Type</th>
                        <th>Component</th>
                        <th>Expiration</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>#<?= htmlspecialchars($row['transaction_id']); ?></td>
                        <td><?= htmlspecialchars($row['donor_name']); ?></td>
                        <td><strong><?= htmlspecialchars($row['blood_type']); ?></strong></td>
                        <td><?= htmlspecialchars($row['component']); ?></td>
                        <td><?= date('M j, Y', strtotime($row['expiration_date'])); ?></td>
                        <td><span class="status-badge status-pending">Pending Review</span></td>
                        <td>
                            <div class="action-buttons">
                                <form method="POST" action="process_request.php" class="approve-form">
                                    <input type="hidden" name="transaction_id" value="<?= $row['transaction_id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>

                                <form method="POST" action="process_request.php" class="reject-form">
                                    <input type="hidden" name="transaction_id" value="<?= $row['transaction_id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </form>

                                <button class="btn btn-sm btn-primary" onclick="showPatientDetails(
                                    '<?= htmlspecialchars($row['patient_name']); ?>',
                                    '<?= htmlspecialchars($row['hospital']); ?>',
                                    '<?= htmlspecialchars($row['notes']); ?>',
                                    '<?= htmlspecialchars($row['blood_type']); ?>',
                                    '<?= htmlspecialchars($row['component']); ?>',
                                    '<?= htmlspecialchars($row['collection_date']); ?>',
                                    '<?= htmlspecialchars($row['expiration_date']); ?>',
                                    '<?= $row['transaction_id']; ?>'
                                )">
                                    <i class="fas fa-info-circle"></i> Details
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>No pending blood requests at this time.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Patient Details Modal -->
    <div id="patientModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Request Details</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <h4>Patient Information</h4>
                <div class="patient-details">
                    <p><strong>Patient Name:</strong> <span id="detail-patient-name"></span></p>
                    <p><strong>Hospital/Department:</strong> <span id="detail-hospital"></span></p>
                    <p><strong>Additional Notes:</strong> <span id="detail-notes"></span></p>
                </div>
                
                <h4>Blood Information</h4>
                <div class="patient-details">
                    <p><strong>Blood Type:</strong> <span id="detail-blood-type"></span></p>
                    <p><strong>Component:</strong> <span id="detail-component"></span></p>
                    <p><strong>Collection Date:</strong> <span id="detail-collection-date"></span></p>
                    <p><strong>Expiration Date:</strong> <span id="detail-expiration-date"></span></p>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <form method="POST" action="process_request.php" style="flex: 1;" id="modal-approve-form">
                        <input type="hidden" name="transaction_id" id="modal-transaction-id">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success" style="width: 100%;">
                            <i class="fas fa-check"></i> Approve Request
                        </button>
                    </form>
                    
                    <form method="POST" action="process_request.php" style="flex: 1;" id="modal-reject-form">
                        <input type="hidden" name="transaction_id" id="modal-transaction-id-reject">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger" style="width: 100%;">
                            <i class="fas fa-times"></i> Reject Request
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function exportReport() {
            // Simply submit the form to export_requests.php
            const form = document.getElementById('filterForm');
            form.action = 'export_requests.php';
            form.submit();
            
            // Reset the form action back to current page after submission
            setTimeout(() => {
                form.action = '';
            }, 100);
        }
    </script>
    
    <script>
        function showPatientDetails(patientName, hospital, notes, bloodType, component, collectionDate, expirationDate, transactionId) {
            document.getElementById('detail-patient-name').textContent = patientName || 'Not specified';
            document.getElementById('detail-hospital').textContent = hospital || 'Not specified';
            document.getElementById('detail-notes').textContent = notes || 'No additional notes';
            
            document.getElementById('detail-blood-type').textContent = bloodType;
            document.getElementById('detail-component').textContent = component;
            document.getElementById('detail-collection-date').textContent = formatDate(collectionDate);
            document.getElementById('detail-expiration-date').textContent = formatDate(expirationDate);
            
            // Set the transaction ID for the modal forms
            document.getElementById('modal-transaction-id').value = transactionId;
            document.getElementById('modal-transaction-id-reject').value = transactionId;
            
            document.getElementById('patientModal').style.display = 'block';
        }
        
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric'
            });
        }
        
        function closeModal() {
            document.getElementById('patientModal').style.display = 'none';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('patientModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Add confirmation for approve and reject actions
        document.addEventListener('DOMContentLoaded', function() {
            // Handle approve forms
            document.querySelectorAll('.approve-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Approve Request',
                        text: 'Are you sure you want to approve this blood request?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, approve it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            this.submit();
                        }
                    });
                });
            });
            
            // Handle reject forms
            document.querySelectorAll('.reject-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Reject Request',
                        text: 'Are you sure you want to reject this blood request?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, reject it!',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#d33'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            this.submit();
                        }
                    });
                });
            });
            
            // Handle modal forms
            document.getElementById('modal-approve-form').addEventListener('submit', function(e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Approve Request',
                    text: 'Are you sure you want to approve this blood request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, approve it!',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
            
            document.getElementById('modal-reject-form').addEventListener('submit', function(e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Reject Request',
                    text: 'Are you sure you want to reject this blood request?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, reject it!',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#d33'
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>