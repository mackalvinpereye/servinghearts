<?php
ob_start();
session_start();

include('../../ADMIN/header.php');
include('../../ADMIN/testsidebar.php');
include('../../config/db.php');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header("Location: ../../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_request_id'])) {
    $delete_id = (int)$_POST['delete_request_id'];
    $conn->begin_transaction();
    try {
        // Delete items first
        $stmtItems = $conn->prepare("DELETE FROM budget_request_items WHERE request_id = ?");
        $stmtItems->bind_param("i", $delete_id);
        $stmtItems->execute();
        $stmtItems->close();

        // Delete request
        $stmtRequest = $conn->prepare("DELETE FROM budget_requests WHERE request_id = ?");
        $stmtRequest->bind_param("i", $delete_id);
        $stmtRequest->execute();
        $stmtRequest->close();

        $conn->commit();
        $_SESSION['success_message'] = "Budget request deleted successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting request: " . $e->getMessage();
    }

    ob_end_clean();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['title'])) {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $reference_code = 'BR' . time() . rand(100, 999);

    $valid = true;
    if (isset($_POST['item_name']) && is_array($_POST['item_name'])) {
        foreach ($_POST['item_name'] as $index => $itemName) {
            $quantity = (int)$_POST['quantity'][$index];
            $unitPrice = (float)$_POST['unit_price'][$index];
            if (empty($itemName) || $quantity < 1 || $unitPrice <= 0) {
                $valid = false;
                break;
            }
        }
    } else { $valid = false; }

    if ($valid) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO budget_requests (user_id, title, description, reference_code, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $stmt->bind_param("isss", $user_id, $title, $description, $reference_code);
            if ($stmt->execute()) {
                $request_id = $conn->insert_id;
                $stmt->close();
                $total_amount = 0;

                if (isset($_POST['item_name']) && is_array($_POST['item_name'])) {
                    $itemStmt = $conn->prepare("INSERT INTO budget_request_items (request_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                    foreach ($_POST['item_name'] as $index => $itemName) {
                        $quantity = (int)$_POST['quantity'][$index];
                        $unitPrice = (float)$_POST['unit_price'][$index];
                        $totalPrice = $quantity * $unitPrice;
                        $total_amount += $totalPrice;
                        $itemStmt->bind_param("isidd", $request_id, $itemName, $quantity, $unitPrice, $totalPrice);
                        $itemStmt->execute();
                    }
                    $itemStmt->close();
                }

                $updateStmt = $conn->prepare("UPDATE budget_requests SET amount = ? WHERE request_id = ?");
                $updateStmt->bind_param("di", $total_amount, $request_id);
                $updateStmt->execute();
                $updateStmt->close();

                $conn->commit();
                $_SESSION['success_message'] = "Budget request submitted successfully! Reference Code: " . $reference_code;
            } else {
                throw new Exception("Error submitting request: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Please fill in all items correctly. Quantity must be ≥1 and Unit Price must be >0.";
    }

    ob_end_clean();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch budget requests
$requests = [];
$stmt = $conn->prepare("
    SELECT br.*, 
    (SELECT COUNT(*) FROM budget_request_items bri WHERE bri.request_id = br.request_id) as item_count 
    FROM budget_requests br 
    WHERE br.user_id = ? 
    ORDER BY br.created_at DESC
");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($request = $result->fetch_assoc()) {
        $itemStmt = $conn->prepare("SELECT * FROM budget_request_items WHERE request_id = ?");
        if ($itemStmt) {
            $itemStmt->bind_param("i", $request['request_id']);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            $request['items'] = $itemResult->fetch_all(MYSQLI_ASSOC);
            $itemStmt->close();
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
<title>Serving Hearts | Budget Requests</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
html, body { background-color: #ffffff; margin: 0; padding: 0; font-family: Arial, sans-serif; }
.main-content { margin-left: 280px; margin-top: 70px; padding: 50px; background: #fff; flex: 1; min-height: calc(100vh - 70px); }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-title { color: #2c3e50; font-size: 24px; margin: 0; }
.btn { padding: 12px 20px; background: #e62929; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.3s; }
.btn:hover { background: #b92929ff; }
.btn-sm { padding: 6px 12px; font-size: 0.875rem; }
.btn-danger { background: #e74c3c; }
.btn-danger:hover { background: #c0392b; }
.btn-success { background: #2ecc71; }
.btn-success:hover { background: #27ae60; }
.requests-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
.requests-table th, .requests-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eaeaea; }
.requests-table th { background-color: #e62929; color: white; }
.requests-table tr:hover { background-color: #f1f9ff; cursor: pointer; }
.status-pending { color: #e67e22; font-weight: bold; }
.status-approved { color: #27ae60; font-weight: bold; }
.status-rejected { color: #e74c3c; font-weight: bold; }
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); }
.modal-content { background: #fff; margin: 5% auto; padding: 30px; width: 700px; border-radius: 10px; position: relative; max-height: 80vh; overflow-y: auto; }
.close { position: absolute; top: 15px; right: 20px; font-size: 28px; cursor: pointer; }
.modal-title { color: #2c3e50; margin-top: 0; margin-bottom: 20px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50; }
.form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; }
.item-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.item-table th { background-color: #2c3e50; color: white; padding: 10px; }
.item-table td { padding: 10px; border-bottom: 1px solid #eee; }
.item-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: flex-end; }
.item-row .form-group { flex: 1; margin-bottom: 0; }
.item-row .form-group:last-child { flex: 0 0 100px; }
.items-container { margin-bottom: 20px; border: 1px solid #eee; padding: 15px; border-radius: 6px; }
.items-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.total-amount { font-weight: bold; font-size: 1.2em; text-align: right; margin-top: 20px; padding-top: 10px; border-top: 2px solid #eee; }
.reference-code { font-family: monospace; background-color: #f8f9fa; padding: 5px 10px; border-radius: 4px; border: 1px dashed #ccc; }
#detailsModal .modal-content {margin: 5% auto; padding: 30px; width: 700px; border-radius: 10px; position: relative; max-height: 80vh; overflow-y: auto;}
</style>
</head>
<body>
<div class="main-content">
    <div class="page-header">
        <h2 class="page-title">User Budget Requests</h2>
        <button class="btn" onclick="openModal('new')">+ New Request</button>
    </div>

    <table class="requests-table">
        <thead>
            <tr>
                <th>Reference Code</th>
                <th>Title</th>
                <th>Items</th>
                <th>Total Amount</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($requests)): ?>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <td class="reference-code"><?= htmlspecialchars($req['reference_code'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($req['title']) ?></td>
                    <td><?= $req['item_count'] ?> item(s)</td>
                    <td>₱<?= number_format($req['amount'] ?? 0, 2) ?></td>
                    <td><span class="status-<?= $req['status'] ?>"><?= ucfirst($req['status']) ?></span></td>
                    <td><?= date('M j, Y g:i A', strtotime($req['created_at'])) ?></td>
                    <td>
                        <button class="btn btn-success btn-sm" onclick="openModal('details', <?= $req['request_id'] ?>)">Details</button>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this request?');" style="display:inline;">
                            <input type="hidden" name="delete_request_id" value="<?= $req['request_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="7">You haven't submitted any budget requests yet.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- New Budget Request Modal -->
<div id="requestModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 class="modal-title">New Budget Request</h3>
        <form method="POST" id="budgetRequestForm">
            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" required placeholder="Enter request title">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" required placeholder="Describe what the budget will be used for"></textarea>
            </div>
            <div class="items-container">
                <div class="items-header">
                    <h4>Items</h4>
                    <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()">+ Add Item</button>
                </div>
                <div id="itemsList">
                    <div class="item-row">
                        <div class="form-group">
                            <label>Item Name</label>
                            <input type="text" name="item_name[]" required placeholder="Item name">
                        </div>
                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" name="quantity[]" min="1" required onchange="calculateTotal(this)">
                        </div>
                        <div class="form-group">
                            <label>Unit Price (₱)</label>
                            <input type="number" name="unit_price[]" step="0.01" min="0.01" required onchange="calculateTotal(this)">
                        </div>
                        <div class="form-group">
                            <label>Total</label>
                            <input type="text" name="total[]" value="₱0.00" readonly class="item-total">
                        </div>
                        <div class="form-group">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeItemRow(this)" disabled style="opacity:0.5;">Remove</button>
                        </div>
                    </div>
                </div>
                <div class="total-amount">Grand Total: <span id="grandTotal">₱0.00</span></div>
            </div>
            <button type="submit" class="btn">Submit Request</button>
        </form>
    </div>
</div>

<!-- Request Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 class="modal-title">Request Details</h3>
        <div id="detailsContent"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if (isset($_SESSION['success_message'])): ?>
Swal.fire({ icon: 'success', title: 'Success!', text: '<?= addslashes($_SESSION['success_message']) ?>' });
<?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
Swal.fire({ icon: 'error', title: 'Error!', text: '<?= addslashes($_SESSION['error_message']) ?>' });
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

function openModal(type, id=null){
    if(type==='new'){
        document.getElementById('requestModal').style.display='block';
    } else if(type==='details' && id!==null){
        const requests = <?php echo json_encode($requests); ?>;
        const req = requests.find(r => r.request_id==id);
        let html = `<p><strong>Reference Code:</strong> ${req.reference_code}</p>
                    <p><strong>Title:</strong> ${req.title}</p>
                    <p><strong>Description:</strong> ${req.description}</p>
                    <h4>Items:</h4>`;
        if(req.items.length>0){
            html += `<table class="item-table">
                        <thead><tr><th>Item Name</th><th>Quantity</th><th>Unit Price</th><th>Total Price</th></tr></thead><tbody>`;
            let total = 0;
            req.items.forEach(i=>{
                html+=`<tr><td>${i.item_name}</td><td>${i.quantity}</td><td>₱${parseFloat(i.unit_price).toFixed(2)}</td><td>₱${parseFloat(i.total_price).toFixed(2)}</td></tr>`;
                total += parseFloat(i.total_price);
            });
            html += `<tr><td colspan="3" style="text-align:right"><strong>Grand Total:</strong></td><td><strong>₱${total.toFixed(2)}</strong></td></tr>`;
            html += `</tbody></table>`;
        } else { html += "<p>No items found.</p>"; }
        document.getElementById('detailsContent').innerHTML = html;
        document.getElementById('detailsModal').style.display='block';
    }
}

function closeModal(){
    document.querySelectorAll('.modal').forEach(m=>m.style.display='none');
}

// Item row functions
function addItemRow(){
    const container = document.getElementById('itemsList');
    const firstRow = container.querySelector('.item-row');
    const newRow = firstRow.cloneNode(true);
    newRow.querySelectorAll('input').forEach(input=>{
        if(input.type==='number') input.value='';
        if(input.classList.contains('item-total')) input.value='₱0.00';
    });
    newRow.querySelector('.btn-danger').disabled=false;
    newRow.querySelector('.btn-danger').style.opacity=1;
    container.appendChild(newRow);
}
function removeItemRow(btn){ btn.closest('.item-row').remove(); updateGrandTotal(); }
function calculateTotal(input){
    const row = input.closest('.item-row');
    const qty = parseFloat(row.querySelector('input[name="quantity[]"]').value) || 0;
    const price = parseFloat(row.querySelector('input[name="unit_price[]"]').value) || 0;
    row.querySelector('.item-total').value = '₱'+(qty*price).toFixed(2);
    updateGrandTotal();
}
function updateGrandTotal(){
    let total=0;
    document.querySelectorAll('.item-total').forEach(input=>{
        total += parseFloat(input.value.replace('₱','')) || 0;
    });
    document.getElementById('grandTotal').innerText='₱'+total.toFixed(2);
}

// Close modal on click outside
window.onclick = function(event){
    if(event.target.classList.contains('modal')) closeModal();
}
</script>
</body>
</html>
