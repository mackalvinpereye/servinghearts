<?php
session_start();
include('../../config/db.php');

// Check for flash messages from previous request (for PRG pattern)
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        // Add user logic
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number']);
        $messenger = mysqli_real_escape_string($conn, $_POST['messenger']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = mysqli_real_escape_string($conn, $_POST['role']);
        
        // Check if username or email already exists
        $check_sql = "SELECT id FROM users WHERE username='$username' OR email='$email'";
        $check_result = mysqli_query($conn, $check_sql);
        
        if (mysqli_num_rows($check_result) > 0) {
            $_SESSION['error_message'] = "Username or email already exists!";
        } else {
            $sql = "INSERT INTO users (username, email, phone_number, messenger, password, role) 
                    VALUES ('$username', '$email', '$phone_number', '$messenger', '$password', '$role')";
            
            if (mysqli_query($conn, $sql)) {
                $_SESSION['success_message'] = "User added successfully!";
                $log_message = "User " . $_SESSION['username'] . " added new user: " . $username;
                mysqli_query($conn, "INSERT INTO activity_log (user_id, activity) VALUES (" . $_SESSION['user_id'] . ", '$log_message')");
            } else {
                $_SESSION['error_message'] = "Error adding user: " . mysqli_error($conn);
            }
        }
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    if (isset($_POST['edit_user'])) {
        // Edit user logic
        $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number']);
        $messenger = mysqli_real_escape_string($conn, $_POST['messenger']);
        $role = mysqli_real_escape_string($conn, $_POST['role']);
        
        // Check if username or email already exists (excluding current user)
        $check_sql = "SELECT id FROM users WHERE (username='$username' OR email='$email') AND id != '$user_id'";
        $check_result = mysqli_query($conn, $check_sql);
        
        if (mysqli_num_rows($check_result) > 0) {
            $_SESSION['error_message'] = "Username or email already exists!";
        } else {
            $sql = "UPDATE users SET username='$username', email='$email', phone_number='$phone_number', 
                    messenger='$messenger', role='$role' WHERE id='$user_id'";
            
            if (mysqli_query($conn, $sql)) {
                $_SESSION['success_message'] = "User updated successfully!";
                $log_message = "User " . $_SESSION['username'] . " updated user: " . $username;
                mysqli_query($conn, "INSERT INTO activity_log (user_id, activity) VALUES (" . $_SESSION['user_id'] . ", '$log_message')");
            } else {
                $_SESSION['error_message'] = "Error updating user: " . mysqli_error($conn);
            }
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST['delete_user'])) {
        $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);

        // Prevent deleting yourself
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['error_message'] = "You cannot delete your own account.";
        } else {
            $sql = "DELETE FROM users WHERE id='$user_id'";
            if (mysqli_query($conn, $sql)) {
                $_SESSION['success_message'] = "User deleted successfully!";
                $log_message = "User " . $_SESSION['username'] . " deleted user ID: " . $user_id;
                mysqli_query($conn, "INSERT INTO activity_log (user_id, activity) VALUES (" . $_SESSION['user_id'] . ", '$log_message')");
            } else {
                $_SESSION['error_message'] = "Error deleting user: " . mysqli_error($conn);
            }
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    if (isset($_POST['backup_database'])) {
        // Backup database logic
        $backup_file = '../BACKUP/backup_' . date("Y-m-d_H-i-s") . '.sql';
        $error_log_file = '../BACKUP/backup_error_' . date("Y-m-d_H-i-s") . '.log';

        // Create BACKUP directory if it doesn't exist
        if (!is_dir('../BACKUP')) {
            mkdir('../BACKUP', 0755, true);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $mysqldumpPath = "C:/xampp/mysql/bin/mysqldump.exe";
        } else {
            $mysqldumpPath = "mysqldump";
        }

        $command = "\"$mysqldumpPath\" --user={$user} --password={$pass} --host={$host} {$dbname} > \"$backup_file\" 2> \"$error_log_file\"";

        system($command, $output);

        if ($output === 0) {
            $_SESSION['success_message'] = "Database backup created successfully!";
            $log_message = "User " . $_SESSION['username'] . " created a database backup";
            mysqli_query($conn, "INSERT INTO activity_log (user_id, activity) VALUES (" . $_SESSION['user_id'] . ", '$log_message')");
        } else {
            $_SESSION['error_message'] = "Error creating database backup. Check log file: " . $error_log_file;
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST['restore_database'])) {
        // Restore database logic
        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $backup_file = $_FILES['backup_file']['tmp_name'];
            $error_log_file = '../BACKUP/restore_error_' . date("Y-m-d_H-i-s") . '.log';

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $mysqlPath = "C:/xampp/mysql/bin/mysql.exe";
            } else {
                $mysqlPath = "mysql";
            }

            $command = "\"$mysqlPath\" --user={$user} --password={$pass} --host={$host} {$dbname} < \"$backup_file\" 2> \"$error_log_file\"";

            system($command, $output);

            if ($output === 0) {
                $_SESSION['success_message'] = "Database restored successfully!";
                $log_message = "User " . $_SESSION['username'] . " restored the database from a backup";
                mysqli_query($conn, "INSERT INTO activity_log (user_id, activity) VALUES (" . $_SESSION['user_id'] . ", '$log_message')");
            } else {
                $_SESSION['error_message'] = "Error restoring database. Check log file: " . $error_log_file;
            }
        } else {
            $_SESSION['error_message'] = "Please select a valid backup file";
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get users for edit form
$users_result = mysqli_query($conn, "SELECT id, username, email, phone_number, messenger, role, created_at FROM users");

// Get activity logs
$logs_result = mysqli_query($conn, "SELECT al.activity, al.timestamp, u.username 
                                   FROM activity_log al 
                                   JOIN users u ON al.user_id = u.id 
                                   ORDER BY al.timestamp DESC 
                                   LIMIT 50");

include('../testsidebar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SH | Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        html, body {
            background-color: #ffffff;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
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

        .dashboard-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            color: #e62929;
        }

        .dashboard-title i {
            font-size: 28px;
        }

        .dashboard-title h2 {
            font-size: 24px;
            font-weight: 700;
        }

        .main-container {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .setting-content {
            flex: 2;
            display: flex;
            flex-direction: column;
            gap: 20px;
            min-width: 0;
        }

        .activity-log {
            flex: 1;
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            max-height: 600px;
            overflow-y: auto;
            position: sticky;
            top: 100px;
            min-width: 300px;
        }

        .activity-log h3 {
            color: #e62929;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .setting-card {
            background: #fff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .setting-card h3 {
            color: #e62929;
            margin-top: 0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"],
        input[type="file"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
        }

        input:focus, select:focus {
            border-color: #e62929;
            box-shadow: 0 0 0 3px rgba(230, 41, 41, 0.1);
            outline: none;
        }

        button {
            background: linear-gradient(135deg, #e62929 0%, #b31616 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.3s;
        }

        button:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(230, 41, 41, 0.3);
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: opacity 0.5s ease;
        }

        .alert-success {
            background-color: #e6f7ee;
            color: #0c5460;
            border-left: 4px solid #1e7e34;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .log-entry {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-activity {
            font-weight: 500;
            margin-bottom: 5px;
        }

        .log-meta {
            font-size: 13px;
            color: #777;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .tab-buttons {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .tab-btn {
            padding: 20px 15px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .tab-btn i {
            font-size: 24px;
            margin-bottom: 10px;
            z-index: 2;
        }

        .tab-btn span {
            z-index: 2;
        }

        .tab-btn:nth-child(1) {
            background: linear-gradient(135deg, #e62929, #ff6b6b);
            color: white;
        }

        .tab-btn:nth-child(2) {
            background: linear-gradient(135deg, #f4b700, #ffd145);
            color: white;
        }

        .tab-btn:nth-child(3) {
            background: linear-gradient(135deg, #fd7e14, #ffab5c);
            color: white;
        }

        .tab-btn:nth-child(4) {
            background: linear-gradient(135deg, #05a081, #0bd5b4);
            color: white;
        }

        .tab-btn.active {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }

        .tab-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .backup-info {
            background: #ffeded;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #f74a4a;
        }

        .backup-info h4 {
            margin-bottom: 10px;
            color: #f74a4a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .shape-circle {
            position: absolute;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            top: -20px;
            right: -20px;
            z-index: 1;
        }

        .shape-square {
            position: absolute;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            bottom: -15px;
            left: -15px;
            transform: rotate(45deg);
            z-index: 1;
        }

        .shape-triangle {
            position: absolute;
            width: 0;
            height: 0;
            border-left: 30px solid transparent;
            border-right: 30px solid transparent;
            border-bottom: 50px solid rgba(255, 255, 255, 0.2);
            top: -25px;
            left: 50%;
            transform: translateX(-50%) rotate(45deg);
            z-index: 1;
        }

        .shape-wave {
            position: absolute;
            width: 70px;
            height: 20px;
            background: rgba(255, 255, 255, 0.2);
            bottom: 10px;
            right: 10px;
            border-radius: 50%;
            transform: rotate(10deg);
            z-index: 1;
        }

        .shape-wave::before,
        .shape-wave::after {
            content: '';
            position: absolute;
            width: 70px;
            height: 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
        }

        .shape-wave::before {
            top: -15px;
            left: 10px;
        }

        .shape-wave::after {
            bottom: -15px;
            right: 10px;
        }

        .user-action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .user-action-buttons button {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            color: #fff;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn-edit {
            background: #28a745;
        }

        .btn-edit:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.3);
        }

        .btn-delete {
            background: #e62929;
        }

        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        .no-activity {
            text-align: center;
            padding: 40px 20px;
            color: #777;
        }

        .no-activity i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }

        /* ===== ENHANCED RESPONSIVE DESIGN ===== */

        /* Large tablets and small desktops (1024px to 1199px) */
        @media (max-width: 1199px) {
            .main-content {
                margin-left: 250px;
                width: calc(100% - 250px);
                padding: 25px;
            }
            
            .tab-buttons {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .main-container {
                gap: 25px;
            }
        }

        /* Tablets (768px to 1023px) */
        @media (max-width: 1023px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
            }
            
            .main-container {
                flex-direction: column;
                gap: 20px;
            }
            
            .setting-content {
                width: 100%;
            }
            
            .activity-log {
                width: 100%;
                max-height: 400px;
                position: static;
            }
            
            .tab-buttons {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .tab-btn {
                min-height: 90px;
                padding: 15px 12px;
            }
            
            .tab-btn i {
                font-size: 22px;
                margin-bottom: 8px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
        }

        /* Large phones (576px to 767px) */
        @media (max-width: 767px) {
            .main-content {
                padding: 15px;
                margin-top: 60px;
            }
            
            .dashboard-title {
                font-size: 1.1rem;
                margin-bottom: 15px;
            }
            
            .dashboard-title i {
                font-size: 24px;
            }
            
            .tab-buttons {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .tab-btn {
                min-height: 80px;
                padding: 15px;
            }
            
            .tab-btn i {
                font-size: 20px;
                margin-bottom: 6px;
            }
            
            .setting-card {
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .activity-log {
                padding: 15px;
                max-height: 350px;
            }
            
            .user-action-buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .user-action-buttons button {
                width: 100%;
            }
            
            .log-meta {
                flex-direction: column;
                gap: 5px;
            }
            
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="tel"],
            input[type="file"],
            select {
                padding: 10px 12px;
                font-size: 14px;
            }
            
            button {
                padding: 10px 20px;
                font-size: 14px;
            }
        }

        /* Small phones (480px to 575px) */
        @media (max-width: 575px) {
            .main-content {
                padding: 12px;
                margin-top: 60px;
            }
            
            .dashboard-title {
                font-size: 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .setting-card {
                padding: 12px;
                margin-bottom: 15px;
                border-radius: 8px;
            }
            
            .activity-log {
                padding: 12px;
                max-height: 300px;
                border-radius: 8px;
            }
            
            .tab-btn {
                min-height: 70px;
                padding: 12px;
            }
            
            .tab-btn span {
                font-size: 14px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
            
            label {
                font-size: 14px;
                margin-bottom: 6px;
            }
            
            .backup-info {
                padding: 12px;
            }
            
            .alert {
                padding: 12px;
                font-size: 14px;
            }
            
            .shape-circle,
            .shape-square,
            .shape-triangle,
            .shape-wave {
                display: none;
            }
        }

        /* Very small phones (479px and below) */
        @media (max-width: 479px) {
            .main-content {
                padding: 10px;
            }
            
            .tab-btn {
                min-height: 60px;
                padding: 10px;
            }
            
            .tab-btn i {
                font-size: 18px;
                margin-bottom: 4px;
            }
            
            .tab-btn span {
                font-size: 13px;
            }
            
            .setting-card h3 {
                font-size: 16px;
                margin-bottom: 15px;
            }
            
            .activity-log h3 {
                font-size: 16px;
            }
            
            .log-activity {
                font-size: 14px;
            }
            
            .log-meta {
                font-size: 12px;
            }
        }

        /* Print styles */
        @media print {
            .main-content {
                margin: 0 !important;
                padding: 20px !important;
                width: 100% !important;
            }
            
            .tab-buttons,
            .user-action-buttons {
                display: none !important;
            }
            
            .activity-log {
                max-height: none;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
                animation: none !important;
            }
            
            .tab-btn:hover,
            .tab-btn.active,
            button:hover {
                transform: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="dashboard-title">
            <i class="fas fa-cog"></i>
            <h2>System Settings</h2>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="main-container">
            <div class="setting-content">
                <div class="tab-buttons">
                    <button class="tab-btn active" onclick="openTab('add-user')">
                        <div class="shape-circle"></div>
                        <div class="shape-square"></div>
                        <i class="fas fa-user-plus"></i>
                        <span>Add User</span>
                    </button>
                    <button class="tab-btn" onclick="openTab('edit-user')">
                        <div class="shape-triangle"></div>
                        <div class="shape-wave"></div>
                        <i class="fas fa-user-edit"></i>
                        <span>Edit User</span>
                    </button>
                    <button class="tab-btn" onclick="openTab('backup-database')">
                        <div class="shape-circle"></div>
                        <div class="shape-wave"></div>
                        <i class="fas fa-database"></i>
                        <span>Backup Database</span>
                    </button>
                    <button class="tab-btn" onclick="openTab('restore-database')">
                        <div class="shape-square"></div>
                        <div class="shape-triangle"></div>
                        <i class="fas fa-undo"></i>
                        <span>Restore Database</span>
                    </button>
                </div>
                
                <div id="add-user" class="tab-content active">
                    <div class="setting-card">
                        <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" required placeholder="Enter username">
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" required placeholder="Enter email">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="phone_number">Phone Number</label>
                                    <input type="tel" id="phone_number" name="phone_number" placeholder="Enter phone number">
                                </div>
                                <div class="form-group">
                                    <label for="messenger">Messenger</label>
                                    <input type="text" id="messenger" name="messenger" placeholder="Enter messenger">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" required placeholder="Enter password">
                                </div>
                                <div class="form-group">
                                    <label for="role">Role</label>
                                    <select id="role" name="role" required>
                                        <option value="user">User</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" name="add_user">
                                <i class="fas fa-plus"></i> Add User
                            </button>
                        </form>
                    </div>
                </div>
                
                <div id="edit-user" class="tab-content">
                    <div class="setting-card">
                        <h3><i class="fas fa-user-edit"></i> Edit User</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label for="user_id">Select User</label>
                                <select id="user_id" name="user_id" required onchange="loadUserData(this.value)">
                                    <option value="">Select a user</option>
                                    <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['email']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_username">Username</label>
                                    <input type="text" id="edit_username" name="username" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_email">Email</label>
                                    <input type="email" id="edit_email" name="email" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_phone_number">Phone Number</label>
                                    <input type="tel" id="edit_phone_number" name="phone_number">
                                </div>
                                <div class="form-group">
                                    <label for="edit_messenger">Messenger ID</label>
                                    <input type="text" id="edit_messenger" name="messenger">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_role">Role</label>
                                    <select id="edit_role" name="role" required>
                                        <option value="user">User</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_created_at">Created At</label>
                                    <input type="text" id="edit_created_at" name="created_at" readonly style="background-color: #f5f5f5;">
                                </div>
                            </div>
                            
                            <div class="user-action-buttons">
                                <button type="submit" name="edit_user" class="btn-edit">
                                    <i class="fas fa-save"></i> Update User
                                </button>

                                <button type="submit" name="delete_user" class="btn-delete"
                                    onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                    <i class="fas fa-trash"></i> Delete User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div id="backup-database" class="tab-content">
                    <div class="setting-card">
                        <h3><i class="fas fa-database"></i> Backup Database</h3>
                        
                        <div class="backup-info">
                            <h4><i class="fas fa-info-circle"></i> Backup Information</h4>
                            <p>This will create a complete backup of your database. The backup will be saved in the backups folder and can be restored if needed.</p>
                            <p><strong>Last backup:</strong> Never</p>
                        </div>
                        
                        <form method="POST">
                            <button type="submit" name="backup_database">
                                <i class="fas fa-download"></i> Create Backup Now
                            </button>
                        </form>
                    </div>
                </div>
                
                <div id="restore-database" class="tab-content">
                    <div class="setting-card">
                        <h3><i class="fas fa-undo"></i> Restore Database</h3>
                        
                        <div class="backup-info">
                            <h4><i class="fas fa-info-circle"></i> Restore Information</h4>
                            <p>This will restore your database from a backup file. Please select a valid SQL backup file to restore.</p>
                            <p><strong>Warning:</strong> This action will overwrite your current database. Make sure you have a recent backup before proceeding.</p>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="backup_file">Select Backup File</label>
                                <input type="file" id="backup_file" name="backup_file" accept=".sql" required>
                            </div>
                            
                            <button type="submit" name="restore_database">
                                <i class="fas fa-upload"></i> Restore Database
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="activity-log">
                <h3><i class="fas fa-history"></i> Recent Activity</h3>
                <?php if (mysqli_num_rows($logs_result) > 0): ?>
                    <?php while ($log = mysqli_fetch_assoc($logs_result)): ?>
                        <div class="log-entry">
                            <div class="log-activity"><?php echo htmlspecialchars($log['activity']); ?></div>
                            <div class="log-meta">
                                <span>By <?php echo htmlspecialchars($log['username']); ?></span>
                                <span><?php echo date('M j, Y g:i A', strtotime($log['timestamp'])); ?></span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-activity">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No activity logs found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openTab(tabName) {
            var tabContents = document.getElementsByClassName('tab-content');
            for (var i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            var tabButtons = document.getElementsByClassName('tab-btn');
            for (var i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }
            
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }
        
        function loadUserData(userId) {
            if (userId) {
                // In a real implementation, you would fetch this data via AJAX from PHP
                // For now, we'll use a simple approach - you can enhance this later
                console.log("Loading data for user ID: " + userId);
                // This would typically make an AJAX call to get user data
            }
        }

        // Auto-hide success/error alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 500); 
                }, 5000);
            });
        });
    </script>
</body>
</html>