<?php
require_once __DIR__ . "/../db.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);
    echo json_encode(["error" => "PUT method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$data = json_decode(file_get_contents("php://input"), true);
$name = (is_array($data) && is_string($data["name"] ?? null))
    ? trim($data["name"])
    : "";

if (!$id || $id < 1 || $name === "" || mb_strlen($name) > 50) {
    http_response_code(422);
    echo json_encode([
        "error" => "Valid ID and category name required (max 50 characters)"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare(
        "UPDATE categories SET name = ? WHERE category_id = ?"
    );
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();
    $check = $conn->prepare(
        "SELECT category_id FROM categories WHERE category_id = ?"
    );
    $check->bind_param("i", $id);
    $check->execute();

    if (!$check->get_result()->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found"]);
    } else {
        echo json_encode(["message" => "Category updated successfully"]);
    }

    $check->close();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        http_response_code(409);
        echo json_encode(["error" => "Category name already exists"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not update category"]);
    }
}