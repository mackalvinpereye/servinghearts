<?php
require_once __DIR__ . "/../config/db.php";

// Step 1: Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully (or already exists).<br>";
} else {
    die("Error creating database: " . $conn->error);
}

// Select the database
$conn->select_db($dbname);

// Step 2: Create users table if not exists
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($sql) === TRUE) {
    echo "Users table created successfully.<br>";
} else {
    die("Error creating table: " . $conn->error);
}

// Step 3: Insert default admin (only if not exists)
$adminUser = "SH-ADMIN01";
$adminPass = password_hash("servinghearts1223334444", PASSWORD_BCRYPT); // secure hash
$role = "admin";

// Check if admin already exists
$check = $conn->query("SELECT * FROM users WHERE username='$adminUser'");
if ($check->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $adminUser, $adminPass, $role);
    if ($stmt->execute()) {
        echo "Admin account created successfully.<br>";
        echo "Username: SH-ADMIN01<br>Password: servinghearts1223334444<br>";
    } else {
        echo "Error creating admin: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Admin account already exists.<br>";
}

$conn->close();
?>
