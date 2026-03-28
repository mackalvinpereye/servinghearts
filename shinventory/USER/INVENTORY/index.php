<?php
// Start output buffering to prevent header errors
ob_start();
session_start();

error_log("GET parameters: " . print_r($_GET, true));
error_log("POST parameters: " . print_r($_POST, true));

// Include the database configuration
require_once '../../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Clear buffer before redirect
    ob_end_clean();
    header("Location: ../../index.php");
    exit;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    // Set JSON header for AJAX responses
    header('Content-Type: application/json');
    
    // Check if donors table has status column, if not add it
    $check_column_sql = "SHOW COLUMNS FROM donors LIKE 'status'";
    $column_result = $conn->query($check_column_sql);
    if ($column_result->num_rows === 0) {
        $alter_sql = "ALTER TABLE donors ADD COLUMN status ENUM('Active', 'Inactive') DEFAULT 'Active'";
        $conn->query($alter_sql);
    }

    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? 'user';
    $action = $_GET['action'] ?? '';

    // In the 'add' action, add drive_id handling
    if ($action === 'add') {
        $donor_id = (int)$_POST['donor_id'];
        $blood_type = $_POST['blood_type'];
        $component = $_POST['component'];
        $collection_date = $_POST['collection_date'];
        // FIX: Allow NULL drive_id when empty
        $drive_id = !empty($_POST['drive_id']) ? (int)$_POST['drive_id'] : NULL;
        
        // Validate required fields
        if (empty($donor_id) || empty($blood_type) || empty($component) || empty($collection_date)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required']);
            exit;
        }
        
        // Check if the donor exists
        $check_donor_sql = "SELECT donor_id, full_name FROM donors WHERE donor_id = ?";
        $check_stmt = $conn->prepare($check_donor_sql);
        $check_stmt->bind_param("i", $donor_id);
        $check_stmt->execute();
        $donor_result = $check_stmt->get_result();
        
        if ($donor_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Donor ID ' . $donor_id . ' does not exist. Please enter a valid donor ID.']);
            exit;
        }
        
        $donor = $donor_result->fetch_assoc();
        $donor_name = $donor['full_name'];
        
        // Auto-calculate expiration date based on component type
        $expiration_date = calculate_expiration_date($collection_date, $component);
        
        // FIX: Handle NULL drive_id properly in the SQL query
        $sql = "INSERT INTO blood_inventory (donor_id, blood_type, component, collection_date, expiration_date, status, added_by, drive_id) 
                VALUES (?, ?, ?, ?, ?, 'available', ?, ?)";
        
        $stmt = $conn->prepare($sql);
        // FIX: Use correct parameter type for drive_id (i for integer, but s if it might be NULL)
        if ($drive_id === NULL) {
            $stmt->bind_param("issssii", $donor_id, $blood_type, $component, $collection_date, $expiration_date, $user_id, $drive_id);
        } else {
            $stmt->bind_param("issssii", $donor_id, $blood_type, $component, $collection_date, $expiration_date, $user_id, $drive_id);
        }
        
        if ($stmt->execute()) {
            $inventory_id = $stmt->insert_id;
            
            // Record transaction for adding blood - use donor name as patient_name and "Serving Hearts" as hospital
            $transaction_sql = "INSERT INTO blood_transactions (inventory_id, patient_name, hospital, processed_by, transaction_type, quantity, created_by) 
                                VALUES (?, ?, 'Serving Hearts', ?, 'IN', 1, ?)";
            $transaction_stmt = $conn->prepare($transaction_sql);
            $transaction_stmt->bind_param("isii", $inventory_id, $donor_name, $user_id, $user_id);
            $transaction_stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Blood added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error adding blood: ' . $conn->error]);
        }
        exit;
    }

    // FIX: Consolidated get_available_units action handler (removed duplicate)
    if ($action === 'get_available_units') {
        $blood_type = $_GET['blood_type'] ?? '';
        $component = $_GET['component'] ?? '';
        
        if (empty($blood_type) || empty($component)) {
            echo json_encode([]);
            exit;
        }
        
        // Debug: Log the received parameters
        error_log("get_available_units called with blood_type: $blood_type, component: $component");
        
        $sql = "SELECT inventory_id, donor_id, expiration_date 
                FROM blood_inventory 
                WHERE blood_type = ? AND component = ? AND status = 'available' 
                ORDER BY collection_date ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $blood_type, $component);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $units = [];
        while ($row = $result->fetch_assoc()) {
            $units[] = $row;
        }
        
        // Debug: Log the results
        error_log("Found " . count($units) . " available units");
        
        echo json_encode($units);
        exit;
    }

    // Add a new action to get blood drives
    if ($action === 'get_blood_drives') {
        $sql = "SELECT drive_id, drive_name FROM blood_drives WHERE status = 'Active' ORDER BY drive_name";
        $result = $conn->query($sql);
        
        $drives = [];
        while ($row = $result->fetch_assoc()) {
            $drives[] = $row;
        }
        
        echo json_encode($drives);
        exit;
    }

    if ($action === 'out') {
    $inventory_id = $_POST['inventory_id'];
    $patient_name = $_POST['patient_name'];
    $hospital = $_POST['hospital'];
    $request_notes = $_POST['request_notes'];
    
    // Check if user is logged in and has a valid user_id
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id === null) {
        echo json_encode(['success' => false, 'message' => 'User not logged in']);
        exit;
    }
    
    // Check if user exists in the users table
    $check_user_sql = "SELECT id FROM users WHERE id = ?";
    $check_user_stmt = $conn->prepare($check_user_sql);
    $check_user_stmt->bind_param("i", $user_id);
    $check_user_stmt->execute();
    $check_user_result = $check_user_stmt->get_result();
    
    if ($check_user_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID. User does not exist.']);
        exit;
    }
    
    // Check if this is a split request
    if (strpos($inventory_id, 'split_') === 0) {
        $original_inventory_id = str_replace('split_', '', $inventory_id);
        
        // Get the whole blood unit details
        $check_sql = "SELECT * FROM blood_inventory WHERE inventory_id = ? AND status = 'available'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $original_inventory_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Whole blood unit not found or not available']);
            exit;
        }
        
        $whole_blood = $result->fetch_assoc();
        
        // Create the new component (split from whole blood) with pending status
        $component = $_POST['request_component'];
        $expiration_date = calculate_expiration_date($whole_blood['collection_date'], $component);
        
        $insert_sql = "INSERT INTO blood_inventory (donor_id, blood_type, component, collection_date, expiration_date, status, added_by, split_from) 
                    VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssii", $whole_blood['donor_id'], $whole_blood['blood_type'], $component, 
                                $whole_blood['collection_date'], $expiration_date, $user_id, $original_inventory_id);
        
        if ($insert_stmt->execute()) {
            $new_inventory_id = $insert_stmt->insert_id;
            
            // Update the original unit status to pending
            $update_original_sql = "UPDATE blood_inventory SET status = 'pending' WHERE inventory_id = ?";
            $update_original_stmt = $conn->prepare($update_original_sql);
            $update_original_stmt->bind_param("i", $original_inventory_id);
            $update_original_stmt->execute();
            
            // Record the transaction for the original unit (NO STATUS FIELD)
            $transaction_sql = "INSERT INTO blood_transactions (inventory_id, patient_name, hospital, notes, processed_by, transaction_type, quantity, created_by) 
                                VALUES (?, ?, ?, ?, ?, 'OUT', 1, ?)";
            $transaction_stmt = $conn->prepare($transaction_sql);
            $transaction_stmt->bind_param("isssii", $original_inventory_id, $patient_name, $hospital, $request_notes, $user_id, $user_id);
            
            if ($transaction_stmt->execute()) {
                $transaction_id = $transaction_stmt->insert_id;
                
                // Create approval record for the original transaction with approved_by field
                $approval_sql = "INSERT INTO approvals (transaction_id, approval_status, approved_by) VALUES (?, 'pending', ?)";
                $approval_stmt = $conn->prepare($approval_sql);
                $approval_stmt->bind_param("ii", $transaction_id, $user_id);
                
                if ($approval_stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Blood request submitted for admin approval']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error creating approval record: ' . $conn->error]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Error creating transaction: ' . $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Error splitting blood unit: ' . $conn->error]);
        }
    } else {
        // Regular blood request
        $inventory_id = (int)$inventory_id;
        
        // Check if blood unit exists and is available
        $check_sql = "SELECT status FROM blood_inventory WHERE inventory_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $inventory_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Blood unit not found']);
            exit;
        }
        
        $row = $result->fetch_assoc();
        if ($row['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Blood unit is not available']);
            exit;
        }
        
        // Set status to pending (waiting for admin approval)
        $update_sql = "UPDATE blood_inventory SET status = 'pending' WHERE inventory_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $inventory_id);
        
        // Record the transaction (NO STATUS FIELD)
        $transaction_sql = "INSERT INTO blood_transactions (inventory_id, patient_name, hospital, notes, processed_by, transaction_type, quantity, created_by) 
                            VALUES (?, ?, ?, ?, ?, 'OUT', 1, ?)";
        $transaction_stmt = $conn->prepare($transaction_sql);
        $transaction_stmt->bind_param("isssii", $inventory_id, $patient_name, $hospital, $request_notes, $user_id, $user_id);
        
        if ($update_stmt->execute() && $transaction_stmt->execute()) {
            $transaction_id = $transaction_stmt->insert_id;
            
            // Create approval record with approved_by field
            $approval_sql = "INSERT INTO approvals (transaction_id, approval_status, approved_by) VALUES (?, 'pending', ?)";
            $approval_stmt = $conn->prepare($approval_sql);
            $approval_stmt->bind_param("ii", $transaction_id, $user_id);
            
            if ($approval_stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Blood request submitted for admin approval']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error creating approval record: ' . $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Error processing request: ' . $conn->error]);
        }
    }
    exit;
}

    if ($action === 'list') {
        $blood_type = $_GET['blood_type'] ?? '';
        $component = $_GET['component'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $sql = "SELECT inventory_id, donor_id, blood_type, component, collection_date, expiration_date, status 
                FROM blood_inventory WHERE 1=1";
        $params = [];
        $types = "";
        
        if (!empty($blood_type)) {
            $sql .= " AND blood_type = ?";
            $params[] = $blood_type;
            $types .= "s";
        }
        
        if (!empty($component)) {
            $sql .= " AND component = ?";
            $params[] = $component;
            $types .= "s";
        }
        
        if (!empty($status)) {
            $sql .= " AND status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $sql .= " ORDER BY expiration_date ASC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        
        echo json_encode($rows);
        exit;
    }

    if ($action === 'stats') {
        // Total units
        $total_sql = "SELECT COUNT(*) as total FROM blood_inventory";
        $total_result = $conn->query($total_sql);
        $total = $total_result->fetch_assoc()['total'];
        
        // Available units
        $available_sql = "SELECT COUNT(*) as available FROM blood_inventory WHERE status = 'available'";
        $available_result = $conn->query($available_sql);
        $available = $available_result->fetch_assoc()['available'];
        
        // Expiring soon (within 7 days)
        $expiring_sql = "SELECT COUNT(*) as expiring FROM blood_inventory 
                        WHERE status = 'available' 
                        AND expiration_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
        $expiring_result = $conn->query($expiring_sql);
        $expiring = $expiring_result->fetch_assoc()['expiring'];
        
        // Critical level (less than 5 units available)
        $critical_sql = "SELECT COUNT(*) as critical FROM (
                            SELECT blood_type, COUNT(*) as count 
                            FROM blood_inventory 
                            WHERE status = 'available' 
                            GROUP BY blood_type
                        ) as blood_counts WHERE count < 5";
        $critical_result = $conn->query($critical_sql);
        $critical = $critical_result->fetch_assoc()['critical'];
        
        echo json_encode([
            'total_units' => $total,
            'available_units' => $available,
            'expiring_units' => $expiring,
            'critical_units' => $critical
        ]);
        exit;
    }

    if ($action === 'get_donors') {
        // Check if status column exists
        $check_column_sql = "SHOW COLUMNS FROM donors LIKE 'status'";
        $column_result = $conn->query($check_column_sql);
        
        if ($column_result->num_rows > 0) {
            $sql = "SELECT donor_id, full_name, blood_type FROM donors WHERE status = 'Active' ORDER BY full_name";
        } else {
            $sql = "SELECT donor_id, full_name, blood_type FROM donors ORDER BY full_name";
        }
        
        $result = $conn->query($sql);
        
        $donors = [];
        while ($row = $result->fetch_assoc()) {
            $donors[] = $row;
        }
        
        echo json_encode($donors);
        exit;
    }

    if ($action === 'get_donor_blood_type') {
        $donor_id = (int)$_GET['donor_id'];
        
        $sql = "SELECT blood_type FROM donors WHERE donor_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode(['blood_type' => $row['blood_type']]);
        } else {
            echo json_encode(['blood_type' => '']);
        }
        exit;
    }

    if ($action === 'add_donor') {
        $full_name = trim($_POST['full_name']);
        $blood_type = $_POST['blood_type'];
        $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
        $unique_number = !empty($_POST['unique_number']) ? trim($_POST['unique_number']) : null;
        
        // Validate required fields
        if (empty($full_name) || empty($blood_type)) {
            echo json_encode(['success' => false, 'message' => 'Full name and blood type are required']);
            exit;
        }
        
        // Check if unique number already exists
        if (!empty($unique_number)) {
            $check_unique_sql = "SELECT donor_id FROM donors WHERE unique_number = ?";
            $check_stmt = $conn->prepare($check_unique_sql);
            $check_stmt->bind_param("s", $unique_number);
            $check_stmt->execute();
            $unique_result = $check_stmt->get_result();
            
            if ($unique_result->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Unique ID number already exists']);
                exit;
            }
        }
        
        // Check if status column exists
        $check_column_sql = "SHOW COLUMNS FROM donors LIKE 'status'";
        $column_result = $conn->query($check_column_sql);
        
        if ($column_result->num_rows > 0) {
            $sql = "INSERT INTO donors (full_name, blood_type, contact_number, unique_number, status) 
                    VALUES (?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $full_name, $blood_type, $contact_number, $unique_number);
        } else {
            $sql = "INSERT INTO donors (full_name, blood_type, contact_number, unique_number) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $full_name, $blood_type, $contact_number, $unique_number);
        }
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'message' => 'Donor added successfully', 
                'donor_id' => $stmt->insert_id,
                'blood_type' => $blood_type
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error adding donor: ' . $conn->error]);
        }
        exit;
    }

    // REMOVED DUPLICATE get_available_units ACTION HANDLER

    if ($action === 'check_whole_blood') {
    $blood_type = $_GET['blood_type'] ?? '';
    
    if (empty($blood_type)) {
            echo json_encode(['available' => false]);
            exit;
        }
        
        // Debug: Log the received parameter
        error_log("check_whole_blood called with blood_type: $blood_type");
        
        $sql = "SELECT inventory_id FROM blood_inventory 
                WHERE blood_type = ? AND component = 'Whole Blood' AND status = 'available' 
                ORDER BY collection_date ASC LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $blood_type);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // Debug: Log the found whole blood unit
            error_log("Found whole blood unit: " . $row['inventory_id']);
            echo json_encode(['available' => true, 'inventory_id' => $row['inventory_id']]);
        } else {
            // Debug: Log that no whole blood was found
            error_log("No whole blood unit found for blood type: $blood_type");
            echo json_encode(['available' => false]);
        }
        exit;
    }

    if ($action === 'get_inventory_details') {
        $inventory_id = (int)$_GET['inventory_id'];
        
        $sql = "SELECT blood_type, component FROM blood_inventory WHERE inventory_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $inventory_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode(['success' => true, 'blood_type' => $row['blood_type'], 'component' => $row['component']]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    // Add this after the existing action handlers
    if ($action === 'get_donors_table') {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';
        $blood_type = $_GET['blood_type'] ?? '';
        $status = $_GET['status'] ?? '';
        $sort = $_GET['sort'] ?? 'donor_id';
        $order = $_GET['order'] ?? 'ASC';
        
        // Validate sort column
        $allowed_sorts = ['donor_id', 'full_name', 'blood_type', 'contact_number', 'unique_number', 'status'];
        if (!in_array($sort, $allowed_sorts)) {
            $sort = 'donor_id';
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        
        // Build query
        $sql = "SELECT donor_id, full_name, blood_type, contact_number, unique_number, status FROM donors WHERE 1=1";
        $count_sql = "SELECT COUNT(*) as total FROM donors WHERE 1=1";
        $params = [];
        $types = "";
        
        if (!empty($search)) {
            $sql .= " AND (full_name LIKE ? OR unique_number LIKE ? OR contact_number LIKE ?)";
            $count_sql .= " AND (full_name LIKE ? OR unique_number LIKE ? OR contact_number LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "sss";
        }
        
        if (!empty($blood_type)) {
            $sql .= " AND blood_type = ?";
            $count_sql .= " AND blood_type = ?";
            $params[] = $blood_type;
            $types .= "s";
        }
        
        if (!empty($status)) {
            $sql .= " AND status = ?";
            $count_sql .= " AND status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $sql .= " ORDER BY $sort $order LIMIT ? OFFSET ?";
        
        // Get total count
        $stmt_count = $conn->prepare($count_sql);
        if (!empty($types)) {
            $stmt_count->bind_param($types, ...$params);
        }
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total = $count_result->fetch_assoc()['total'];
        
        // Get donors
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $donors = [];
        while ($row = $result->fetch_assoc()) {
            $donors[] = $row;
        }
        
        echo json_encode([
            'donors' => $donors,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'order' => $order
        ]);
        exit;
    }

    if ($action === 'get_transactions') {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';
        $type_filter = $_GET['type'] ?? '';
        $sort = $_GET['sort'] ?? 'bt.created_at';
        $order = $_GET['order'] ?? 'DESC';
        
        // Validate sort column
        $allowed_sorts = ['bt.transaction_id', 'bt.inventory_id', 'bi.blood_type', 'bi.component', 'bt.transaction_type', 'bt.patient_name', 'bt.hospital', 'u.username', 'bt.created_at'];
        if (!in_array($sort, $allowed_sorts)) {
            $sort = 'bt.created_at';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        
        // FIXED: Proper SQL query to get all transactions including OUT transactions
        $sql = "SELECT bt.*, bi.blood_type, bi.component, u.username as processed_by_name 
                FROM blood_transactions bt 
                LEFT JOIN blood_inventory bi ON bt.inventory_id = bi.inventory_id 
                LEFT JOIN users u ON bt.processed_by = u.id 
                WHERE 1=1";
        
        $count_sql = "SELECT COUNT(*) as total 
                    FROM blood_transactions bt 
                    LEFT JOIN blood_inventory bi ON bt.inventory_id = bi.inventory_id 
                    LEFT JOIN users u ON bt.processed_by = u.id 
                    WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if (!empty($search)) {
            $sql .= " AND (bt.patient_name LIKE ? OR bt.hospital LIKE ? OR u.username LIKE ? OR bt.transaction_type LIKE ?)";
            $count_sql .= " AND (bt.patient_name LIKE ? OR bt.hospital LIKE ? OR u.username LIKE ? OR bt.transaction_type LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ssss";
        }
        
        if (!empty($type_filter)) {
            $sql .= " AND bt.transaction_type = ?";
            $count_sql .= " AND bt.transaction_type = ?";
            $params[] = $type_filter;
            $types .= "s";
        }
        
        $sql .= " ORDER BY $sort $order LIMIT ? OFFSET ?";
        
        // Get total count
        $stmt_count = $conn->prepare($count_sql);
        if (!empty($types)) {
            $stmt_count->bind_param($types, ...$params);
        }
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total = $count_result->fetch_assoc()['total'];
        
        // Get transactions
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        // Debug: Log the results to see what's being returned
        error_log("Transactions found: " . count($transactions));
        error_log("Transaction data: " . print_r($transactions, true));
        
        echo json_encode([
            'transactions' => $transactions,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'order' => $order
        ]);
        exit;
    }

    if ($action === 'get_transaction_details') {
        $transaction_id = (int)$_GET['transaction_id'];
        
        $sql = "SELECT bt.*, bi.blood_type, bi.component, bi.donor_id, u.username as processed_by_name 
                FROM blood_transactions bt 
                LEFT JOIN blood_inventory bi ON bt.inventory_id = bi.inventory_id 
                LEFT JOIN users u ON bt.processed_by = u.id 
                WHERE bt.transaction_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $transaction = $result->fetch_assoc();
            echo json_encode(['success' => true, 'transaction' => $transaction]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

function calculate_expiration_date($collection_date, $component) {
    $collection_timestamp = strtotime($collection_date);
    
    // Set expiration based on component type
    switch($component) {
        case 'Whole Blood':
            $expiration_days = 42; // 6 weeks
            break;
        case 'RBC':
            $expiration_days = 42; // 6 weeks
            break;
        case 'Plasma':
            $expiration_days = 365; // 1 year frozen
            break;
        case 'Platelets':
            $expiration_days = 5; // 5 days
            break;
        case 'Cryoprecipitate':
            $expiration_days = 365; // 1 year frozen
            break;
        default:
            $expiration_days = 42; // Default to whole blood
    }
    
    return date('Y-m-d H:i:s', strtotime("+$expiration_days days", $collection_timestamp));
}

// Include header and sidebar files
include('../../ADMIN/header.php');
include('../../ADMIN/testsidebar.php');

$user_id = $_SESSION['user_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SH | Blood Inventory</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        }
        
        html, body {
            background-color: #ffffff;
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 50px;
            background: #fff;
            flex: 1;
            min-height: calc(100vh - 70px);
        }

        .page-title {
            color: var(--primary-dark);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-light);
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        .card-primary {
            border-top: 4px solid var(--primary);
        }

        .card-success {
            border-top: 4px solid var(--success);
        }

        .card-warning {
            border-top: 4px solid var(--warning);
        }

        .card-danger {
            border-top: 4px solid var(--danger);
        }

        .card-title {
            font-size: 16px;
            color: var(--secondary);
            margin-bottom: 10px;
        }

        .card-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--primary-dark);
        }

        .card-icon {
            float: right;
            font-size: 32px;
            color: var(--primary-light);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
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

        .btn i {
            margin-right: 8px;
        }

        .inventory-table-container {
            background: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            overflow: auto;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table th, table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--grey);
        }

        table th {
            background-color: #e62929;
            color: #fff;
            font-weight: 600;
        }

        table tr:hover {
            background-color: rgba(229, 57, 53, 0.05);
        }

        .status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-available {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success);
        }

        .status-used {
            background-color: rgba(244, 67, 54, 0.2);
            color: var(--danger);
        }

        .status-expired {
            background-color: rgba(255, 152, 0, 0.2);
            color: var(--warning);
        }

        /* Modal Styles */
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
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: modalopen 0.4s;
        }

        @keyframes modalopen {
            from {opacity: 0; transform: translateY(-60px);}
            to {opacity: 1; transform: translateY(0);}
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

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
        }

        .close {
            color: var(--white);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: var(--primary-light);
        }

        .modal-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(229, 57, 53, 0.2);
        }

        .modal-footer {
            padding: 15px 20px;
            background: var(--grey);
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
            text-align: right;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: none;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background-color: rgba(244, 67, 54, 0.2);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background-color: var(--white);
        }

        .btn-action {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 16px;
            margin-right: 10px;
            transition: color 0.3s;
        }

        .btn-action:hover {
            color: var(--primary-dark);
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 220px;
            }
            header, .main-content {
                margin-left: 220px;
            }
        }

        @media (max-width: 768px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                flex-direction: column;
            }
            .filter-section {
                flex-direction: column;
            }
        }

        .status-pending {
            background-color: rgba(255, 152, 0, 0.2);
            color: var(--warning);
        }

        .status-approved {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success);
        }

        .status-rejected {
            background-color: rgba(244, 67, 54, 0.2);
            color: var(--danger);
        }

        .status-out-of-stock {
            background-color: rgba(117, 117, 117, 0.2);
            color: #757575;
        }

        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--primary-light);
        }

        .tab {
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--primary-dark);
            border-bottom: 3px solid var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 10px;
        }

        .pagination button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            border-radius: 4px;
        }

        .pagination button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .transaction-type {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .transaction-in {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success);
        }

        .transaction-out {
            background-color: rgba(244, 67, 54, 0.2);
            color: var(--danger);
        }

        /* Ensure tabs display correctly */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--primary-light);
        }

        .tab {
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--primary-dark);
            border-bottom: 3px solid var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .pagination-ellipsis {
            padding: 8px 12px;
            color: #666;
        }

        th {
            position: relative;
        }

        th .fa-sort-up, 
        th .fa-sort-down {
            margin-left: 5px;
            color: var(--primary);
        }

        th .fa-sort {
            margin-left: 5px;
            color: #ccc;
        }

        .transaction-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-item label {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 5px;
            font-size: 14px;
        }

        .detail-item span {
            padding: 8px;
            background: #f9f9f9;
            border-radius: 4px;
            border-left: 3px solid var(--primary);
        }

        .detail-item.full-width {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body>

<div class="main-content">
    <h1 class="page-title">Blood Inventory Management</h1>
    
    <!-- Update the tabs section -->
    <div class="tabs">
        <div class="tab active" data-tab="inventory">Inventory</div>
        <div class="tab" data-tab="transactions">Transactions</div>
        <div class="tab" data-tab="donors">Donors</div>
    </div>

    <!-- Add Donors Tab Content -->
    <div class="tab-content" id="donors-tab">
        <div class="inventory-table-container">
            <h2>Donor Management</h2>
            
            <!-- Action Buttons for Donors -->
            <div class="action-buttons" style="margin-bottom: 20px;">
                <button class="btn btn-primary" id="addDonorBtn"><i class="fas fa-user-plus"></i> Add New Donor</button>
                <button class="btn btn-outline" id="refreshDonorsBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <input type="text" id="donorSearch" class="form-control" placeholder="Search donors by name or ID..." style="max-width: 300px;">
                <select class="filter-select" id="filterDonorBloodType">
                    <option value="">All Blood Types</option>
                    <option value="O+">O+</option>
                    <option value="O-">O-</option>
                    <option value="A+">A+</option>
                    <option value="A-">A-</option>
                    <option value="B+">B+</option>
                    <option value="B-">B-</option>
                    <option value="AB+">AB+</option>
                    <option value="AB-">AB-</option>
                </select>
                <select class="filter-select" id="filterDonorStatus">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            
            <!-- Donors Table -->
            <table id="donorsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Blood Type</th>
                        <th>Contact Number</th>
                        <th>Unique ID</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be loaded via AJAX -->
                </tbody>
            </table>
            
            <!-- Pagination -->
            <div class="pagination" id="donorPagination">
                <!-- Pagination will be loaded via AJAX -->
            </div>
        </div>
    </div>
    
    <!-- Inventory Tab Content -->
    <div class="tab-content active" id="inventory-tab">
        <!-- Dashboard Cards -->
        <div class="dashboard-cards">
            <div class="card card-primary">
                <div class="card-title">Total Blood Units</div>
                <div class="card-value" id="total-units">0</div>
                <div class="card-icon"><i class="fas fa-tint"></i></div>
            </div>
            <div class="card card-success">
                <div class="card-title">Available Units</div>
                <div class="card-value" id="available-units">0</div>
                <div class="card-icon"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="card card-warning">
                <div class="card-title">Expiring Soon</div>
                <div class="card-value" id="expiring-units">0</div>
                <div class="card-icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
            <div class="card card-danger">
                <div class="card-title">Critical Level</div>
                <div class="card-value" id="critical-units">0</div>
                <div class="card-icon"><i class="fas fa-skull-crossbones"></i></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary" id="addBloodBtn"><i class="fas fa-plus"></i> Add Blood</button>
            <button class="btn btn-outline" id="requestBloodBtn"><i class="fas fa-hand-holding-medical"></i> Submit Request</button>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <select class="filter-select" id="filterBloodType">
                <option value="">All Blood Types</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
            </select>
            
            <select class="filter-select" id="filterComponent">
                <option value="">All Components</option>
                <option value="Whole Blood">Whole Blood</option>
                <option value="RBC">RBC</option>
                <option value="Plasma">Plasma</option>
                <option value="Platelets">Platelets</option>
                <option value="Cryoprecipitate">Cryoprecipitate</option>
            </select>
            
            <select class="filter-select" id="filterStatus" style="display:none;">
                <option value="">All Status</option>
                <option value="Available">Available</option>
                <option value="Used">Used</option>
                <option value="Expired">Expired</option>
            </select>
        </div>

        <!-- Inventory Table -->
        <div class="inventory-table-container">
            <h2>Current Blood Inventory</h2>
            <table id="inventoryTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Donor</th>
                        <th>Blood Type</th>
                        <th>Component</th>
                        <th>Collection</th>
                        <th>Expiration</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be loaded via AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Transactions Tab Content -->
    <div class="tab-content" id="transactions-tab">
        <div class="inventory-table-container">
            <h2>Blood Transactions</h2>
            <div class="filter-section">
                <input type="text" id="transactionSearch" class="form-control" placeholder="Search transactions..." style="max-width: 300px;">
                <select class="filter-select" id="transactionTypeFilter">
                    <option value="">All Types</option>
                    <option value="IN">IN</option>
                    <option value="OUT">OUT</option>
                </select>
            </div>
            <table id="transactionsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Inventory ID</th>
                        <th>Blood Type</th>
                        <th>Component</th>
                        <th>Type</th>
                        <th>Patient/Hospital</th>
                        <th>Processed By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be loaded via AJAX -->
                </tbody>
            </table>
            <div class="pagination" id="transactionPagination">
                <!-- Pagination will be loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Add Blood Modal -->
<div id="addBloodModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add Blood to Inventory</h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="addAlert" class="alert"></div>
            <form id="addBloodForm">
                <div class="form-group">
                    <label for="donor_id">Donor</label>
                    <select id="donor_id" name="donor_id" class="form-control" required>
                        <option value="">Select Donor</option>
                        <!-- Options will be populated by JavaScript -->
                    </select>
                </div>

                <!-- In the Add Blood Modal, add a drive selection field -->
                <div class="form-group">
                    <label for="drive_id">Blood Drive (Optional)</label>
                    <select id="drive_id" name="drive_id" class="form-control">
                        <option value="">Select Blood Drive (Optional)</option>
                        <!-- Options will be populated by JavaScript -->
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="blood_type">Blood Type</label>
                    <select id="blood_type" name="blood_type" class="form-control" required>
                        <option value="">Select Blood Type</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="component">Component</label>
                    <select id="component" name="component" class="form-control" required>
                        <option value="">Select Component</option>
                        <option value="Whole Blood">Whole Blood</option>
                        <option value="RBC">RBC</option>
                        <option value="Plasma">Plasma</option>
                        <option value="Platelets">Platelets</option>
                        <option value="Cryoprecipitate">Cryoprecipitate</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="collection_date">Collection Date</label>
                    <input type="datetime-local" id="collection_date" name="collection_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="expiration_date">Expiration Date</label>
                    <input type="datetime-local" id="expiration_date" name="expiration_date" class="form-control" required readonly>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelAdd">Cancel</button>
            <button type="button" class="btn btn-primary" id="submitAdd">Add Blood</button>
        </div>
    </div>
</div>

<!-- Request Blood Modal -->
<div id="requestBloodModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Request Blood From Inventory</h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="requestAlert" class="alert"></div>
            <form id="requestBloodForm">
                <div class="form-group">
                    <label for="request_blood_type">Blood Type</label>
                    <select id="request_blood_type" name="request_blood_type" class="form-control" required>
                        <option value="">Select Blood Type</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="request_component">Component</label>
                    <select id="request_component" name="request_component" class="form-control" required>
                        <option value="">Select Component</option>
                        <option value="Whole Blood">Whole Blood</option>
                        <option value="RBC">RBC</option>
                        <option value="Plasma">Plasma</option>
                        <option value="Platelets">Platelets</option>
                        <option value="Cryoprecipitate">Cryoprecipitate</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="available_units">Available Units (FIFO Order)</label>
                    <select id="available_units" name="inventory_id" class="form-control" required>
                        <option value="">Select a unit (oldest first)</option>
                        <!-- Options will be populated by JavaScript -->
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="patient_name">Patient Name</label>
                    <input type="text" id="patient_name" name="patient_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="hospital">Hospital/Department</label>
                    <input type="text" id="hospital" name="hospital" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="request_notes">Notes</label>
                    <textarea id="request_notes" name="request_notes" class="form-control" rows="3"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelRequest">Cancel</button>
            <button type="button" class="btn btn-primary" id="submitRequest">Request Blood</button>
        </div>
    </div>
</div>

<!-- Add Donor Modal -->
<div id="addDonorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Donor</h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="donorAlert" class="alert"></div>
            <form id="addDonorForm">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="donor_blood_type">Blood Type</label>
                    <select id="donor_blood_type" name="blood_type" class="form-control" required>
                        <option value="">Select Blood Type</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <input type="tel" id="contact_number" name="contact_number" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="unique_number">Unique ID Number</label>
                    <input type="text" id="unique_number" name="unique_number" class="form-control">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelDonor">Cancel</button>
            <button type="button" class="btn btn-primary" id="submitDonor">Add Donor</button>
        </div>
    </div>
</div>

<!-- Transaction Details Modal -->
<div id="transactionDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Transaction Details</h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="transactionDetails">
                <!-- Details will be populated by JavaScript -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="closeTransactionDetails">Close</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function(){
    const ajaxUrl = window.location.href.split('?')[0];
    
    // Current pages and states
    let currentTransactionPage = 1;
    let currentDonorPage = 1;
    const transactionsPerPage = 10;
    const donorsPerPage = 10;
    
    // Sort states
    let transactionSort = {field: 'bt.created_at', order: 'DESC'};
    let donorSort = {field: 'donor_id', order: 'ASC'};
    
    // Search states
    let transactionSearchState = {
        search: '',
        type: ''
    };
    
    let donorSearchState = {
        search: '',
        blood_type: '',
        status: ''
    };

    // Load initial data
    loadInventory();
    loadDashboardStats();
    
    // Tab functionality
    $(".tab").click(function() {
        const tabId = $(this).data('tab');
        
        $(".tab").removeClass('active');
        $(this).addClass('active');
        
        $(".tab-content").removeClass('active');
        $(`#${tabId}-tab`).addClass('active');
        
        if (tabId === 'transactions') {
            loadTransactions();
            setTimeout(addTransactionRefreshButton, 100);
        } else if (tabId === 'donors') {
            loadDonorsTable();
        } else if (tabId === 'inventory') {
            setTimeout(addInventoryRefreshButton, 100);
        }
    });
    
    // Modal functionality
    const addModal = document.getElementById("addBloodModal");
    const requestModal = document.getElementById("requestBloodModal");
    const donorModal = document.getElementById("addDonorModal");
    const transactionModal = document.getElementById("transactionDetailsModal");
    
    // Open modals
    $("#addBloodBtn").click(function() {
        addModal.style.display = "block";
        loadDonors();
        loadBloodDrives();
    });
    
    $("#requestBloodBtn").click(function() {
        requestModal.style.display = "block";
        $("#request_blood_type, #request_component").val("");
        $("#available_units").html('<option value="">First select blood type and component</option>');
    });
    
    $("#addDonorBtn, #addDonorBtnTab").click(function() {
        donorModal.style.display = "block";
    });
    
    // Close modals
    $(".close, #cancelAdd, #cancelRequest, #cancelDonor, #closeTransactionDetails").click(function() {
        addModal.style.display = "none";
        requestModal.style.display = "none";
        donorModal.style.display = "none";
        transactionModal.style.display = "none";
        clearAlerts();
        clearDonorAlerts();
    });
    
    $(window).click(function(event) {
        if (event.target == addModal) {
            addModal.style.display = "none";
            clearAlerts();
        }
        if (event.target == requestModal) {
            requestModal.style.display = "none";
            clearAlerts();
        }
        if (event.target == donorModal) {
            donorModal.style.display = "none";
            clearDonorAlerts();
        }
        if (event.target == transactionModal) {
            transactionModal.style.display = "none";
        }
    });
    
    // INVENTORY FILTER FUNCTIONALITY - NEW
    $("#filterBloodType, #filterComponent, #filterStatus").change(function() {
        loadInventory();
    });
    
    // Form submissions
    $("#submitAdd").click(function(){
        $.ajax({
            url: ajaxUrl + "?action=add",
            type: "POST",
            data: $("#addBloodForm").serialize(),
            dataType: "json",
            success: function(data) {
                if(data.success) {
                    showAlert("addAlert", "success", data.message);
                    setTimeout(function(){
                        addModal.style.display = "none";
                        clearAlerts();
                        loadInventory();
                        loadDashboardStats();
                    }, 1500);
                } else {
                    showAlert("addAlert", "error", data.message);
                }
            },
            error: function(xhr, status, error) {
                showAlert("addAlert", "error", "Error connecting to server. Please try again.");
                console.error("AJAX Error:", status, error);
            }
        });
    });
    
    // Submit request blood
    $("#submitRequest").click(function(){
        $.ajax({
            url: ajaxUrl + "?action=out",
            type: "POST",
            data: $("#requestBloodForm").serialize(),
            dataType: "json",
            success: function(data) {
                if(data.success) {
                    showAlert("requestAlert", "success", data.message);
                    setTimeout(function(){
                        requestModal.style.display = "none";
                        clearAlerts();
                        loadInventory();
                        loadDashboardStats();
                    }, 1500);
                } else {
                    showAlert("requestAlert", "error", data.message);
                }
            },
            error: function(xhr, status, error) {
                showAlert("requestAlert", "error", "Error connecting to server. Please try again.");
                console.error("AJAX Error:", status, error);
            }
        });
    });
    
    // Submit donor
    $("#submitDonor").click(function(){
        $.ajax({
            url: ajaxUrl + "?action=add_donor",
            type: "POST",
            data: $("#addDonorForm").serialize(),
            dataType: "json",
            success: function(data) {
                if(data.success) {
                    showAlert("donorAlert", "success", data.message);
                    setTimeout(function(){
                        donorModal.style.display = "none";
                        clearDonorAlerts();
                        // Auto-select the new donor if we're in the add blood modal
                        if(data.donor_id && data.blood_type) {
                            loadDonors(); // Refresh donor list
                            setTimeout(function() {
                                $("#donor_id").val(data.donor_id);
                                $("#blood_type").val(data.blood_type);
                                calculateExpirationDate();
                            }, 500);
                        }
                        // Refresh donors table if we're on that tab
                        if($(".tab.active").data('tab') === 'donors') {
                            loadDonorsTable();
                        }
                    }, 1500);
                } else {
                    showAlert("donorAlert", "error", data.message);
                }
            },
            error: function(xhr, status, error) {
                showAlert("donorAlert", "error", "Error connecting to server. Please try again.");
                console.error("AJAX Error:", status, error);
            }
        });
    });
    
    // Request blood type and component change handlers
    $("#request_blood_type, #request_component").change(function() {
        const bloodType = $("#request_blood_type").val();
        const component = $("#request_component").val();
        
        if (bloodType && component) {
            loadAvailableUnits(bloodType, component);
        } else {
            $("#available_units").html('<option value="">Please select blood type and component first</option>');
        }
    });
    
    // Component change handler for expiration date calculation
    $("#component, #collection_date").change(function() {
        calculateExpirationDate();
    });
    
    // Donor search and filter with debounce
    let donorSearchTimeout;
    $("#donorSearch").on('input', function() {
        clearTimeout(donorSearchTimeout);
        donorSearchTimeout = setTimeout(function() {
            currentDonorPage = 1;
            donorSearchState.search = $("#donorSearch").val();
            loadDonorsTable();
        }, 500);
    });
    
    $("#filterDonorBloodType, #filterDonorStatus").change(function() {
        currentDonorPage = 1;
        donorSearchState.blood_type = $("#filterDonorBloodType").val();
        donorSearchState.status = $("#filterDonorStatus").val();
        loadDonorsTable();
    });
    
    // Transaction search and filter with debounce - UPDATED
    let transactionSearchTimeout;
    $("#transactionSearch").on('input', function() {
        clearTimeout(transactionSearchTimeout);
        transactionSearchTimeout = setTimeout(function() {
            currentTransactionPage = 1;
            transactionSearchState.search = $("#transactionSearch").val().trim();
            loadTransactions();
        }, 500);
    });

    $("#transactionTypeFilter").change(function() {
        currentTransactionPage = 1;
        transactionSearchState.type = $("#transactionTypeFilter").val();
        loadTransactions();
    });
    
    // Add refresh functionality for donors
    $("#refreshDonorsBtn").click(function() {
        donorSearchState = { search: '', blood_type: '', status: '' };
        $("#donorSearch").val('');
        $("#filterDonorBloodType").val('');
        $("#filterDonorStatus").val('');
        currentDonorPage = 1;
        donorSort = {field: 'donor_id', order: 'ASC'};
        loadDonorsTable();
    });
    
    // Load donors for dropdown
    function loadDonors() {
        $.get(ajaxUrl + "?action=get_donors", function(data) {
            let options = '<option value="">Select Donor</option>';
            if (data.length > 0) {
                data.forEach(function(donor) {
                    options += `<option value="${donor.donor_id}">${donor.full_name} (${donor.blood_type})</option>`;
                });
            } else {
                options += '<option value="" disabled>No donors available</option>';
            }
            $("#donor_id").html(options);
        }, "json").fail(function(xhr, status, error) {
            console.error("Error loading donors:", error);
            $("#donor_id").html('<option value="" disabled>Error loading donors</option>');
        });
    }

    // Load blood drives for dropdown
    function loadBloodDrives() {
        $.get(ajaxUrl + "?action=get_blood_drives", function(data) {
            let options = '<option value="">Select Blood Drive (Optional)</option>';
            if (data.length > 0) {
                data.forEach(function(drive) {
                    options += `<option value="${drive.drive_id}">${drive.drive_name}</option>`;
                });
            }
            $("#drive_id").html(options);
        }, "json").fail(function(xhr, status, error) {
            console.error("Error loading blood drives:", error);
            $("#drive_id").html('<option value="">Error loading blood drives</option>');
        });
    }
    
    // Load available blood units for request (FIFO order) with better error handling
    function loadAvailableUnits(bloodType, component) {
        console.log("Loading available units for:", bloodType, component);
        
        // Show loading state
        $("#available_units").html('<option value="">Loading available units...</option>');
        
        $.ajax({
            url: ajaxUrl + `?action=get_available_units&blood_type=${encodeURIComponent(bloodType)}&component=${encodeURIComponent(component)}`,
            type: "GET",
            dataType: "json",
            success: function(data) {
                console.log("Received data:", data);
                
                let options = '<option value="">Select a unit (oldest first)</option>';
                
                if (data && data.length > 0) {
                    data.forEach(function(unit) {
                        const expDate = new Date(unit.expiration_date);
                        const formattedDate = expDate.toLocaleDateString();
                        options += `<option value="${unit.inventory_id}">ID: ${unit.inventory_id} - Exp: ${formattedDate} (Donor: ${unit.donor_id})</option>`;
                    });
                    $("#available_units").html(options);
                } else {
                    // Check if we can split from whole blood for non-whole-blood components
                    if (component !== "Whole Blood") {
                        $.ajax({
                            url: ajaxUrl + `?action=check_whole_blood&blood_type=${encodeURIComponent(bloodType)}`,
                            type: "GET",
                            dataType: "json",
                            success: function(wholeBloodData) {
                                if (wholeBloodData.available) {
                                    options += `<option value="split_${wholeBloodData.inventory_id}">SPLIT from Whole Blood ID: ${wholeBloodData.inventory_id}</option>`;
                                    $("#available_units").html(options);
                                } else {
                                    $("#available_units").html('<option value="">No available units found</option>');
                                }
                            },
                            error: function() {
                                $("#available_units").html('<option value="">Error checking for whole blood</option>');
                            }
                        });
                    } else {
                        $("#available_units").html('<option value="">No available units found</option>');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error("Error loading available units:", error);
                console.error("Response:", xhr.responseText);
                $("#available_units").html('<option value="">Error loading available units</option>');
            }
        });
    }
    
    // Auto-set blood type when donor is selected
    $("#donor_id").change(function() {
        const donorId = $(this).val();
        if (donorId) {
            $.get(ajaxUrl + "?action=get_donor_blood_type&donor_id=" + donorId, function(data) {
                if (data.blood_type) {
                    $("#blood_type").val(data.blood_type);
                    calculateExpirationDate();
                }
            }, "json");
        }
    });
    
    function calculateExpirationDate() {
        const component = $("#component").val();
        const collectionDate = $("#collection_date").val();
        
        if (component && collectionDate) {
            const expirationDate = calculateExpirationDateJS(collectionDate, component);
            $("#expiration_date").val(expirationDate);
        }
    }
    
    function calculateExpirationDateJS(collectionDate, component) {
        const collection = new Date(collectionDate);
        let expirationDays = 42; // Default: Whole Blood (6 weeks)
        
        switch(component) {
            case 'Whole Blood':
                expirationDays = 42;
                break;
            case 'RBC':
                expirationDays = 42;
                break;
            case 'Plasma':
                expirationDays = 365; // 1 year frozen
                break;
            case 'Platelets':
                expirationDays = 5;
                break;
            case 'Cryoprecipitate':
                expirationDays = 365; // 1 year frozen
                break;
        }
        
        const expiration = new Date(collection);
        expiration.setDate(collection.getDate() + expirationDays);
        return expiration.toISOString().slice(0, 16);
    }
    
    // UPDATED loadInventory function with proper filtering
    function loadInventory(){
        const bloodType = $("#filterBloodType").val();
        const component = $("#filterComponent").val();
        const status = $("#filterStatus").val();
        
        // Build query parameters properly
        let queryParams = "action=list";
        
        if (bloodType) {
            queryParams += "&blood_type=" + encodeURIComponent(bloodType);
        }
        if (component) {
            queryParams += "&component=" + encodeURIComponent(component);
        }
        if (status) {
            queryParams += "&status=" + encodeURIComponent(status);
        }
        
        console.log("Loading inventory with params:", queryParams); // Debug log
        
        $.get(ajaxUrl + "?" + queryParams, function(data){
            let rows = "";
            if (data.length === 0) {
                rows = `<tr><td colspan="8" style="text-align: center;">No inventory records found</td></tr>`;
            } else {
                data.forEach(function(row){
                    let statusClass = "";
                    if(row.status === "available") statusClass = "status-available";
                    else if(row.status === "pending") statusClass = "status-pending";
                    else if(row.status === "approved") statusClass = "status-approved";
                    else if(row.status === "rejected") statusClass = "status-rejected";
                    else if(row.status === "used") statusClass = "status-used";
                    else if(row.status === "out_of_stock") statusClass = "status-out-of-stock";
                    else if(row.status === "expired") statusClass = "status-expired";
                    
                    rows += `<tr>
                        <td>${row.inventory_id}</td>
                        <td>${row.donor_id}</td>
                        <td>${row.blood_type}</td>
                        <td>${row.component}</td>
                        <td>${formatDate(row.collection_date)}</td>
                        <td>${formatDate(row.expiration_date)}</td>
                        <td><span class="status ${statusClass}">${row.status}</span></td>
                    </tr>`;
                });
            }
            $("#inventoryTable tbody").html(rows);
            
            // Add event listeners to action buttons
            $(".request-btn").click(function(){
                const inventoryId = $(this).data('id');
                // Get blood details for this inventory item
                $.get(ajaxUrl + `?action=get_inventory_details&inventory_id=${inventoryId}`, function(data) {
                    if (data.success) {
                        $("#request_blood_type").val(data.blood_type);
                        $("#request_component").val(data.component);
                        loadAvailableUnits(data.blood_type, data.component);
                        // Select the specific unit
                        setTimeout(function() {
                            $("#available_units").val(inventoryId);
                        }, 300);
                    }
                    requestModal.style.display = "block";
                }, "json");
            });
        }, "json").fail(function(xhr, status, error) {
            console.error("Error loading inventory:", error);
            console.error("Response:", xhr.responseText); // Additional debug info
            $("#inventoryTable tbody").html('<tr><td colspan="8" style="text-align: center; color: red;">Error loading inventory data</td></tr>');
        });
    }
    
    // Enhanced loadDonorsTable function with sorting
    function loadDonorsTable(page = 1) {
        const search = donorSearchState.search;
        const bloodType = donorSearchState.blood_type;
        const status = donorSearchState.status;
        
        $.get(ajaxUrl + `?action=get_donors_table&page=${page}&limit=${donorsPerPage}&search=${encodeURIComponent(search)}&blood_type=${encodeURIComponent(bloodType)}&status=${encodeURIComponent(status)}&sort=${donorSort.field}&order=${donorSort.order}`, function(data){
            let rows = "";
            if (data.donors.length === 0) {
                rows = `<tr><td colspan="6" style="text-align: center;">No donor records found</td></tr>`;
            } else {
                data.donors.forEach(function(donor){
                    let statusClass = donor.status === 'Active' ? 'status-available' : 'status-used';
                    
                    rows += `<tr>
                        <td>${donor.donor_id}</td>
                        <td>${donor.full_name}</td>
                        <td>${donor.blood_type}</td>
                        <td>${donor.contact_number || 'N/A'}</td>
                        <td>${donor.unique_number || 'N/A'}</td>
                        <td><span class="status ${statusClass}">${donor.status || 'Active'}</span></td>
                    </tr>`;
                });
            }
            $("#donorsTable tbody").html(rows);
            
            // Update pagination
            updatePagination("#donorPagination", page, data.total, donorsPerPage, loadDonorsTable);
            
        }, "json").fail(function(xhr, status, error) {
            console.error("Error loading donors table:", error);
            $("#donorsTable tbody").html('<tr><td colspan="6" style="text-align: center; color: red;">Error loading donor data</td></tr>');
        });
    }
    
    // Enhanced loadTransactions function with sorting - UPDATED
    function loadTransactions(page = 1) {
        const search = transactionSearchState.search;
        const type = transactionSearchState.type;
        
        $.get(ajaxUrl + `?action=get_transactions&page=${page}&limit=${transactionsPerPage}&search=${encodeURIComponent(search)}&type=${encodeURIComponent(type)}&sort=${transactionSort.field}&order=${transactionSort.order}`, function(data){
            let rows = "";
            if (data.transactions.length === 0) {
                rows = `<tr><td colspan="9" style="text-align: center;">No transaction records found</td></tr>`;
            } else {
                data.transactions.forEach(function(row){
                    const typeClass = row.transaction_type === 'IN' ? 'transaction-in' : 'transaction-out';
                    
                    rows += `<tr>
                        <td>${row.transaction_id}</td>
                        <td>${row.inventory_id}</td>
                        <td>${row.blood_type || 'N/A'}</td>
                        <td>${row.component || 'N/A'}</td>
                        <td><span class="transaction-type ${typeClass}">${row.transaction_type}</span></td>
                        <td>${row.patient_name || 'N/A'}${row.hospital ? ` (${row.hospital})` : ''}</td>
                        <td>${row.processed_by_name || 'System'}</td>
                        <td>${formatDate(row.created_at)}</td>
                        <td>
                            <button class="btn-action view-transaction-btn" data-id="${row.transaction_id}" title="View Details"><i class="fas fa-eye"></i></button>
                        </td>
                    </tr>`;
                });
            }
            $("#transactionsTable tbody").html(rows);
            
            // Update pagination
            updatePagination("#transactionPagination", page, data.total, transactionsPerPage, loadTransactions);
            
            // Add event listeners to view buttons
            $(".view-transaction-btn").click(function(){
                const transactionId = $(this).data('id');
                viewTransactionDetails(transactionId);
            });
            
        }, "json").fail(function(xhr, status, error) {
            console.error("Error loading transactions:", error);
            $("#transactionsTable tbody").html('<tr><td colspan="9" style="text-align: center; color: red;">Error loading transaction data</td></tr>');
        });
    }
    
    // Generic pagination function
    function updatePagination(container, currentPage, totalItems, itemsPerPage, loadFunction) {
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        let paginationHtml = '';
        
        if (totalPages > 1) {
            if (currentPage > 1) {
                paginationHtml += `<button class="page-btn" data-page="${currentPage - 1}">Previous</button>`;
            }
            
            // Show first page, pages around current page, and last page
            const showPage = (page) => {
                if (page === currentPage) {
                    paginationHtml += `<button class="page-btn active" data-page="${page}">${page}</button>`;
                } else {
                    paginationHtml += `<button class="page-btn" data-page="${page}">${page}</button>`;
                }
            };
            
            if (totalPages <= 7) {
                // Show all pages
                for (let i = 1; i <= totalPages; i++) {
                    showPage(i);
                }
            } else {
                // Complex pagination logic
                showPage(1);
                
                if (currentPage > 3) {
                    paginationHtml += `<span class="pagination-ellipsis">...</span>`;
                }
                
                let start = Math.max(2, currentPage - 1);
                let end = Math.min(totalPages - 1, currentPage + 1);
                
                for (let i = start; i <= end; i++) {
                    if (i > 1 && i < totalPages) {
                        showPage(i);
                    }
                }
                
                if (currentPage < totalPages - 2) {
                    paginationHtml += `<span class="pagination-ellipsis">...</span>`;
                }
                
                showPage(totalPages);
            }
            
            if (currentPage < totalPages) {
                paginationHtml += `<button class="page-btn" data-page="${currentPage + 1}">Next</button>`;
            }
        }
        
        $(container).html(paginationHtml);
        
        // Add event listeners to pagination buttons
        $(container).off('click', '.page-btn').on('click', '.page-btn', function() {
            const pageNum = $(this).data('page');
            if ($(this).hasClass('active')) return;
            
            if (loadFunction === loadDonorsTable) {
                currentDonorPage = pageNum;
            } else {
                currentTransactionPage = pageNum;
            }
            loadFunction(pageNum);
        });
    }
    
    // Add column sorting functionality
    function makeSortable(tableId, columns, sortState, loadFunction) {
        $(`${tableId} th`).each(function(index) {
            const column = columns[index];
            if (column) {
                $(this).css('cursor', 'pointer');
                
                $(this).click(function() {
                    // Update sort state
                    if (sortState.field === column) {
                        sortState.order = sortState.order === 'ASC' ? 'DESC' : 'ASC';
                    } else {
                        sortState.field = column;
                        sortState.order = 'ASC';
                    }
                    
                    // Update sort indicators
                    $(`${tableId} th`).find('.fa-sort-up, .fa-sort-down').remove();
                    $(this).find('.fa-sort').remove();
                    
                    const sortIcon = sortState.order === 'ASC' ? 
                        '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
                    $(this).append(sortIcon);
                    
                    // Reload data
                    if (loadFunction === loadDonorsTable) {
                        currentDonorPage = 1;
                        loadDonorsTable();
                    } else {
                        currentTransactionPage = 1;
                        loadTransactions();
                    }
                });
            }
        });
    }
    
    // Initialize sorting when tabs are activated
    $(".tab").click(function() {
        const tabId = $(this).data('tab');
        setTimeout(() => {
            if (tabId === 'donors') {
                makeSortable('#donorsTable', 
                    ['donor_id', 'full_name', 'blood_type', 'contact_number', 'unique_number', 'status'], 
                    donorSort, loadDonorsTable);
            } else if (tabId === 'transactions') {
                makeSortable('#transactionsTable', 
                    ['bt.transaction_id', 'bt.inventory_id', 'bi.blood_type', 'bi.component', 'bt.transaction_type', 'bt.patient_name', 'bt.hospital', 'u.username', 'bt.created_at'], 
                    transactionSort, loadTransactions);
            }
        }, 100);
    });
    
    // Enhanced transaction details function
    function viewTransactionDetails(transactionId) {
        // Show loading message
        $("#transactionDetails").html(`
            <div style="padding: 20px; text-align: center;">
                <i class="fas fa-spinner fa-spin"></i> Loading transaction details...
            </div>
        `);
        transactionModal.style.display = "block";
        
        // Fetch transaction details from server
        $.get(ajaxUrl + `?action=get_transaction_details&transaction_id=${transactionId}`, function(data){
            if (data.success) {
                const transaction = data.transaction;
                
                let detailsHtml = `
                    <div style="padding: 20px;">
                        <h3>Transaction #${transaction.transaction_id}</h3>
                        <div class="transaction-details-grid">
                            <div class="detail-item">
                                <label>Transaction Type:</label>
                                <span class="transaction-type ${transaction.transaction_type === 'IN' ? 'transaction-in' : 'transaction-out'}">
                                    ${transaction.transaction_type}
                                </span>
                            </div>
                            <div class="detail-item">
                                <label>Inventory ID:</label>
                                <span>${transaction.inventory_id || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Blood Type:</label>
                                <span>${transaction.blood_type || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Component:</label>
                                <span>${transaction.component || 'N/A'}</span>
                            </div>
                `;
                
                if (transaction.transaction_type === 'OUT') {
                    detailsHtml += `
                            <div class="detail-item">
                                <label>Patient Name:</label>
                                <span>${transaction.patient_name || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Hospital:</label>
                                <span>${transaction.hospital || 'N/A'}</span>
                            </div>
                    `;
                } else {
                    detailsHtml += `
                            <div class="detail-item">
                                <label>Donor ID:</label>
                                <span>${transaction.donor_id || 'N/A'}</span>
                            </div>
                    `;
                }
                
                detailsHtml += `
                            <div class="detail-item">
                                <label>Processed By:</label>
                                <span>${transaction.processed_by_name || 'System'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Transaction Date:</label>
                                <span>${formatDate(transaction.created_at)}</span>
                            </div>
                `;
                
                if (transaction.notes) {
                    detailsHtml += `
                            <div class="detail-item full-width">
                                <label>Notes:</label>
                                <div style="background: #f5f5f5; padding: 10px; border-radius: 5px; margin-top: 5px;">
                                    ${transaction.notes}
                                </div>
                            </div>
                    `;
                }
                
                detailsHtml += `
                        </div>
                    </div>
                `;
                
                $("#transactionDetails").html(detailsHtml);
            } else {
                $("#transactionDetails").html(`
                    <div style="padding: 20px; text-align: center; color: red;">
                        <i class="fas fa-exclamation-triangle"></i> Error loading transaction details
                    </div>
                `);
            }
        }, "json").fail(function(xhr, status, error) {
            console.error("Error fetching transaction details:", error);
            $("#transactionDetails").html(`
                <div style="padding: 20px; text-align: center; color: red;">
                    <i class="fas fa-exclamation-triangle"></i> Error connecting to server
                </div>
            `);
        });
    }
    
    function loadDashboardStats(){
        $.get(ajaxUrl + "?action=stats", function(data){
            $("#total-units").text(data.total_units || 0);
            $("#available-units").text(data.available_units || 0);
            $("#expiring-units").text(data.expiring_units || 0);
            $("#critical-units").text(data.critical_units || 0);
        }, "json").fail(function(xhr, status, error) {
            console.error("Error loading dashboard stats:", error);
        });
    }
    
    function formatDate(dateString) {
        if(!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }
    
    function showAlert(containerId, type, message) {
        const alert = $("#" + containerId);
        alert.removeClass("alert-success alert-error");
        alert.addClass(type === "success" ? "alert-success" : "alert-error");
        alert.text(message).fadeIn();
    }
    
    function clearAlerts() {
        $(".alert").hide().removeClass("alert-success alert-error").text("");
        $("#addBloodForm")[0].reset();
        $("#requestBloodForm")[0].reset();
    }
    
    function clearDonorAlerts() {
        $("#donorAlert").hide().removeClass("alert-success alert-error").text("");
        $("#addDonorForm")[0].reset();
    }
    
    // Add inventory refresh button function - NEW
    function addInventoryRefreshButton() {
        if ($("#refreshInventoryBtn").length === 0) {
            
            $("#refreshInventoryBtn").click(function() {
                // Reset all filters
                $("#filterBloodType").val('');
                $("#filterComponent").val('');
                $("#filterStatus").val('');
                loadInventory();
                loadDashboardStats();
            });
        }
    }
    
    // Add transaction refresh button function - NEW
    function addTransactionRefreshButton() {
        if ($("#refreshTransactionsBtn").length === 0) {
            $("#transactions-tab .filter-section").append('<button class="btn btn-outline" id="refreshTransactionsBtn"><i class="fas fa-sync-alt"></i> Refresh</button>');
            
            $("#refreshTransactionsBtn").click(function() {
                // Reset search state
                transactionSearchState = { search: '', type: '' };
                $("#transactionSearch").val('');
                $("#transactionTypeFilter").val('');
                currentTransactionPage = 1;
                transactionSort = {field: 'bt.created_at', order: 'DESC'};
                
                // Reset sort indicators
                $("#transactionsTable th").find('.fa-sort-up, .fa-sort-down').remove();
                
                loadTransactions();
            });
        }
    }
    
    // Initialize refresh buttons on page load
    addInventoryRefreshButton();
});
</script>
</body>
</html>