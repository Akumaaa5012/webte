<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists("json_response")) {
    function json_response($data, $status = 200)
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($data);
        exit;
    }
}

if (!function_exists("json_error")) {
    function json_error($message, $status = 400, $details = null)
    {
        $body = ["success" => false, "error" => $message];
        if ($details !== null) {
            $body["details"] = $details;
        }
        json_response($body, $status);
    }
}

if (!function_exists("require_method")) {
    function require_method($method)
    {
        if ($_SERVER["REQUEST_METHOD"] !== $method) {
            header("Allow: " . $method);
            json_error("Method not allowed.", 405);
        }
    }
}

if (!function_exists("read_json_body")) {
    function read_json_body()
    {
        $raw = file_get_contents("php://input");
        if ($raw === "" || $raw === false) {
            return $_POST;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            json_error("Request body must be valid JSON.");
        }
        return $data;
    }
}

// db.php handles a failed connection itself but turns mysqli exceptions off;
// turn them back on, since the order code relies on them (duplicate keys, transactions).
function connect_db()
{
    require __DIR__ . "/db.php";
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $conn;
}

if (!function_exists("current_user")) {
    function current_user()
    {
        if (empty($_SESSION["user_id"])) {
            return null;
        }

        return [
            "user_id" => (int) $_SESSION["user_id"],
            "username" => $_SESSION["username"] ?? "",
            "email" => $_SESSION["email"] ?? "",
            "role" => $_SESSION["role"] ?? "",
            "portal" => $_SESSION["portal"] ?? ""
        ];
    }
}

if (!function_exists("require_login")) {
    function require_login()
    {
        $user = current_user();
        if (!$user) {
            json_error("Please log in.", 401);
        }
        return $user;
    }
}

if (!function_exists("require_role")) {
    function require_role($roles)
    {
        $user = require_login();
        $roles = (array) $roles;
        if (!in_array($user["role"], $roles, true)) {
            json_error("You do not have access to this resource.", 403);
        }
        return $user;
    }
}

