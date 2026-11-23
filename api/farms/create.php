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

$fields = [
    "farm_name" => 100,
    "owner_name" => 100,
    "contact_no" => 15,
    "address" => 255
];

$user_id = filter_var($data["user_id"] ?? null, FILTER_VALIDATE_INT);

if (!$user_id || $user_id < 1) {
    http_response_code(422);
    echo json_encode(["error" => "A valid user_id is required"]);
    exit;
}

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
        "INSERT INTO farms (user_id, farm_name, owner_name, contact_no, address)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "issss",
        $user_id,
        $values["farm_name"],
        $values["owner_name"],
        $values["contact_no"],
        $values["address"]
    );

    $stmt->execute();

    http_response_code(201);
    echo json_encode([
        "message" => "Farm created successfully",
        "farm_id" => $conn->insert_id
    ]);

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $code = (int) $e->getCode();

    if ($code === 1062) {
        http_response_code(409);
        echo json_encode(["error" => "This user already has a farm"]);
    } elseif ($code === 1452) {
        http_response_code(422);
        echo json_encode(["error" => "user_id does not exist"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not create farm"]);
    }
}
