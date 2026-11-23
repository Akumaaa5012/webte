<?php
/*
 * PUT or POST /api/orders/update.php?id=5
 * Edits an order and/or changes its status. Send only what changes.
 * Body (all optional, at least one):
 *   "requested_date": "2026-10-30"                 pending orders only; not for schedule orders
 *   "delivery_lat": 16.41, "delivery_lng": 120.59  pending orders only; send both as null to remove
 *   "status": "confirmed", "remarks": "optional note"
 * Items are changed with items.php.
 *
 * Status changes are recorded in order_status_log. Allowed (ORDER_STATUS_TRANSITIONS in helpers.php):
 *   pending    -> confirmed, rejected, cancelled
 *   confirmed  -> in_transit, cancelled
 *   in_transit -> delivered
 * Field edits are applied before the status change, so one call can edit and confirm.
 *
 * Authorization:
 *   - Restaurants can only cancel their own pending orders
 *   - Farmers can use any allowed status transition for their farm's orders
 *
 * 200 {"success": true, "order": {...}, "history": [...]}
 * 404 order not found, 409 order no longer editable or status change not allowed, 422 bad input
 */

require_once __DIR__ . "/../helpers.php";
define("LOAD_ORDER_FUNCTIONS_ONLY", true);
require_once __DIR__ . "/create.php";

const MAX_REMARKS_LENGTH = 255;

// Accept both PUT and POST for backward compatibility with existing frontend
if (!in_array($_SERVER["REQUEST_METHOD"], ["PUT", "POST"], true)) {
    header("Allow: PUT, POST");
    json_error("Method not allowed.", 405);
}

// Support id in query parameter or body for backward compatibility
$order_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$order_id || $order_id < 1) {
    $body = read_json_body();
    $order_id = filter_var($body["id"] ?? $body["order_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$order_id || $order_id < 1) {
        json_error("Valid order ID required.", 400);
    }
}

$conn = connect_db();
$user = require_login();
$body = read_json_body();
$errors = [];

$edits_date = array_key_exists("requested_date", $body);
$edits_location = array_key_exists("delivery_lat", $body) || array_key_exists("delivery_lng", $body);
$changes_status = array_key_exists("status", $body);

// Ignore seller-specific fields that don't exist in the orders table
// (logistics_provider, pickup_schedule, estimated_arrival are for logistics module, not implemented yet)
unset($body["logistics_provider"], $body["pickup_schedule"], $body["estimated_arrival"]);

if (!$edits_date && !$edits_location && !$changes_status) {
    json_error("Nothing to update. Send requested_date, delivery_lat/delivery_lng or status.", 422);
}

if ($edits_date) {
    $date_error = requested_date_error($body["requested_date"]);
    if ($date_error !== null) {
        $errors["requested_date"] = $date_error;
    }
}

if ($edits_location) {
    if (!array_key_exists("delivery_lat", $body) || !array_key_exists("delivery_lng", $body)) {
        $errors["delivery_location"] = "Send delivery_lat and delivery_lng together.";
    } else {
        [$delivery_lat, $delivery_lng] = validate_delivery_location($body["delivery_lat"], $body["delivery_lng"], $errors);
    }
}

$status = $body["status"] ?? null;
if ($changes_status && !is_valid_order_status($status)) {
    $errors["status"] = "Must be one of: " . implode(", ", array_keys(ORDER_STATUS_TRANSITIONS)) . ".";
}

$remarks = $body["remarks"] ?? null;
if ($remarks !== null && !$changes_status) {
    $errors["remarks"] = "Only used together with status.";
} elseif ($remarks !== null && !is_string($remarks)) {
    $errors["remarks"] = "Must be text.";
} elseif ($remarks !== null) {
    $remarks = trim($remarks);
    if (mb_strlen($remarks) > MAX_REMARKS_LENGTH) {
        $errors["remarks"] = "At most " . MAX_REMARKS_LENGTH . " characters.";
    }
    if ($remarks === "") {
        $remarks = null;
    }
}

if ($errors) {
    json_error("Validation failed.", 422, $errors);
}

$conn = connect_db();

$conn->begin_transaction();
try {
    // Lock the row so two people changing the same order cannot both pass the checks below.
    $order = lock_order($conn, $order_id);
    if (!$order) {
        $conn->rollback();
        json_error("Order not found.", 404);
    }

    require_order_access($conn, $user, $order);
    $current = $order["status"];

    if (($edits_date || $edits_location) && $current !== "pending") {
        $conn->rollback();
        json_error("Only pending orders can be edited. This order is $current.", 409);
    }

    if ($edits_date && $body["requested_date"] !== $order["requested_date"]) {
        if ($order["schedule_id"] !== null) {
            $conn->rollback();
            json_error("This order was generated from schedule #{$order["schedule_id"]}; its date follows the schedule.", 409);
        }
        $stmt = $conn->prepare("UPDATE orders SET requested_date = ? WHERE order_id = ?");
        $stmt->bind_param("si", $body["requested_date"], $order_id);
        $stmt->execute();
        $stmt->close();
    }

    if ($edits_location) {
        if ($delivery_lat === null) {
            $stmt = $conn->prepare("DELETE FROM deliveries WHERE order_id = ?");
            $stmt->bind_param("i", $order_id);
        } else {
            // deliveries has one row per order (uq_delivery_order).
            $stmt = $conn->prepare(
                "INSERT INTO deliveries (order_id, delivery_lat, delivery_lng) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE delivery_lat = VALUES(delivery_lat), delivery_lng = VALUES(delivery_lng)"
            );
            $stmt->bind_param("idd", $order_id, $delivery_lat, $delivery_lng);
        }
        $stmt->execute();
        $stmt->close();
    }

    if ($changes_status && $status !== $current) {
        $allowed = ORDER_STATUS_TRANSITIONS[$current] ?? [];
        if (!in_array($status, $allowed, true)) {
            $conn->rollback();
            $message = $allowed
                ? "Cannot change a $current order to $status. Allowed: " . implode(", ", $allowed) . "."
                : "Cannot change a $current order to $status. It is already final.";
            json_error($message, 409);
        }

        // Role-based authorization: restaurants can only cancel, farmers can use any allowed transition
        if ($user["role"] === "restaurant" && $status !== "cancelled") {
            $conn->rollback();
            json_error("Restaurants can only cancel orders.", 403);
        }

        // Restore stock when cancelling or rejecting an order (but not for delivered orders)
        if (($status === "cancelled" || $status === "rejected") && $current !== "cancelled" && $current !== "rejected") {
            restore_stock($conn, $order_id);
        }

        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
        $stmt->bind_param("si", $status, $order_id);
        $stmt->execute();
        $stmt->close();

        log_order_status($conn, $order_id, $status, $remarks ?? "Status changed from $current to $status.");
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_error("Could not update order.", 500);
}

json_response([
    "success" => true,
    "order" => load_order($conn, $order_id),
    "history" => load_order_history($conn, $order_id),
]);
