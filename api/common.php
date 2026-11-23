<?php

require_once __DIR__ . "/db.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");

function json_response($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function json_ok($data = [], $status = 200)
{
    json_response(array_merge(["ok" => true], $data), $status);
}

function json_error($message, $status = 400, $extra = [])
{
    json_response(array_merge(["ok" => false, "error" => $message], $extra), $status);
}

function request_method()
{
    return strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
}

function require_method($allowed)
{
    $allowed = (array) $allowed;
    if (!in_array(request_method(), $allowed, true)) {
        json_error("Method not allowed.", 405);
    }
}

function read_json_body()
{
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === "") {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error("Invalid JSON body.");
    }

    return $data;
}

function str_input($source, $key, $max = 255)
{
    if (!isset($source[$key])) {
        return "";
    }

    $value = trim((string) $source[$key]);
    if ($max > 0 && strlen($value) > $max) {
        $value = substr($value, 0, $max);
    }

    return $value;
}

function int_input($source, $key)
{
    if (!isset($source[$key]) || $source[$key] === "") {
        return 0;
    }

    return (int) $source[$key];
}

function query_id()
{
    return isset($_GET["id"]) ? (int) $_GET["id"] : 0;
}

function fetch_categories($conn, $id = 0)
{
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT category_id, name FROM categories WHERE category_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    }

    if (!$result) {
        json_error("Could not load categories.", 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $categoryId = (int) $row["category_id"];
        $rows[] = [
            "id" => $categoryId,
            "category_id" => $categoryId,
            "name" => $row["name"]
        ];
    }

    return $rows;
}

function fetch_farms($conn, $id = 0)
{
    if ($id > 0) {
        $stmt = $conn->prepare(
            "SELECT farm_id, user_id, farm_name, owner_name, contact_no, address
             FROM farms
             WHERE farm_id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query(
            "SELECT farm_id, user_id, farm_name, owner_name, contact_no, address
             FROM farms
             ORDER BY farm_name ASC"
        );
    }

    if (!$result) {
        json_error("Could not load farms.", 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $farmId = (int) $row["farm_id"];
        $rows[] = [
            "id" => $farmId,
            "farm_id" => $farmId,
            "user_id" => (int) $row["user_id"],
            "name" => $row["farm_name"],
            "farm_name" => $row["farm_name"],
            "owner_name" => $row["owner_name"],
            "contact_no" => $row["contact_no"],
            "address" => $row["address"]
        ];
    }

    return $rows;
}

function fetch_restaurants($conn, $id = 0)
{
    if ($id > 0) {
        $stmt = $conn->prepare(
            "SELECT restaurant_id, user_id, res_name, res_owner_name, res_contact_no, res_address
             FROM restaurants
             WHERE restaurant_id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query(
            "SELECT restaurant_id, user_id, res_name, res_owner_name, res_contact_no, res_address
             FROM restaurants
             ORDER BY res_name ASC"
        );
    }

    if (!$result) {
        json_error("Could not load restaurants.", 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $restaurantId = (int) $row["restaurant_id"];
        $rows[] = [
            "id" => $restaurantId,
            "restaurant_id" => $restaurantId,
            "user_id" => (int) $row["user_id"],
            "name" => $row["res_name"],
            "res_name" => $row["res_name"],
            "res_owner_name" => $row["res_owner_name"],
            "res_contact_no" => $row["res_contact_no"],
            "res_address" => $row["res_address"]
        ];
    }

    return $rows;
}

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

function require_login()
{
    $user = current_user();
    if (!$user) {
        json_error("Please log in.", 401);
    }
    return $user;
}

function require_role($roles)
{
    $user = require_login();
    $roles = (array) $roles;
    if (!in_array($user["role"], $roles, true)) {
        json_error("You do not have access to this resource.", 403);
    }
    return $user;
}

function portal_for_role($role)
{
    if ($role === "farmer") {
        return "seller";
    }
    if ($role === "restaurant") {
        return "buyer";
    }
    if ($role === "logistics") {
        return "logistics";
    }
    return $role;
}

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
    return $ids;
}

function current_restaurant_id($conn, $user)
{
    $userId = (int) $user["user_id"];
    $stmt = $conn->prepare("SELECT restaurant_id FROM restaurants WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? (int) $row["restaurant_id"] : 0;
}

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
    return $ids;
}

function make_uuid()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($bytes), 4));
}

