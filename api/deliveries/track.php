<?php
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

if ($method !== "GET" && $method !== "PATCH") {
    header("Allow: GET, PATCH");
    json_error("Method not allowed.", 405);
}

$conn = connect_db();

try {
    if ($method === "GET") {
        // GET track.php?id=1   or   GET track.php?order_id=2
        $id = positive_id($_GET["id"] ?? null);
        $orderId = positive_id($_GET["order_id"] ?? null);

        if ($id === null && $orderId === null) {
            json_error("Provide a valid id or order_id.", 400);
        }

        $column = $id !== null ? "d.delivery_id" : "d.order_id";
        $value = $id ?? $orderId;

        $stmt = $conn->prepare(
            "SELECT d.delivery_id, d.order_id, d.status,
                    d.delivery_lat, d.delivery_lng, d.estimated_arrival,
                    d.pickup_at, d.delivered_at,
                    lp.provider_name, lp.contact_no AS provider_contact
             FROM deliveries d
             LEFT JOIN logistics_providers lp ON lp.provider_id = d.provider_id
             WHERE $column = ?"
        );
        $stmt->bind_param("i", $value);
        $stmt->execute();
        $tracking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$tracking) {
            json_error("Delivery not found.", 404);
        }

        json_response($tracking);
    }

    // PATCH track.php?id=1 -> the driver or app reports a new position
    $id = positive_id($_GET["id"] ?? null);

    if ($id === null) {
        json_error("Valid delivery ID required.", 400);
    }

    $data = read_json_body();

    [$lat, $lng, $coordError] = read_coordinates($data, true);

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

    $stmt = $conn->prepare("SELECT status FROM deliveries WHERE delivery_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$delivery) {
        json_error("Delivery not found.", 404);
    }

    if (!in_array($delivery["status"], TRACKABLE_STATUSES, true)) {
        json_error(
            "Position cannot be updated while the delivery is {$delivery["status"]}.",
            409
        );
    }

    // estimated_arrival is left as is when not sent
    $update = $conn->prepare(
        "UPDATE deliveries
         SET delivery_lat = ?, delivery_lng = ?,
             estimated_arrival = COALESCE(?, estimated_arrival)
         WHERE delivery_id = ?"
    );
    $update->bind_param("ddsi", $lat, $lng, $estimatedArrival, $id);
    $update->execute();
    $update->close();

    json_response([
        "success" => true,
        "message" => "Position updated successfully.",
        "delivery_id" => $id,
        "delivery_lat" => $lat,
        "delivery_lng" => $lng
    ]);
} catch (mysqli_sql_exception $e) {
    json_error("Could not process tracking request.", 500);
}