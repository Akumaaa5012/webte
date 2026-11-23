<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "sproutroute";
$port = 3306;

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli(
    $host,
    $username,
    $password,
    $database,
    $port
);

if ($conn->connect_error) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "ok" => false,
        "error" => "Database connection failed."
    ]);
    exit;
}

$conn->set_charset("utf8mb4");
