<?php 
// Start output buffering at the very top to capture any potential output
ob_start();

// Start session first to prevent header issues
session_start();

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Safe output function
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// =======================
// Handle AJAX status update
// =======================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_status'])) {
    include('../../config/db.php'); 
    
    $request_id      = $_POST['request_id'];
    $status          = $_POST['status'];
    $remarks         = $_POST['remarks'] ?? '';
    $released_amount = $_POST['released_amount'] ?? 0;
    
    $response = [
        'success' => false, 
        'message' => 'An unknown error occurred.'
    ];
    
    $conn->begin_transaction();
    
    try {
        // Update budget request
        $stmt = $conn->prepare("
            UPDATE budget_requests 
            SET status = ?, remarks = ? 
            WHERE request_id = ?
        ");
        $stmt->bind_param("ssi", $status, $remarks, $request_id);
        
        if ($stmt->execute()) {
            // If approved, insert into transactions
            if ($status === 'approved') {
                $transactionStmt = $conn->prepare("
                    INSERT INTO budget_transactions 
                        (request_id, released_amount, released_date, remarks) 
                    VALUES (?, ?, NOW(), ?)
                ");
                $transactionStmt->bind_param("ids", $request_id, $released_amount, $remarks);
                $transactionStmt->execute();
                $transactionStmt->close();
            }
            
            $conn->commit();
            $response = [
                'success'         => true,
                'message'         => "Budget request status updated successfully!",
                'request_id'      => $request_id,
                'new_status'      => $status,
                'released_amount' => $released_amount
            ];
        } else {
            throw new Exception("Error updating request: " . $conn->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
    }
    
    // Clear buffer before JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// =======================
// For normal page load
// =======================
include('../testsidebar.php');
include('../../config/db.php'); 

// Fetch all budget requests
$requests = [];
$stmt = $conn->prepare("
    SELECT br.*, u.username, u.email,
           (SELECT COUNT(*) 
            FROM budget_request_items bri 
            WHERE bri.request_id = br.request_id) as item_count,
           (SELECT released_amount 
            FROM budget_transactions bt 
            WHERE bt.request_id = br.request_id 
            LIMIT 1) as released_amount
    FROM budget_requests br 
    LEFT JOIN users u ON br.user_id = u.id
    ORDER BY br.created_at DESC
");

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($request = $result->fetch_assoc()) {
        // Fetch request items
        $itemStmt = $conn->prepare("
            SELECT * 
            FROM budget_request_items 
            WHERE request_id = ?
        ");
        if ($itemStmt) {
            $itemStmt->bind_param("i", $request['request_id']);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            $request['items'] = $itemResult->fetch_all(MYSQLI_ASSOC);
            $itemStmt->close();
        }
        
        // Fetch transactions
        $transactionStmt = $conn->prepare("
            SELECT * 
            FROM budget_transactions 
            WHERE request_id = ?
        ");
        if ($transactionStmt) {
            $transactionStmt->bind_param("i", $request['request_id']);
            $transactionStmt->execute();
            $transactionResult = $transactionStmt->get_result();
            $request['transaction'] = $transactionResult->fetch_assoc();
            $transactionStmt->close();
        }
        
        $requests[] = $request;
    }
    $stmt->close();
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SH | Assets</title>
    <style>
    /* === Global === */
    html, body {
        background-color: #ffffff;
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
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

    /* === Layout === */
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

    /* === Buttons === */
    .btn {
        padding: 12px 20px;
        background: linear-gradient(135deg, #e62929 0%, #b31616 100%);
        color: #fff;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
        transition: all 0.3s;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        display: inline-block;
        text-align: center;
    }

    .btn:hover {
        background: #b92929ff;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 0.875rem;
    }

    .btn-danger {
        background: #e74c3c;
    }
    .btn-danger:hover {
        background: #c0392b;
    }

    .btn-success {
        background: #27ae60;

    }
    .btn-success:hover {
        background: #2ecc71;
    }

    .btn-info {
        background: #2c3e50;
    }
    .btn-info:hover {
        background: #82888dff;
    }

    .btn-disabled {
        background: #190fa3ff;
        cursor: not-allowed;
    }
    .btn-disabled:hover {
        background: #275ccfff;
        transform: none;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    /* === Table === */
    .requests-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-radius: 8px;
        overflow: hidden;
        min-width: 600px; /* Minimum width for table */
    }

    .requests-table th,
    .requests-table td {
        padding: 10px;
        text-align: left;
        border-bottom: 1px solid #eaeaea;
    }

    .requests-table th {
        background-color: #e62929;
        color: white;
        text-align: center;
        font-weight: 600;
    }

    .requests-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }

    .requests-table tr:hover {
        background-color: #f1f9ff;
    }

    .requests-table tr.approved-row {
        background-color: #f5f5f5;
        color: #999;
    }
    .requests-table tr.approved-row:hover {
        background-color: #e9e9e9;
    }

    .status-pending {
        color: #e67e22;
        font-weight: bold;
        background-color: #fef5e7;
        padding: 5px 10px;
        border-radius: 12px;
        font-size: 0.85em;
    }

    .status-approved {
        color: #27ae60;
        font-weight: bold;
        background-color: #eafaf1;
        padding: 5px 10px;
        border-radius: 12px;
        font-size: 0.85em;
    }

    .status-rejected {
        color: #e74c3c;
        font-weight: bold;
        background-color: #fdedec;
        padding: 5px 10px;
        border-radius: 12px;
        font-size: 0.85em;
    }

    /* === Expandable Row === */
    .expandable-row {
        cursor: pointer;
    }

    .item-details {
        display: none;
        padding: 15px;
        background-color: #f9f9f9;
        border-top: 1px solid #eee;
    }

    .item-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        min-width: 400px; /* Minimum width for item table */
    }

    .item-table th {
        background-color: #2c3e50;
        color: white;
        padding: 10px;
    }

    .item-table td {
        padding: 10px 55px;
        border-bottom: 1px solid #eee;
    }

    .item-table tr:last-child td {
        border-bottom: none;
        font-weight: bold;
        background-color: #f1f1f1;
    }

    /* === Modal === */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(3px);
    }

    .modal-content {
        background: #fff;
        margin: 5% auto;
        padding: 30px;
        width: 700px;
        border-radius: 10px;
        position: relative;
        box-shadow: 0 5px 25px rgba(0,0,0,0.2);
        animation: modalFade 0.3s;
        max-height: 90vh;
        overflow-y: auto;
        height: 500px; /* fixed height */
    }

    @keyframes modalFade {
        from { opacity: 0; transform: translateY(-50px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .close {
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 28px;
        cursor: pointer;
        color: #aaa;
        transition: color 0.3s;
    }
    .close:hover {
        color: #333;
    }

    .modal-title {
        color: #2c3e50;
        margin-top: 0;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #2c3e50;
    }
    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        box-sizing: border-box;
        font-family: inherit;
        transition: border 0.3s;
    }
    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
    }

    .form-group textarea {
        min-height: 100px;
        resize: vertical;
    }

    .no-requests {
        text-align: center;
        padding: 40px;
        color: #7f8c8d;
        font-style: italic;
        background-color: #f9f9f9;
        border-radius: 8px;
        margin-top: 20px;
    }

    .amount-cell {
        font-weight: 600;
        color: #2c3e50;
    }

    .reference-code {
        font-family: monospace;
        background-color: #f8f9fa;
        padding: 5px 10px;
        border-radius: 4px;
        border: 1px dashed #ccc;
    }

    .user-info {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 6px;
        flex-wrap: wrap;
    }
    .user-info div {
        flex: 1;
        min-width: 200px;
    }
    .user-info strong {
        display: block;
        margin-bottom: 5px;
        color: #2c3e50;
    }

    .transaction-details {
        margin-top: 20px;
        padding: 15px;
        background-color: #eafaf1;
        border-radius: 6px;
        border-left: 4px solid #27ae60;
    }
    .transaction-details h4 {
        margin-top: 0;
        color: #27ae60;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        flex-wrap: wrap;
    }

    .stats-container {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        text-align: center;
    }

    .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #e62929;
        margin: 10px 0;
    }

    .stat-label {
        color: #7f8c8d;
        font-size: 0.9em;
    }

    /* === Responsive Design === */
    
    /* Large screens (desktops) */
    @media (max-width: 1200px) {
        .main-content {
            margin-left: 200px;
            width: calc(100% - 230px);
        }
        
        .modal-content {
            width: 85%;
        }
    }
    
    /* Medium screens (tablets) */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 20px;
        }
        
        .stats-container {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .modal-content {
            width: 90%;
            padding: 20px;
        }
        
        .item-table td {
            padding: 10px 30px;
        }
    }
    
    /* Small screens (large phones) */
    @media (max-width: 768px) {
        .main-content {
            margin-top: 60px;
            padding: 15px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .stats-container {
            grid-template-columns: 1fr;
        }
        
        .requests-table {
            display: block;
            overflow-x: auto;
        }
        
        .item-table {
            display: block;
            overflow-x: auto;
        }
        
        .user-info {
            flex-direction: column;
            gap: 10px;
        }
        
        .user-info div {
            min-width: 100%;
        }
        
        .action-buttons {
            flex-direction: column;
        }
        
        .action-buttons .btn {
            width: 100%;
        }
        
        .modal-content {
            width: 95%;
            margin: 10% auto;
            padding: 15px;
        }
        
        .item-table td {
            padding: 10px 15px;
        }
    }
    
    /* Extra small screens (phones) */
    @media (max-width: 576px) {
        .main-content {
            padding: 10px;
        }
        
        .page-title {
            font-size: 20px;
        }
        
        .stat-number {
            font-size: 1.5em;
        }
        
        .stat-card {
            padding: 15px;
        }
        
        .requests-table th,
        .requests-table td {
            padding: 8px 6px;
            font-size: 0.9em;
        }
        
        .btn {
            padding: 10px 15px;
            font-size: 0.9em;
        }
        
        .modal-content {
            width: 98%;
            margin: 5% auto;
            padding: 10px;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            padding: 10px;
        }
    }
    
    /* Print styles */
    @media print {
        .btn, .action-buttons {
            display: none !important;
        }
        
        .main-content {
            margin: 0;
            width: 100%;
            padding: 0;
        }
        
        .page-header {
            border: none;
            padding-bottom: 5px;
        }
        
        .requests-table {
            box-shadow: none;
        }
    }
</style>

</head>
<body>
    <div class="main-content">
        <div class="page-header">
            <h2 class="page-title">Budget Requests Management</h2>
        </div>

        <!-- === STATS === -->
        <div class="stats-container">
            <?php
            $pending_count = 0;
            $approved_count = 0;
            $rejected_count = 0;
            $total_amount = 0;
            $released_amount = 0;

            foreach ($requests as $req) {
                if ($req['status'] === 'pending') $pending_count++;
                if ($req['status'] === 'approved') $approved_count++;
                if ($req['status'] === 'rejected') $rejected_count++;
                $total_amount += $req['amount'] ?? 0;
                $released_amount += $req['released_amount'] ?? 0;
            }
            ?>
            <div class="stat-card">
                <div class="stat-label">Pending Requests</div>
                <div class="stat-number"><?= $pending_count ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Requested</div>
                <div class="stat-number">₱<?= number_format($total_amount, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Released</div>
                <div class="stat-number">₱<?= number_format($released_amount, 2) ?></div>
            </div>
        </div>

        <!-- === REQUESTS TABLE === -->
        <table class="requests-table">
            <thead>
                <tr>
                    <th>Reference Code</th>
                    <th>Title</th>
                    <th>Requested By</th>
                    <th>Items</th>
                    <th>Requested Amount</th>
                    <th>Released Amount</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($requests)): ?>
                    <?php foreach ($requests as $req): ?>
                        <tr class="<?= $req['status'] === 'approved' ? 'approved-row' : '' ?>">
                            <td class="reference-code"><?= e($req['reference_code'] ?? 'N/A') ?></td>
                            <td><?= e($req['title']) ?></td>
                            <td><?= e($req['full_name'] ?? $req['username'] ?? 'Deleted User') ?></td>
                            <td><?= $req['item_count'] ?> item(s)</td>
                            <td class="amount-cell">₱<?= number_format($req['amount'] ?? 0, 2) ?></td>
                            <td class="amount-cell">₱<?= number_format($req['released_amount'] ?? 0, 2) ?></td>
                            <td><span class="status-<?= $req['status'] ?>"><?= ucfirst($req['status']) ?></span></td>
                            <td><?= date('M j, Y g:i A', strtotime($req['created_at'])) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-info btn-sm" onclick="openDetailsModal(<?= $req['request_id'] ?>)">View Details</button>
                                    <?php if ($req['status'] !== 'approved'): ?>
                                        <button class="btn btn-success btn-sm" onclick="openStatusModal(<?= $req['request_id'] ?>, '<?= $req['status'] ?>', <?= $req['amount'] ?? 0 ?>)">Update Status</button>
                                    <?php else: ?>
                                        <button class="btn btn-disabled btn-sm" disabled>Completed</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <tr id="details-<?= $req['request_id'] ?>" class="item-details">
                            <td colspan="9">
                                <div class="user-info">
                                    <div><strong>Requested By:</strong> <?= e($req['full_name'] ?? $req['username'] ?? 'Deleted User') ?></div>
                                    <div><strong>Contact:</strong> <?= e($req['email'] ?? 'N/A') ?></div>
                                </div>
                                <h4>Request Details: <?= e($req['title']) ?></h4>
                                <p><strong>Description:</strong> <?= e($req['description'] ?? 'No description provided') ?></p>

                                <?php if (!empty($req['items'])): ?>
                                    <table class="item-table">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Quantity</th>
                                                <th>Unit Price</th>
                                                <th>Total Price</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($req['items'] as $item): ?>
                                                <tr>
                                                    <td><?= e($item['item_name']) ?></td>
                                                    <td><?= $item['quantity'] ?></td>
                                                    <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                                                    <td>₱<?= number_format($item['total_price'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr>
                                                <td colspan="3" style="text-align: right;"><strong>Grand Total:</strong></td>
                                                <td>₱<?= number_format($req['amount'] ?? 0, 2) ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php endif; ?>

                                <?php if (!empty($req['transaction'])): ?>
                                    <div class="transaction-details">
                                        <h4>Transaction Details</h4>
                                        <p><strong>Released Amount:</strong> ₱<?= number_format($req['transaction']['released_amount'], 2) ?></p>
                                        <p><strong>Released Date:</strong> <?= date('M j, Y g:i A', strtotime($req['transaction']['released_date'])) ?></p>
                                        <p><strong>Remarks:</strong> <?= e($req['transaction']['remarks'] ?? 'N/A') ?></p>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="no-requests">No budget requests found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- === MODALS === -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeStatusModal()">&times;</span>
            <h3 class="modal-title">Update Budget Request Status</h3>
            <form id="statusForm">
                <input type="hidden" id="statusRequestId" name="request_id">
                <div class="form-group">
                    <label for="status">New Status:</label>
                    <select id="status" name="status" required>
                        <option value="approved">Approve</option>
                        <option value="rejected">Reject</option>
                        <option value="pending">Keep Pending</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="released_amount">Released Amount (₱):</label>
                    <input type="number" id="released_amount" name="released_amount" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label for="remarks">Remarks:</label>
                    <textarea id="remarks" name="remarks"></textarea>
                </div>
                <button type="submit" class="btn btn-success">Update Status</button>
            </form>
        </div>
    </div>

    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDetailsModal()">&times;</span>
            <h3 class="modal-title">Request Details</h3>
            <div id="detailsContent"></div>
        </div>
    </div>
</body>


    <!-- === JAVASCRIPT === -->
    <script>
    function toggleDetails(requestId) {
        const detailsRow = document.getElementById('details-' + requestId);
        if (detailsRow.style.display === 'table-row') {
            detailsRow.style.display = 'none';
        } else {
            detailsRow.style.display = 'table-row';
        }
    }

    function openStatusModal(requestId, currentStatus, amount) {
        document.getElementById('statusModal').style.display = 'block';
        document.getElementById('statusRequestId').value = requestId;
        document.getElementById('status').value = currentStatus;
        document.getElementById('released_amount').value = amount;
    }

    function closeStatusModal() {
        document.getElementById('statusModal').style.display = 'none';
    }

    function openDetailsModal(requestId) {
        const detailsRow = document.getElementById('details-' + requestId);
        const detailsContent = detailsRow
            ? detailsRow.innerHTML
            : "<p>No details available.</p>";

        document.getElementById('detailsContent').innerHTML = detailsContent;
        document.getElementById('detailsModal').style.display = 'block';
    }

    function closeDetailsModal() {
        document.getElementById('detailsModal').style.display = 'none';
    }

    window.onclick = function (event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = "none";
        }
    }

    document.getElementById('statusForm').onsubmit = async function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('update_status', '1');

        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert("Error: " + data.message);
            }
        } catch (err) {
            console.error(err);
            alert("An unexpected error occurred.");
        }
    }
</script>

</body>
</html>
