<?php
session_start();
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/config.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if (password_verify($password, $row['password'])) {
            // store session data
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];

            // separate redirects
            if ($row['role'] === 'admin') {
                header("Location: " . $baseURL . "ADMIN/index.php");
                exit();
            }

            if ($row['role'] === 'user') {
                header("Location: " . $baseURL . "USER/index.php");
                exit();
            }

            // fallback if role is neither admin nor user
            header("Location: index.php?error=role");
            exit();
        } else {
            // wrong password
            header("Location: index.php?error=invalid");
            exit();
        }
    } else {
        // no user found
        header("Location: index.php?error=nouser");
        exit();
    }

    $stmt->close();
}
$conn->close();