function product_photo_url($photo)
{
    $photo = is_string($photo) ? trim($photo) : "";
    if ($photo === "") {
        return "";
    }
    if (preg_match('#^(https?:|data:|/)#i', $photo)) {
        return $photo;
    }
    return "../" . ltrim($photo, "/");
}

function require_owned_farm($conn, $farmId)
{
    $user = require_role("farmer");
    $farmId = (int) $farmId;
    if ($farmId <= 0 || !in_array($farmId, current_farm_ids($conn, $user), true)) {
        json_error("You can only manage products for your own farm.", 403);
    }
    return $user;
}

function require_owned_product($conn, $productId)
{
    $user = require_role("farmer");
    $productId = (int) $productId;
    $stmt = $conn->prepare("SELECT product_id, farm_id FROM products WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_error("Product not found.", 404);
    }
    if (!in_array((int) $row["farm_id"], current_farm_ids($conn, $user), true)) {
        json_error("You can only manage products for your own farm.", 403);
    }

    return $user;
}

function product_select_sql()
{
    return "SELECT p.product_id, p.farm_id, p.category_id, p.name, p.price_per_kg,
                   p.min_order_kg, p.stock_kg, p.harvest_date, p.photo, p.updated_at,
                   f.farm_name, f.address AS farm_address, c.name AS category_name
            FROM products p
            INNER JOIN farms f ON f.farm_id = p.farm_id
            INNER JOIN categories c ON c.category_id = p.category_id";
}

function format_product_row($row)
{
    $id = (int) $row["product_id"];
    $price = (float) $row["price_per_kg"];
    $stock = (float) $row["stock_kg"];
    $minOrder = (float) $row["min_order_kg"];
    $photo = $row["photo"];

    return [
        "id" => $id,
        "product_id" => $id,
        "farm_id" => (int) $row["farm_id"],
        "category_id" => (int) $row["category_id"],
        "name" => $row["name"],
        "price" => $price,
        "price_per_kg" => $price,
        "currency" => "PHP",
        "unit" => "kg",
        "stock" => $stock,
        "stock_kg" => $stock,
        "stock_quantity" => $stock,
        "min_order_kg" => $minOrder,
        "minimum_order_quantity" => $minOrder,
        "harvest_date" => $row["harvest_date"],
        "photo" => $photo,
        "image_url" => product_photo_url($photo),
        "category_name" => $row["category_name"],
        "category" => $row["category_name"],
        "farm_name" => $row["farm_name"],
        "farm" => $row["farm_name"],
        "location" => $row["farm_address"]
    ];
}

function load_products($conn, $id = 0, $farmIds = null)
{
    $sql = product_select_sql();
    $types = "";
    $params = [];

    if ($id > 0) {
        $sql .= " WHERE p.product_id = ?";
        $types .= "i";
        $params[] = $id;
    } elseif (is_array($farmIds)) {
        if (count($farmIds) === 0) {
            return [];
        }
        $placeholders = implode(",", array_fill(0, count($farmIds), "?"));
        $sql .= " WHERE p.farm_id IN ($placeholders)";
        $types .= str_repeat("i", count($farmIds));
        $params = $farmIds;
    }

    $sql .= " ORDER BY p.name ASC";

    if ($types === "") {
        $result = $conn->query($sql);
        if (!$result) {
            json_error("Could not load products.", 500);
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = format_product_row($row);
        }
        return $rows;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = format_product_row($row);
    }
    return $rows;
}
