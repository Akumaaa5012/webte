<?php
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

require_method("GET");
$conn = connect_db();

$user = require_role("logistics");

$providerIds = current_provider_ids($conn, $user);

if (count($providerIds) === 0) {
    json_error("No logistics provider found for this account.", 404);
}

$where = [];
$types = "";
$params = [];

$placeholders = implode(",", array_fill(0, count($providerIds), "?"));
$where[] = "d.provider_id IN ($placeholders)";
$types .= str_repeat("i", count($providerIds));
$params = $providerIds;

if (isset($_GET["status"]) && $_GET["status"] !== "") {
    if (!is_string($_GET["status"]) ||
        !in_array($_GET["status"], DELIVERY_STATUSES, true)) {
        json_error("status must be one of: " . implode(", ", DELIVERY_STATUSES) . ".", 400);
    }

    $where[] = "d.status = ?";
    $types .= "s";
    $params[] = $_GET["status"];
}

$sql = "SELECT d.*, o.status AS order_status, o.farm_id, o.restaurant_id,
               lp.provider_name, lp.provider_type,
               r.res_name AS restaurant_name, r.res_address AS restaurant_address,
               f.farm_name
        FROM deliveries d
        JOIN orders o ON o.order_id = d.order_id
        LEFT JOIN logistics_providers lp ON lp.provider_id = d.provider_id
        LEFT JOIN restaurants r ON r.restaurant_id = o.restaurant_id
        LEFT JOIN farms f ON f.farm_id = o.farm_id";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY d.delivery_id DESC";

try {
    $stmt = $conn->prepare($sql);

    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json_response($rows);
} catch (mysqli_sql_exception $e) {
    json_error("Could not load deliveries.", 500);
}
