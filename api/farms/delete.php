<?php
require_once __DIR__ . "/../db.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "DELETE") {
    http_response_code(405);
    echo json_encode(["error" => "DELETE method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(["error" => "Valid farm ID required"]);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM farms WHERE farm_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Farm not found"]);
    } else {
        echo json_encode(["message" => "Farm deleted successfully"]);
    }

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1451) {
        http_response_code(409);
        echo json_encode([
            "error" => "Farm cannot be deleted because it is referenced by products, orders, schedules, or logistics providers."
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not delete farm"]);
    }
}
