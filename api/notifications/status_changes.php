<?php
/*
 * GET /api/notifications/status_changes.php
 * Order status-change events, read from order_status_log. The first "pending" entry written
 * when an order is created is not a change, so it is left out (new orders have their own endpoint).
 *
 * Parameters (all optional):
 *   after_id=30        only log entries with log_id > 30, oldest first. Send the last_id from
 *                      the previous response to get just what is new.
 *                      Without it, the newest `limit` changes are returned.
 *   limit=20           1-100
 *   farm_id=1          only orders of this farm
 *   restaurant_id=1    only this restaurant's orders (e.g. tell it when its order is confirmed)
 *   status=confirmed,cancelled   only changes to these statuses
 *
 * 200 {"success": true, "events": [{"type": "order_status_changed", "event_id": 30,
 *      "occurred_at": "...", "message": "...", "order_id": 2, "status": "confirmed",
 *      "previous_status": "pending", "remarks": "...", ...}], "last_id": 30, "has_more": false}
 * 422 bad parameter
 */

require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/notification_helpers.php";

require_method("GET");
$user = require_login();
$params = read_event_params();

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

$where = ["l.status <> 'pending'"];
$types = "";
$bind = [];

// Enforce ownership based on user role
$conn = connect_db();
if ($user["role"] === "farmer") {
    // Farmers can only see their own farms' status changes
    $userFarmIds = current_farm_ids($conn, $user);
    if (empty($userFarmIds)) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
    $where[] = "o.farm_id IN (" . implode(",", array_fill(0, count($userFarmIds), "?")) . ")";
    $types .= str_repeat("i", count($userFarmIds));
    $bind = array_merge($bind, $userFarmIds);
} elseif ($user["role"] === "restaurant") {
    // Restaurants can only see their own order status changes
    $userRestaurantId = current_restaurant_id($conn, $user);
    if (!$userRestaurantId) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
    $where[] = "o.restaurant_id = ?";
    $types .= "i";
    $bind[] = $userRestaurantId;
} elseif ($user["role"] === "logistics") {
    // Logistics providers can only see status changes for deliveries assigned to them
    $userProviderIds = current_provider_ids($conn, $user);
    if (empty($userProviderIds)) {
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
    $where[] = "EXISTS (
        SELECT 1 FROM deliveries d
        WHERE d.order_id = l.order_id
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
    if (count($where) === 1) { // Only has the "l.status <> 'pending'" filter
        $conn->close();
        send_event_list([], $params["after_id"], false);
    }
} else {
    // Unknown role - deny access
    $conn->close();
    send_event_list([], $params["after_id"], false);
}

if ($statuses) {
    $where[] = "l.status IN (" . implode(",", array_fill(0, count($statuses), "?")) . ")";
    $types .= str_repeat("s", count($statuses));
    array_push($bind, ...$statuses);
}

try {
    [$rows, $has_more] = fetch_event_rows(
        $conn,
        "l.log_id, l.order_id, l.status, l.remarks, l.changed_at,
         (SELECT l2.status FROM order_status_log l2
          WHERE l2.order_id = l.order_id AND l2.log_id < l.log_id
          ORDER BY l2.log_id DESC LIMIT 1) AS previous_status,
         o.restaurant_id, r.res_name AS restaurant_name,
         o.farm_id, f.farm_name",
        "order_status_log l
         JOIN orders o ON o.order_id = l.order_id
         JOIN restaurants r ON r.restaurant_id = o.restaurant_id
         JOIN farms f ON f.farm_id = o.farm_id",
        $where,
        $types,
        $bind,
        "l.log_id",
        $params["after_id"],
        $params["limit"]
    );
} catch (mysqli_sql_exception $e) {
    json_error("Could not load status changes.", 500);
}

$events = array_map(static fn ($row) => [
    "type" => "order_status_changed",
    "event_id" => (int) $row["log_id"],
    "occurred_at" => $row["changed_at"],
    "message" => $row["previous_status"] !== null
        ? sprintf("Order #%d changed from %s to %s.", (int) $row["order_id"], $row["previous_status"], $row["status"])
        : sprintf("Order #%d is now %s.", (int) $row["order_id"], $row["status"]),
    "order_id" => (int) $row["order_id"],
    "status" => $row["status"],
    "previous_status" => $row["previous_status"],
    "remarks" => $row["remarks"],
    "restaurant_id" => (int) $row["restaurant_id"],
    "restaurant_name" => $row["restaurant_name"],
    "farm_id" => (int) $row["farm_id"],
    "farm_name" => $row["farm_name"],
], $rows);

send_event_list($events, $params["after_id"], $has_more);