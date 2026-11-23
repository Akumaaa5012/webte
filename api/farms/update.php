<?php
require_once __DIR__ . "/../db.php";
header("Content-Type: application/json; charset=utf-8");

function clean_text($value, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    return ($value === "" || mb_strlen($value) > $max) ? null : $value;
}

if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);
    echo json_encode(["error" => "PUT method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$data = json_decode(file_get_contents("php://input"), true);

if (!$id || $id < 1 || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Valid farm ID and JSON body required"]);
    exit;
}

$fields = [
    "farm_name" => 100,
    "owner_name" => 100,
    "contact_no" => 15,
    "address" => 255
];

$values = [];

foreach ($fields as $field => $max) {
    $values[$field] = clean_text($data[$field] ?? null, $max);

    if ($values[$field] === null) {
        http_response_code(422);
        echo json_encode([
            "error" => "$field is required (max $max characters)"
        ]);
        exit;
    }
}

try {
    $stmt = $conn->prepare(
        "UPDATE farms SET farm_name = ?, owner_name = ?,
         contact_no = ?, address = ? WHERE farm_id = ?"
    );
    $stmt->bind_param(
        "ssssi",
        $values["farm_name"],
        $values["owner_name"],
        $values["contact_no"],
        $values["address"],
        $id
    );

    $stmt->execute();
    $check = $conn->prepare("SELECT farm_id FROM farms WHERE farm_id = ?");
    $check->bind_param("i", $id);
    $check->execute();

    if (!$check->get_result()->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(["error" => "Farm not found"]);
    } else {
        echo json_encode(["message" => "Farm updated successfully"]);
    }

    $check->close();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Could not update farm"]);
}
