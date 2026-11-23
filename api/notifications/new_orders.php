<?php
/*
 * GET /api/notifications/new_orders.php
 * New-order events, for example to alert a farm when a restaurant orders from it.
 *
 * Parameters (all optional):
 *   after_id=12        only orders with order_id > 12, oldest first. Send the last_id from
 *                      the previous response to get just what is new.
 *                      Without it, the newest `limit` orders are returned.
 *   limit=20           1-100
 *   farm_id=1          only orders for this farm
 *   restaurant_id=1    only this restaurant's orders
 *   status=pending     only orders with this status (e.g., pending for seller active notifications)
 *                      multiple statuses allowed: status=pending,confirmed
 *
 * 200 {"success": true, "events": [{"type": "new_order", "event_id": 12, "occurred_at": "...",
 *      "message": "...", "order": {...}}], "last_id": 12, "has_more": false}
 * 422 bad parameter
 */

require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/notification_helpers.php";

require_method("GET");
$user = require_login();
$params = read_event_params();

$where = [];
$types = "";
$bind = [];

// Enforce ownership based on user role
$conn = connect_db();
if ($user["role"] === "farmer") {
    // Farmers can only see their own farms' orders
    $userFarmIds = current_farm_ids($conn, $user);
    if (empty($userFarmIds)) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
    $where[] = "o.farm_id IN (" . implode(",", array_fill(0, count($userFarmIds), "?")) . ")";
    $types .= str_repeat("i", count($userFarmIds));
    $bind = array_merge($bind, $userFarmIds);
} elseif ($user["role"] === "restaurant") {
    // Restaurants can only see their own orders
    $userRestaurantId = current_restaurant_id($conn, $user);
    if (!$userRestaurantId) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
    $where[] = "o.restaurant_id = ?";
    $types .= "i";
    $bind[] = $userRestaurantId;
} elseif ($user["role"] === "logistics") {
    // Logistics providers can only see orders for deliveries assigned to them
    $userProviderIds = current_provider_ids($conn, $user);
    if (empty($userProviderIds)) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
    $where[] = "EXISTS (
        SELECT 1 FROM deliveries d
        WHERE d.order_id = o.order_id
        AND d.provider_id IN (" . implode(",", array_fill(0, count($userProviderIds), "?")) . ")
    )";
    $types .= str_repeat("i", count($userProviderIds));
    $bind = array_merge($bind, $userProviderIds);
} elseif ($user["role"] === "admin") {
    // Admin can view specific farms with explicit farm_id or restaurant_id parameter
    if ($params["farm_id"] !== null) {
        $where[] = "o.farm_id = ?";
        $types .= "i";
        $bind[] = $params["farm_id"];
    }
    if ($params["restaurant_id"] !== null) {
        $where[] = "o.restaurant_id = ?";
        $types .= "i";
        $bind[] = $params["restaurant_id"];
    }
    // If no filters provided, return empty for safety
    if (empty($where)) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
} else {
    // Unknown role - deny access
    $conn->close();
    send_event_list([], $params["after_id"], false);
}

$statuses = [];
$status_param = notification_param("status");

if ($status_param === false) {
    json_error("Validation failed.", 422, ["status" => "Must be text."]);
}

if ($status_param !== "") {
    foreach (explode(",", $status_param) as $status) {
        $status = strtolower(trim($status));
        if ($status === "") {
            continue;
        }
        if (!is_valid_order_status($status)) {
            json_error("Validation failed.", 422, [
                "status" => "Unknown status \"$status\". Use: " . implode(", ", array_keys(ORDER_STATUS_TRANSITIONS)) . ".",
            ]);
        }
        $statuses[$status] = true;
    }
    $statuses = array_keys($statuses);
}

if ($statuses) {
    $where[] = "o.status IN (" . implode(",", array_fill(0, count($statuses), "?")) . ")";
    $types .= str_repeat("s", count($statuses));
    array_push($bind, ...$statuses);
}

try {
    [$rows, $has_more] = fetch_event_rows(
        $conn,
        "o.order_id, o.status, o.schedule_id, o.total_amount, o.requested_date, o.ordered_at,
         o.restaurant_id, r.res_name AS restaurant_name,
         o.farm_id, f.farm_name,
         (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count",
        "orders o
         JOIN restaurants r ON r.restaurant_id = o.restaurant_id
         JOIN farms f ON f.farm_id = o.farm_id",
        $where,
        $types,
        $bind,
        "o.order_id",
        $params["after_id"],
        $params["limit"]
    );
} catch (mysqli_sql_exception $e) {
    json_error("Could not load new orders.", 500);
}

$events = array_map(static fn ($row) => [
    "type" => "new_order",
    "event_id" => (int) $row["order_id"],
    "occurred_at" => $row["ordered_at"],
    "message" => sprintf(
        "New order #%d from %s (PHP %s).",
        (int) $row["order_id"],
        $row["restaurant_name"],
        number_format((float) $row["total_amount"], 2)
    ),
    "order" => [
        "order_id" => (int) $row["order_id"],
        "status" => $row["status"],
        "schedule_id" => $row["schedule_id"] !== null ? (int) $row["schedule_id"] : null,
        "restaurant_id" => (int) $row["restaurant_id"],
        "restaurant_name" => $row["restaurant_name"],
        "farm_id" => (int) $row["farm_id"],
        "farm_name" => $row["farm_name"],
        "total_amount" => (float) $row["total_amount"],
        "requested_date" => $row["requested_date"],
        "ordered_at" => $row["ordered_at"],
        "item_count" => (int) $row["item_count"],
    ],
], $rows);

send_event_list($events, $params["after_id"], $has_more);