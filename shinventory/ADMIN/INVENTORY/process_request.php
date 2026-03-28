<?php
session_start();
include('../../config/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Function to calculate expiration date based on component
function calculate_expiration_date($collection_date, $component) {
    $collection_timestamp = strtotime($collection_date);  // Convert collection date to timestamp

    // Expiration logic
    switch ($component) {
        case 'RBC':
            return date('Y-m-d', strtotime("+42 days", $collection_timestamp));  // 42 days for RBC
        case 'Plasma':
            return date('Y-m-d', strtotime("+365 days", $collection_timestamp)); // 1 year for Plasma
        case 'Platelets':
            return date('Y-m-d', strtotime("+5 days", $collection_timestamp));   // 5 days for Platelets
        case 'Cryoprecipitate':
            return date('Y-m-d', strtotime("+365 days", $collection_timestamp)); // 1 year for Cryoprecipitate
        default:
            return date('Y-m-d', strtotime("+35 days", $collection_timestamp)); // Default is 35 days for Whole Blood
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id']);
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];

    // Get transaction details, including component from the blood_inventory table
    $sql = "SELECT t.inventory_id, t.quantity, bi.component 
            FROM blood_transactions t 
            JOIN blood_inventory bi ON t.inventory_id = bi.inventory_id 
            WHERE t.transaction_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $transaction_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $inventory_id = $result['inventory_id'];
    $quantity = $result['quantity'];
    $requested_component = $result['component']; // Fetch the component from blood_inventory

    if ($action === 'approve') {
        // Check if the requested component is a component (not whole blood)
        if ($requested_component !== 'Whole Blood') {
            // Check if this inventory is split from a whole blood unit
            $sql = "SELECT * FROM blood_inventory WHERE inventory_id = ? AND split_from IS NOT NULL AND status = 'available'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $inventory_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Try to find the original whole blood unit
                $sql = "SELECT * FROM blood_inventory WHERE inventory_id = (SELECT split_from FROM blood_inventory WHERE inventory_id = ?) AND component = 'Whole Blood' AND status = 'available'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $inventory_id);
                $stmt->execute();
                $whole_blood_result = $stmt->get_result();
                
                if ($whole_blood_result->num_rows === 0) {
                    $_SESSION['message'] = "Original whole blood unit is no longer available for splitting.";
                    $_SESSION['message_type'] = "error";
                    header("Location: index.php");
                    exit();
                }

                // Fetch the whole blood details from the original unit
                $whole_blood = $whole_blood_result->fetch_assoc();
                $donor_id = $whole_blood['donor_id'];
                $blood_type = $whole_blood['blood_type'];
                $collection_date = $whole_blood['collection_date'];

                // Calculate expiration date for the requested component
                $expiration_date = calculate_expiration_date($collection_date, $requested_component);

                // Check if the requested component already exists in the inventory
                $sqlCheck = "SELECT * FROM blood_inventory WHERE component = ? AND split_from = ? AND status IN ('available', 'pending')";
                $stmtCheck = $conn->prepare($sqlCheck);
                $stmtCheck->bind_param("si", $requested_component, $whole_blood['inventory_id']);
                $stmtCheck->execute();
                $checkResult = $stmtCheck->get_result();

                if ($checkResult->num_rows === 0) {
                    // Create the requested component with status 'out_of_stock'
                    $sql = "INSERT INTO blood_inventory (donor_id, blood_type, component, collection_date, expiration_date, status, split_from)
                            VALUES (?, ?, ?, ?, ?, 'out_of_stock', ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssi", $donor_id, $blood_type, $requested_component, $collection_date, $expiration_date, $whole_blood['inventory_id']);
                    $stmt->execute();

                    // Get the new inventory_id for the split component (e.g., Plasma, RBC)
                    $new_inventory_id = $stmt->insert_id;
                }

                // Now create the other components (Plasma, Platelets, Cryoprecipitate) that are not requested
                $components = ['RBC', 'Plasma', 'Platelets', 'Cryoprecipitate'];
                foreach ($components as $component) {
                    if ($component !== $requested_component) {
                        // Check if the non-requested component exists in inventory
                        $sqlCheck = "SELECT * FROM blood_inventory WHERE component = ? AND split_from = ? AND status = 'available'";
                        $stmtCheck = $conn->prepare($sqlCheck);
                        $stmtCheck->bind_param("si", $component, $whole_blood['inventory_id']);
                        $stmtCheck->execute();
                        $checkResult = $stmtCheck->get_result();

                        if ($checkResult->num_rows === 0) {
                            // If the component doesn't exist, create it with status 'available'
                            $expiration_date = calculate_expiration_date($collection_date, $component);
                            $sql = "INSERT INTO blood_inventory (donor_id, blood_type, component, collection_date, expiration_date, status, split_from)
                                    VALUES (?, ?, ?, ?, ?, 'available', ?)";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("sssssi", $donor_id, $blood_type, $component, $collection_date, $expiration_date, $whole_blood['inventory_id']);
                            $stmt->execute();
                        }
                    }
                }

                // **Update the original whole blood record to 'out_of_stock' immediately after the split**
                $sql = "UPDATE blood_inventory SET status = 'out_of_stock' WHERE inventory_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $whole_blood['inventory_id']);
                $stmt->execute();

            } else {
                // If component is already split, we approve it normally
                $sql = "UPDATE blood_inventory SET status = 'out_of_stock' WHERE inventory_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $inventory_id);
                $stmt->execute();
            }

            // **Ensure the requested component is marked 'out_of_stock' after approval**
            $sql = "UPDATE blood_inventory SET status = 'out_of_stock' WHERE inventory_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $inventory_id);
            $stmt->execute();

            // Log the transaction for the new component
            $sqlTr = "UPDATE blood_transactions SET transaction_type = 'approved_out', created_by = ? WHERE transaction_id = ?";
            $stmt = $conn->prepare($sqlTr);
            $stmt->bind_param("ii", $admin_id, $transaction_id);
            $stmt->execute();
            
            // Log to approvals table
            $approvalSql = "INSERT INTO approvals (transaction_id, approved_by, approval_status, approved_at) 
                            VALUES (?, ?, 'approved', NOW())";
            $approvalStmt = $conn->prepare($approvalSql);
            $approvalStmt->bind_param("ii", $transaction_id, $admin_id);
            $approvalStmt->execute();
            
            $_SESSION['message'] = "Request approved and component split successfully.";
            $_SESSION['message_type'] = "success";
        } else {
            // Regular approval for whole blood
            // Update inventory status (mark as out)
            $sqlInv = "UPDATE blood_inventory SET status = 'out_of_stock' WHERE inventory_id = ?";
            $stmt2 = $conn->prepare($sqlInv);
            $stmt2->bind_param("i", $inventory_id);
            $stmt2->execute();

            // Update transaction to approved
            $sqlTr = "UPDATE blood_transactions SET transaction_type = 'approved_out', created_by = ? WHERE transaction_id = ?";
            $stmt3 = $conn->prepare($sqlTr);
            $stmt3->bind_param("ii", $admin_id, $transaction_id);
            $stmt3->execute();
            
            // Log to approvals table
            $approvalSql = "INSERT INTO approvals (transaction_id, approved_by, approval_status, approved_at) 
                            VALUES (?, ?, 'approved', NOW())";
            $approvalStmt = $conn->prepare($approvalSql);
            $approvalStmt->bind_param("ii", $transaction_id, $admin_id);
            $approvalStmt->execute();
            
            $_SESSION['message'] = "Request approved successfully.";
            $_SESSION['message_type'] = "success";
        }
    } else {
        // Reject transaction
        $sqlTr = "UPDATE blood_transactions SET transaction_type = 'rejected_out' WHERE transaction_id = ?";
        $stmt3 = $conn->prepare($sqlTr);
        $stmt3->bind_param("i", $transaction_id);
        $stmt3->execute();

        // Reset inventory status to available
        $sqlInv = "UPDATE blood_inventory SET status = 'available' WHERE inventory_id = ?";
        $stmt4 = $conn->prepare($sqlInv);
        $stmt4->bind_param("i", $inventory_id);
        $stmt4->execute();
        
        // Log to approvals table
        $approvalSql = "INSERT INTO approvals (transaction_id, approved_by, approval_status, approved_at) 
                        VALUES (?, ?, 'rejected', NOW())";
        $approvalStmt = $conn->prepare($approvalSql);
        $approvalStmt->bind_param("ii", $transaction_id, $admin_id);
        $approvalStmt->execute();
        
        $_SESSION['message'] = "Request rejected successfully.";
        $_SESSION['message_type'] = "success";
    }
}

header("Location: index.php");
exit();