if (!function_exists("current_farm_ids")) {
    function current_farm_ids($conn, $user)
    {
        $ids = [];
        $userId = (int) $user["user_id"];
        $stmt = $conn->prepare("SELECT farm_id FROM farms WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row["farm_id"];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists("current_restaurant_id")) {
    function current_restaurant_id($conn, $user)
    {
        $userId = (int) $user["user_id"];
        $stmt = $conn->prepare("SELECT restaurant_id FROM restaurants WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row["restaurant_id"] : 0;
    }
}

if (!function_exists("current_provider_ids")) {
    function current_provider_ids($conn, $user)
    {
        $ids = [];
        $userId = (int) $user["user_id"];
        $stmt = $conn->prepare("SELECT provider_id FROM logistics_providers WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row["provider_id"];
        }
        $stmt->close();
        return $ids;
    }
}

function user_can_access_order($conn, $user, $order)
{
    if (!$order || !$user) {
        return false;
    }

    if ($user["role"] === "restaurant") {
        $restaurantId = current_restaurant_id($conn, $user);
        return $restaurantId > 0 && (int) $order["restaurant_id"] === $restaurantId;
    }

    if ($user["role"] === "farmer") {
        return in_array((int) $order["farm_id"], current_farm_ids($conn, $user), true);
    }

    return false;
}

function require_order_access($conn, $user, $order)
{
    if (!user_can_access_order($conn, $user, $order)) {
        json_error("Order not found.", 404);
    }
}

function apply_order_list_scope($conn, $user, array &$where, string &$types, array &$params)
{
    if ($user["role"] === "restaurant") {
        $restaurantId = current_restaurant_id($conn, $user);
        if ($restaurantId <= 0) {
            $where[] = "1 = 0";
            return;
        }
        $where[] = "o.restaurant_id = ?";
        $types .= "i";
        $params[] = $restaurantId;
        return;
    }

    if ($user["role"] === "farmer") {
        $farmIds = current_farm_ids($conn, $user);
        if (count($farmIds) === 0) {
            $where[] = "1 = 0";
            return;
        }
        $placeholders = implode(",", array_fill(0, count($farmIds), "?"));
        $where[] = "o.farm_id IN ($placeholders)";
        $types .= str_repeat("i", count($farmIds));
        array_push($params, ...$farmIds);
        return;
    }

    json_error("You do not have access to this resource.", 403);
}

// WAMP's PHP defaults to UTC; "today" and "now" checks must use local time.
date_default_timezone_set("Asia/Manila");

// Every status an order can have. Keys are statuses, values are the statuses it may change to.
const ORDER_STATUS_TRANSITIONS = [
    "pending" => ["confirmed", "rejected", "cancelled"],
    "confirmed" => ["in_transit", "cancelled"],
    "in_transit" => ["delivered"],
    "delivered" => [],
    "rejected" => [],
    "cancelled" => [],
];

// Largest quantity accepted for one product in an order.
const MAX_QUANTITY_KG = 1000;

// Rough bounding box of the Philippines; delivery points outside it are rejected.
const SERVICE_AREA = ["min_lat" => 4.5, "max_lat" => 21.5, "min_lng" => 116.0, "max_lng" => 127.0];

function is_valid_order_status($value)
{
    return is_string($value) && isset(ORDER_STATUS_TRANSITIONS[$value]);
}

// Deducts stock for ordered products atomically with row locking.
// Called inside a transaction after order_items are inserted.
// Uses UPDATE with WHERE stock_kg >= quantity to prevent negative stock.
// Also marks the order as having stock deducted for reliable restoration tracking.
function deduct_stock($conn, $items, $order_id)
{
    foreach ($items as $product_id => $quantity_kg) {
        $stmt = $conn->prepare(
            "UPDATE products SET stock_kg = stock_kg - ? WHERE product_id = ? AND stock_kg >= ?"
        );
        $stmt->bind_param("ddd", $quantity_kg, $product_id, $quantity_kg);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            // Stock became insufficient between the check and this update (race condition)
            // Fetch product name for better error message
            $nameStmt = $conn->prepare("SELECT name FROM products WHERE product_id = ?");
            $nameStmt->bind_param("i", $product_id);
            $nameStmt->execute();
            $result = $nameStmt->get_result()->fetch_assoc();
            $nameStmt->close();
            $productName = $result ? $result["name"] : "product #$product_id";
            throw new Exception("Insufficient stock for $productName. Another order may have just purchased the last available stock. Please try again.");
        }
        $stmt->close();
    }

    // Mark the order as having stock deducted for reliable restoration tracking
    // Using integer 1 for TRUE to match TINYINT(1) column type
    $stmt = $conn->prepare("UPDATE orders SET stock_deducted = 1 WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
}

// Restores stock for cancelled or rejected orders.
// Called inside a transaction when status changes to cancelled or rejected.
// Uses the order_items to get the exact quantities to restore.
// Only restores stock if stock_deducted = 1 (inventory was actually reserved).
// Marks stock_deducted = 0 after restoration to prevent double restoration.
function restore_stock($conn, $order_id)
{
    // Check if stock was deducted for this order using the stock_deducted flag
    $stmt = $conn->prepare("SELECT stock_deducted FROM orders WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return;
    }

    // Only restore if stock was actually deducted (stock_deducted = 1)
    if ((int) $order["stock_deducted"] !== 1) {
        // Stock was never deducted (historical order or already restored)
        return;
    }

    $stmt = $conn->prepare(
        "SELECT product_id, quantity_kg FROM order_items WHERE order_id = ?"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($items)) {
        return;
    }

    foreach ($items as $item) {
        $product_id = (int) $item["product_id"];
        $quantity_kg = (float) $item["quantity_kg"];

        $stmt = $conn->prepare(
            "UPDATE products SET stock_kg = stock_kg + ? WHERE product_id = ?"
        );
        $stmt->bind_param("di", $quantity_kg, $product_id);
        $stmt->execute();
        $stmt->close();
    }

    // Mark stock as restored to prevent double restoration
    $stmt = $conn->prepare("UPDATE orders SET stock_deducted = 0 WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
}

// Returns an error message, or null when $value is a usable kg amount.
function quantity_kg_error($value)
{
    if (is_bool($value) || !is_numeric($value)) {
        return "Required and must be a number.";
    }
    if ($value <= 0) {
        return "Must be greater than 0.";
    }
    if ($value > MAX_QUANTITY_KG) {
        return "Cannot be more than " . MAX_QUANTITY_KG . " kg.";
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
        return "Use at most 2 decimal places.";
    }
    return null;
}

function is_coordinate($value, $min, $max)
{
    return !is_bool($value) && is_numeric($value) && $value >= $min && $value <= $max;
}

function is_in_service_area($lat, $lng)
{
    return $lat >= SERVICE_AREA["min_lat"] && $lat <= SERVICE_AREA["max_lat"]
        && $lng >= SERVICE_AREA["min_lng"] && $lng <= SERVICE_AREA["max_lng"];
}

function is_positive_int($value)
{
    return filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) !== false;
}

function is_valid_date($value)
{
    $date = DateTime::createFromFormat("Y-m-d", (string) $value);
    return $date !== false && $date->format("Y-m-d") === $value;
}

function is_valid_datetime($value)
{
    $date = DateTime::createFromFormat("Y-m-d H:i:s", (string) $value);
    return $date !== false && $date->format("Y-m-d H:i:s") === $value;
}

function is_valid_uuid($value)
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}

function generate_uuid()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($bytes), 4));
}
