<?php
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

require_method("POST");
$conn = connect_db();
$data = read_json_body();

$orderId = positive_id($data["order_id"] ?? null);

if ($orderId === null) {
    json_error("A valid order_id is required.", 422);
}

// optional: assign a provider right away
$providerId = null;

if (($data["provider_id"] ?? null) !== null && $data["provider_id"] !== "") {
    $providerId = positive_id($data["provider_id"]);

    if ($providerId === null) {
        json_error("provider_id must be a positive integer.", 422);
    }
}

// optional: last known position and estimated arrival
[$lat, $lng, $coordError] = read_coordinates($data, false);

if ($coordError !== null) {
    json_error($coordError, 422);
}

$estimatedArrival = $data["estimated_arrival"] ?? null;

if ($estimatedArrival === "") {
    $estimatedArrival = null;
}

if ($estimatedArrival !== null &&
    (!is_string($estimatedArrival) || !is_valid_datetime($estimatedArrival))) {
    json_error("estimated_arrival must be formatted YYYY-MM-DD HH:MM:SS.", 422);
}

try {
    $stmt = $conn->prepare("SELECT farm_id, status FROM orders WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        json_error("order_id does not exist.", 422);
    }

    if ($order["status"] !== DELIVERABLE_ORDER_STATUS) {
        json_error(
            "Only " . DELIVERABLE_ORDER_STATUS . " orders can get a delivery (this order is {$order["status"]}).",
            409
        );
    }

    if ($providerId !== null) {
        $providerError = provider_error_for_order(
            $conn, $providerId, (int) $order["farm_id"]
        );

        if ($providerError !== null) {
            json_error($providerError, 422);
        }
    }

    $status = $providerId !== null ? "assigned" : "pending";

    $insert = $conn->prepare(
        "INSERT INTO deliveries
         (order_id, provider_id, delivery_lat, delivery_lng,
          estimated_arrival, status)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $insert->bind_param(
        "iiddss",
        $orderId,
        $providerId,
        $lat,
        $lng,
        $estimatedArrival,
        $status
    );
    $insert->execute();
    $deliveryId = $conn->insert_id;
    $insert->close();

    json_response([
        "success" => true,
        "message" => "Delivery created successfully.",
        "delivery_id" => $deliveryId,
        "status" => $status
    ], 201);
} catch (mysqli_sql_exception $e) {
    $code = (int) $e->getCode();

    if ($code === 1062) { // unique key uq_delivery_order
        $find = $conn->prepare("SELECT delivery_id FROM deliveries WHERE order_id = ?");
        $find->bind_param("i", $orderId);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();

        json_error("This order already has a delivery record.", 409, [
            "delivery_id" => $existing["delivery_id"] ?? null
        ]);
    } elseif ($code === 1452) {
        json_error("order_id or provider_id does not exist.", 422);
    } else {
        json_error("Could not create delivery.", 500);
    }
}