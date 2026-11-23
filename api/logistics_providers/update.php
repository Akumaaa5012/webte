<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/validate_provider.php";

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);
    echo json_encode(["error" => "PUT method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(["error" => "Valid provider ID required"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON body"]);
    exit;
}

// full update: user_id and farm_id must be present
$result = validate_provider_input($data, true);

if ($result["error"] !== null) {
    http_response_code(422);
    echo json_encode(["error" => $result["error"]]);
    exit;
}

$v = $result["values"];

try {
    $stmt = $conn->prepare(
        "UPDATE logistics_providers
         SET user_id = ?, farm_id = ?, provider_name = ?,
             provider_type = ?, contact_no = ?
         WHERE provider_id = ?"
    );
    $stmt->bind_param(
        "iisssi",
        $v["user_id"],
        $v["farm_id"],
        $v["provider_name"],
        $v["provider_type"],
        $v["contact_no"],
        $id
    );

    $stmt->execute();

    $check = $conn->prepare(
        "SELECT provider_id FROM logistics_providers WHERE provider_id = ?"
    );
    $check->bind_param("i", $id);
    $check->execute();

    if (!$check->get_result()->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(["error" => "Logistics provider not found"]);
    } else {
        echo json_encode(["message" => "Logistics provider updated successfully"]);
    }

    $check->close();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1452) { // foreign key failure
        http_response_code(422);
        echo json_encode(["error" => "user_id or farm_id does not exist"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not update logistics provider"]);
    }
}