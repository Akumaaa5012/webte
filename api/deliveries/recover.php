<?php

require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

require_method("POST");
$conn = connect_db();
$user = require_login();
$data = read_json_body();

$orderId = positive_id($data["order_id"] ?? null);
if ($orderId === null) {
    json_error("A valid order_id is required.", 422);
}

try {
    $conn->begin_transaction();

    // Check if order exists and is deliverable
    $stmt = $conn->prepare("SELECT farm_id, status FROM orders WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $conn->rollback();
        json_error("Order not found.", 404);
    }

    // Verify the user owns this farm (only farmers can recover their farm's orders)
    $userFarmIds = current_farm_ids($conn, $user);
    if (!in_array((int) $order["farm_id"], $userFarmIds, true)) {
        $conn->rollback();
        json_error("You do not have permission to recover this order's delivery record.", 403);
    }

    if ($order["status"] !== DELIVERABLE_ORDER_STATUS) {
        $conn->rollback();
        json_error(
            "Order must be in '" . DELIVERABLE_ORDER_STATUS . "' status for delivery recovery (current: {$order["status"]}).",
            409
        );
    }

    // Check if delivery already exists
    $stmt = $conn->prepare("SELECT delivery_id FROM deliveries WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $conn->rollback();
        json_response([
            "success" => true,
            "message" => "Delivery record already exists.",
            "delivery_id" => (int) $existing["delivery_id"]
        ]);
    }

    // Create delivery record without coordinates
    $stmt = $conn->prepare("INSERT INTO deliveries (order_id, status) VALUES (?, 'pending')");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $deliveryId = $conn->insert_id;
    $stmt->close();

    $conn->commit();

    json_response([
        "success" => true,
        "message" => "Delivery record created successfully.",
        "delivery_id" => $deliveryId
    ], 201);
} catch (mysqli_sql_exception $e) {
    $conn->rollback();

    // Handle duplicate key error (should not happen due to check above, but defensive)
    if ((int) $e->getCode() === 1062) {
        json_error("This order already has a delivery record.", 409);
    }

    json_error("Could not create delivery record.", 500);
}
