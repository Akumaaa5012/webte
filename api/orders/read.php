<?php
/*
 * GET /api/orders/read.php?id=5
 * One order with restaurant/farm names, items, delivery point and status history.
 *
 * 200 {"success": true, "order": {..., "items": [...]}, "history": [...]}
 * 404 order not found
 */

require_once __DIR__ . "/../helpers.php";
define("LOAD_ORDER_FUNCTIONS_ONLY", true);
require_once __DIR__ . "/create.php";

require_method("GET");
$order_id = require_order_id_param();
$conn = connect_db();
$user = require_login();

try {
    $order = load_order($conn, $order_id);
    if ($order === null) {
        json_error("Order not found.", 404);
    }

    require_order_access($conn, $user, $order);

    $stmt = $conn->prepare(
        "SELECT r.res_name AS restaurant_name, f.farm_name
         FROM restaurants r, farms f
         WHERE r.restaurant_id = ? AND f.farm_id = ?"
    );
    $stmt->bind_param("ii", $order["restaurant_id"], $order["farm_id"]);
    $stmt->execute();
    $order += $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();

    // Load delivery information if it exists
    $stmt = $conn->prepare(
        "SELECT d.delivery_id, d.provider_id, d.status AS delivery_status,
                lp.provider_name, lp.provider_type
         FROM deliveries d
         LEFT JOIN logistics_providers lp ON lp.provider_id = d.provider_id
         WHERE d.order_id = ?"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($delivery) {
        $order["delivery"] = $delivery;
    }

    $history = load_order_history($conn, $order_id);
} catch (mysqli_sql_exception $e) {
    json_error("Could not load order.", 500);
}

json_response(["success" => true, "order" => $order, "history" => $history]);
