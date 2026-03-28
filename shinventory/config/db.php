<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = parse_ini_file(__DIR__ . '/../.env');

$host   = $env['DB_HOST'];
$user   = $env['DB_USER'];
$pass   = $env['DB_PASS'];
$dbname = $env['DB_NAME'];
$port   = (int)$env['DB_PORT'];

$conn = new mysqli($host, $user, $pass, $dbname, $port);

$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}