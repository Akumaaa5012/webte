<?php
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

require_method("GET");
$conn = connect_db();

$where = [];
$types = "";
$params = [];

$id = null;

if (isset($_GET["id"])) {
    $id = positive_id($_GET["id"]);

    if ($id === null) {
        json_error("Invalid delivery ID.", 400);
    }

    $where[] = "d.delivery_id = ?";
    $types .= "i";
    $params[] = $id;
}

// optional list filters: ?status=in_transit&provider_id=1&order_id=2
if (isset($_GET["status"]) && $_GET["status"] !== "") {
    if (!is_string($_GET["status"]) ||
        !in_array($_GET["status"], DELIVERY_STATUSES, true)) {
        json_error("status must be one of: " . implode(", ", DELIVERY_STATUSES) . ".", 400);
    }

    $where[] = "d.status = ?";
    $types .= "s";
    $params[] = $_GET["status"];
}

foreach (["provider_id" => "d.provider_id", "order_id" => "d.order_id"] as $param => $column) {
    if (isset($_GET[$param]) && $_GET[$param] !== "") {
        $value = positive_id($_GET[$param]);

        if ($value === null) {
            json_error("Invalid $param.", 400);
        }

        $where[] = "$column = ?";
        $types .= "i";
        $params[] = $value;
    }
}

$sql = "SELECT d.*, o.status AS order_status, o.farm_id, o.restaurant_id,
               lp.provider_name, lp.provider_type
        FROM deliveries d
        JOIN orders o ON o.order_id = d.order_id
        LEFT JOIN logistics_providers lp ON lp.provider_id = d.provider_id";

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

    if ($id !== null) {
        $delivery = $result->fetch_assoc();
        $stmt->close();

        if (!$delivery) {
            json_error("Delivery not found.", 404);
        }

        json_response($delivery);
    }

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json_response($rows);
} catch (mysqli_sql_exception $e) {
    json_error("Could not load deliveries.", 500);
}