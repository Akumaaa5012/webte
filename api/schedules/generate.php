<?php
/*
 * POST /api/schedules/generate.php
 * Creates the order for one delivery date of a recurring schedule, using the schedule's items.
 * Body: {"schedule_id": 1, "date": "2026-10-27"}
 *   date is optional: defaults to the schedule's next delivery date.
 *
 * A schedule gets at most one order per delivery date:
 *   1. an order that already exists for that schedule and date is returned ("duplicate": true)
 *   2. the order's client_uuid is derived from schedule + date, so retries and parallel calls
 *      are the same order to create_order()
 *   3. the database has UNIQUE (schedule_id, requested_date) on orders
 * A cancelled order still counts, so cancelling one delivery skips that date for good.
 *
 * After success next_delivery_date moves to the following delivery date (it never moves back).
 * The order goes through the normal order checks: prices, min order and stock are as of now.
 *
 * 201 {"success": true, "duplicate": false, "order": {...}, "next_delivery_date": "2026-11-03"}
 * 200 same, with "duplicate": true
 * 404 schedule not found
 * 409 schedule is paused or cancelled
 * 422 date is not a delivery date of this schedule, or the order failed its checks (e.g. stock)
 */

require_once __DIR__ . "/functions.php";
define("LOAD_ORDER_FUNCTIONS_ONLY", true);
require_once __DIR__ . "/../orders/create.php";

require_method("POST");
$conn = connect_db();
$body = read_json_body();

$schedule_id = $body["schedule_id"] ?? null;
if (!is_positive_int($schedule_id)) {
    json_error("Validation failed.", 422, ["schedule_id" => "Required and must be a positive integer."]);
}
$schedule_id = (int) $schedule_id;

try {
    $schedule = load_schedule($conn, $schedule_id);
    if ($schedule === null) {
        json_error("Schedule not found.", 404);
    }

    // Default: the first upcoming delivery date that has no order yet.
    $date = $body["date"] ?? next_open_delivery_date($conn, $schedule);
    if ($date === null) {
        json_error("This schedule has no more delivery dates before its end_date.", 409);
    }
    if (!is_string($date) || !is_valid_date($date)) {
        json_error("Validation failed.", 422, ["date" => "Format YYYY-MM-DD."]);
    }

    // Already generated: return it, even if the schedule was paused or the date has passed since.
    $existing_id = find_schedule_order_id($conn, $schedule_id, $date);
    if ($existing_id !== null) {
        respond($conn, $schedule, $date, load_order($conn, $existing_id), true);
    }

    if ($schedule["status"] !== "active") {
        json_error("Only active schedules can generate orders. This schedule is {$schedule["status"]}.", 409);
    }
    if (!is_schedule_occurrence($schedule, $date)) {
        $next = next_open_delivery_date($conn, $schedule);
        json_error("Validation failed.", 422, [
            "date" => "Not an upcoming delivery date of this schedule." . ($next ? " Next one: $next." : ""),
        ]);
    }

    $order_body = [
        "client_uuid" => schedule_occurrence_uuid($schedule_id, $date),
        "restaurant_id" => $schedule["restaurant_id"],
        "farm_id" => $schedule["farm_id"],
        "schedule_id" => $schedule_id,
        "requested_date" => $date,
        "items" => array_map(function ($item) {
            return ["product_id" => $item["product_id"], "quantity_kg" => $item["quantity_kg"]];
        }, $schedule["items"]),
    ];

    try {
        $result = create_order($conn, $order_body, true);
    } catch (OrderValidationException $e) {
        // A parallel call may have created this occurrence in the meantime.
        $existing_id = find_schedule_order_id($conn, $schedule_id, $date);
        if ($existing_id === null) {
            json_error("Could not generate the order.", 422, $e->errors);
        }
        $result = ["order" => load_order($conn, $existing_id), "duplicate" => true];
    }
} catch (mysqli_sql_exception $e) {
    json_error("Could not generate the order.", 500);
}

respond($conn, $schedule, $date, $result["order"], $result["duplicate"]);

// Moves next_delivery_date to the first upcoming delivery date without an order, then responds.
function respond($conn, $schedule, $date, $order, $duplicate)
{
    $next = next_open_delivery_date($conn, $schedule);

    // null means end_date leaves no more deliveries; the column keeps its last date.
    if ($next !== null) {
        $stmt = $conn->prepare("UPDATE recurring_schedules SET next_delivery_date = ? WHERE schedule_id = ?");
        $stmt->bind_param("si", $next, $schedule["schedule_id"]);
        $stmt->execute();
        $stmt->close();
    }

    json_response([
        "success" => true,
        "duplicate" => $duplicate,
        "order" => $order,
        "next_delivery_date" => $next,
    ], $duplicate ? 200 : 201);
}
