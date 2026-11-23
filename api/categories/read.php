<?php
require_once __DIR__ . "/../db.php";
header("Content-Type: application/json; charset=utf-8");

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (isset($_GET["id"]) && (!$id || $id < 1)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid category ID"]);
    exit;
}

if ($id) {
    $stmt = $conn->prepare(
        "SELECT * FROM categories WHERE category_id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc();

    if (!$category) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found"]);
    } else {
        echo json_encode($category);
    }

    $stmt->close();
} else {
    $result = $conn->query("SELECT * FROM categories ORDER BY name ASC");
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
}
