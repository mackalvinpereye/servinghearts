<?php
require 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$title = $_POST['title'];
$description = $_POST['description'];
$amount = $_POST['amount'];

// Generate reference code
$reference_code = "BUDG-" . date("Y") . "-" . str_pad(rand(1,9999), 4, "0", STR_PAD_LEFT);

$sql = "INSERT INTO budget_requests (user_id, title, description, amount, reference_code) 
        VALUES (?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $title, $description, $amount, $reference_code]);

header("Location: my_requests.php?success=1");
exit;
?>
