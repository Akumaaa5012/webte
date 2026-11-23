<?php
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

require_method("PATCH");
$conn = connect_db();

$id = positive_id($_GET["id"] ?? null);

if ($id === null) {
    json_error("Valid delivery ID required.", 400);
}

$data = read_json_body();
$providerId = positive_id($data["provider_id"] ?? null);

if ($providerId === null) {
    json_error("A valid provider_id is required.", 422);
}

try {
    $stmt = $conn->prepare(
        "SELECT d.status, o.farm_id, o.status AS order_status
         FROM deliveries d
         JOIN orders o ON o.order_id = d.order_id
         WHERE d.delivery_id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$delivery) {
        json_error("Delivery not found.", 404);
    }

    // the provider can be set or changed until the parcel is picked up
    if (!in_array($delivery["status"], ["pending", "assigned"], true)) {
        json_error(
            "The provider can only be changed before pickup (current status: {$delivery["status"]}).",
            409
        );
    }

    if ($delivery["order_status"] !== DELIVERABLE_ORDER_STATUS) {
        json_error(
            "The order is {$delivery["order_status"]}, so a provider can no longer be assigned.",
            409
        );
    }

    $providerError = provider_error_for_order(
        $conn, $providerId, (int) $delivery["farm_id"]
    );

    if ($providerError !== null) {
        json_error($providerError, 422);
    }

    $update = $conn->prepare(
        "UPDATE deliveries SET provider_id = ?, status = 'assigned'
         WHERE delivery_id = ?"
    );
    $update->bind_param("ii", $providerId, $id);
    $update->execute();
    $update->close();

    json_response([
        "success" => true,
        "message" => "Provider assigned successfully.",
        "delivery_id" => $id,
        "provider_id" => $providerId,
        "status" => "assigned"
    ]);
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1452) {
        json_error("provider_id does not exist.", 422);
    }

    json_error("Could not assign provider.", 500);
}