<?php
require_once __DIR__ . "/../common.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header("Content-Type: application/json; charset=utf-8");

const MAX_DECIMAL = 99999999.99; // limit of DECIMAL(10,2)

if ($_SERVER["REQUEST_METHOD"] !== "PATCH") {
    http_response_code(405);
    echo json_encode(["error" => "PATCH method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(["error" => "Valid product ID required"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON body"]);
    exit;
}

// stock_kg  = set the stock to an exact amount
// adjust_kg = add (positive) or remove (negative) from the current stock
$hasSet = array_key_exists("stock_kg", $data);
$hasAdjust = array_key_exists("adjust_kg", $data);

if ($hasSet === $hasAdjust) {
    http_response_code(422);
    echo json_encode(["error" => "Send exactly one of stock_kg or adjust_kg"]);
    exit;
}

$value = $hasSet ? $data["stock_kg"] : $data["adjust_kg"];

if (!is_numeric($value)) {
    http_response_code(422);
    echo json_encode(["error" => "Value must be a number"]);
    exit;
}

$value = (float) $value;

if ($hasSet && ($value < 0 || $value > MAX_DECIMAL)) {
    http_response_code(422);
    echo json_encode(["error" => "stock_kg must be between 0 and " . MAX_DECIMAL]);
    exit;
}

if ($hasAdjust && ($value == 0 || abs($value) > MAX_DECIMAL)) {
    http_response_code(422);
    echo json_encode(["error" => "adjust_kg must be a non-zero amount"]);
    exit;
}

require_owned_product($conn, $id);

try {
    if ($hasSet) {
        $stmt = $conn->prepare(
            "UPDATE products SET stock_kg = ? WHERE product_id = ?"
        );
        $stmt->bind_param("di", $value, $id);
    } else {
        // WHERE clause stops stock from going below zero
        $stmt = $conn->prepare(
            "UPDATE products SET stock_kg = stock_kg + ?
             WHERE product_id = ? AND stock_kg + ? >= 0"
        );
        $stmt->bind_param("did", $value, $id, $value);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $check = $conn->prepare(
        "SELECT stock_kg FROM products WHERE product_id = ?"
    );
    $check->bind_param("i", $id);
    $check->execute();
    $product = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$product) {
        http_response_code(404);
        echo json_encode(["error" => "Product not found"]);
    } elseif ($hasAdjust && $affected === 0) {
        http_response_code(409);
        echo json_encode([
            "error" => "Not enough stock for this adjustment",
            "stock_kg" => $product["stock_kg"]
        ]);
    } else {
        echo json_encode([
            "message" => "Stock updated successfully",
            "product_id" => $id,
            "stock_kg" => $product["stock_kg"]
        ]);
    }
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1264) { // value out of range for DECIMAL(10,2)
        http_response_code(422);
        echo json_encode(["error" => "Resulting stock is too large"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Could not update stock"]);
    }
}