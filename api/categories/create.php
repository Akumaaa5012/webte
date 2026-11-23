<?php
require_once __DIR__ . "/../db.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "POST method required"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON body"]);
    exit;
}

$name = is_string($data["name"] ?? null) ? trim($data["name"]) : "";

if ($name === "" || mb_strlen($name) > 50) {
    http_response_code(422);
    echo json_encode([
        "error" => "Category name is required (max 50 characters)"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();

    http_response_code(201);
    echo json_encode([
        "message" => "Category created successfully",
        "category_id" => $conn->insert_id
    ]);

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        http_response_code(409);
        echo json_encode(["error" => "Category already exists"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not create category"]);
    }
}