<?php

require_once __DIR__ . "/../helpers.php";
define("LOAD_ORDER_FUNCTIONS_ONLY", true);
require_once __DIR__ . "/create.php";

const MAX_ORDERS_PER_SYNC = 50;

require_method("POST");
$conn = connect_db();
$user = require_login();
$body = read_json_body();

$orders = $body["orders"] ?? null;
if (!is_array($orders) || !array_is_list($orders)) {
    json_error("Body must be {\"orders\": [...]}.", 422);
}
if (count($orders) > MAX_ORDERS_PER_SYNC) {
    json_error("Send at most " . MAX_ORDERS_PER_SYNC . " orders per request.", 413);
}

$results = [];
foreach ($orders as $order) {
    $client_uuid = is_array($order) ? ($order["client_uuid"] ?? null) : null;

    try {
        // Verify the queued order belongs to the authenticated user
        $queuedUserId = $order["user_id"] ?? null;
        if ($queuedUserId !== null && (int) $queuedUserId !== (int) $user["user_id"]) {
            throw new OrderValidationException(["user_id" => "This order was created by a different user and cannot be submitted by your account."]);
        }

        $result = create_order($conn, $order, $user, true);
        $results[] = [
            "client_uuid" => $client_uuid,
            "status" => $result["duplicate"] ? "duplicate" : "created",
            "order" => $result["order"],
        ];
    } catch (OrderValidationException $e) {
        $results[] = ["client_uuid" => $client_uuid, "status" => "rejected", "errors" => $e->errors];
    } catch (mysqli_sql_exception $e) {
        $results[] = ["client_uuid" => $client_uuid, "status" => "error"];
    }
}

json_response(["success" => true, "results" => $results]);
