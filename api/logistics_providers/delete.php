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
    echo json_encode(["error" => "Valid provider ID required"]);
    exit;
}

try {
    $stmt = $conn->prepare(
        "DELETE FROM logistics_providers WHERE provider_id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Logistics provider not found"]);
    } else {
        echo json_encode(["message" => "Logistics provider deleted successfully"]);
    }

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1451) { // deliveries reference providers
        http_response_code(409);
        echo json_encode([
            "error" => "Provider cannot be deleted because it is assigned to deliveries."
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not delete logistics provider"]);
    }
}
