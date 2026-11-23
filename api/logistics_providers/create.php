<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/validate_provider.php";

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

$result = validate_provider_input($data);

if ($result["error"] !== null) {
    http_response_code(422);
    echo json_encode(["error" => $result["error"]]);
    exit;
}

$v = $result["values"];

try {
    $stmt = $conn->prepare(
        "INSERT INTO logistics_providers
         (user_id, farm_id, provider_name, provider_type, contact_no)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "iisss",
        $v["user_id"],
        $v["farm_id"],
        $v["provider_name"],
        $v["provider_type"],
        $v["contact_no"]
    );

    $stmt->execute();

    http_response_code(201);
    echo json_encode([
        "message" => "Logistics provider created successfully",
        "provider_id" => $conn->insert_id
    ]);

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1452) { // foreign key failure
        http_response_code(422);
        echo json_encode(["error" => "user_id or farm_id does not exist"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not create logistics provider"]);
    }
}

