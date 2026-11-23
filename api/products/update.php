<?php
require_once __DIR__ . "/../common.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header("Content-Type: application/json; charset=utf-8");

const MAX_DECIMAL = 99999999.99;

function valid_amount($value, bool $allowZero): bool
{
    if (!is_numeric($value)) {
        return false;
    }
    $n = (float) $value;
    return $n <= MAX_DECIMAL && ($allowZero ? $n >= 0 : $n > 0);
}

if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);
    echo json_encode(["error" => "PUT method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(["error" => "Valid product ID required"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$fields = [
    "farm_id", "category_id", "name", "price_per_kg",
    "min_order_kg", "stock_kg", "harvest_date", "photo"
];

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON body"]);
    exit;
}

foreach ($fields as $field) {
    if (!array_key_exists($field, $data)) {
        http_response_code(422);
        echo json_encode(["error" => "$field is required"]);
        exit;
    }
}

$farm_id = filter_var($data["farm_id"], FILTER_VALIDATE_INT);
$category_id = filter_var($data["category_id"], FILTER_VALIDATE_INT);
$name = is_string($data["name"]) ? trim($data["name"]) : "";
$price_per_kg = $data["price_per_kg"];
$min_order_kg = $data["min_order_kg"];
$stock_kg = $data["stock_kg"];
$harvest_date = $data["harvest_date"];
$photo = $data["photo"];

if (!$farm_id || $farm_id < 1 || !$category_id || $category_id < 1 ||
    $name === "" || mb_strlen($name) > 100) {
    http_response_code(422);
    echo json_encode(["error" => "Invalid product details"]);
    exit;
}

if (!valid_amount($price_per_kg, false) ||
    !valid_amount($min_order_kg, false) ||
    !valid_amount($stock_kg, true)) {
    http_response_code(422);
    echo json_encode(["error" => "Invalid price_per_kg, min_order_kg or stock_kg"]);
    exit;
}

if ($photo !== null && (!is_string($photo) || mb_strlen($photo) > 255)) {
    http_response_code(422);
    echo json_encode(["error" => "photo must be a string up to 255 characters"]);
    exit;
}

if ($photo === "") {
    $photo = null;
}

$date = is_string($harvest_date)
    ? DateTime::createFromFormat("!Y-m-d", $harvest_date)
    : false;

if (!$date || $date->format("Y-m-d") !== $harvest_date) {
    http_response_code(422);
    echo json_encode(["error" => "Invalid harvest_date; use YYYY-MM-DD"]);
    exit;
}

$price_per_kg = (float) $price_per_kg;
$min_order_kg = (float) $min_order_kg;
$stock_kg = (float) $stock_kg;

require_owned_product($conn, $id);
require_owned_farm($conn, $farm_id);

try {
    $stmt = $conn->prepare(
        "UPDATE products SET
            farm_id = ?, category_id = ?, name = ?,
            price_per_kg = ?, min_order_kg = ?, stock_kg = ?,
            harvest_date = ?, photo = ?
         WHERE product_id = ?"
    );

    $stmt->bind_param(
        "iisdddssi",
        $farm_id,
        $category_id,
        $name,
        $price_per_kg,
        $min_order_kg,
        $stock_kg,
        $harvest_date,
        $photo,
        $id
    );

    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $check = $conn->prepare(
            "SELECT product_id FROM products WHERE product_id = ?"
        );
        $check->bind_param("i", $id);
        $check->execute();

        if (!$check->get_result()->fetch_assoc()) {
            http_response_code(404);
            echo json_encode(["error" => "Product not found"]);
        } else {
            echo json_encode(["message" => "No changes made"]);
        }

        $check->close();
    } else {
        echo json_encode(["message" => "Product updated successfully"]);
    }

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1452) {
        http_response_code(422);
        echo json_encode(["error" => "farm_id or category_id does not exist"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not update product"]);
    }
}
