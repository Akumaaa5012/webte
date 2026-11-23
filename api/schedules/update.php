<?php
/*
 * PUT /api/schedules/update.php?id=1
 * Changes a schedule. Send only the fields to change; the rest keep their saved values.
 * Body (all optional): frequency, preferred_day, preferred_time, start_date, end_date, status,
 *   items: [{"product_id": 1, "quantity_kg": 5}, ...]   replaces ALL items when sent
 * restaurant_id and farm_id cannot change. next_delivery_date is recalculated.
 *
 * Status changes: active <-> paused, active/paused -> cancelled. Cancelled schedules cannot be changed.
 *
 * 200 {"success": true, "schedule": {...}}
 * 404 not found, 409 cancelled schedule or status change not allowed, 422 bad input
 */

require_once __DIR__ . "/functions.php";

require_method("PUT");
$schedule_id = require_id_param();
$user = require_login();
$conn = connect_db();
$body = read_json_body();

$conn->begin_transaction();
try {
    $current = lock_schedule($conn, $schedule_id);
    if ($current === null) {
        $conn->rollback();
        json_error("Schedule not found.", 404);
    }
    if ($current["status"] === "cancelled") {
        $conn->rollback();
        json_error("This schedule is cancelled and can no longer be changed.", 409);
    }

    // Enforce ownership: restaurant users can only update their own schedules
    if ($user["role"] === "restaurant") {
        $restaurantId = current_restaurant_id($conn, $user);
        if ($restaurantId <= 0 || (int) $current["restaurant_id"] !== $restaurantId) {
            $conn->rollback();
            json_error("Schedule not found.", 404);
        }
    }

    // Farmers can only update schedules for their farms
    if ($user["role"] === "farmer") {
        $farmIds = current_farm_ids($conn, $user);
        if (!in_array((int) $current["farm_id"], $farmIds, true)) {
            $conn->rollback();
            json_error("Schedule not found.", 404);
        }
    }

    $new_status = $body["status"] ?? $current["status"];
    if ($new_status !== $current["status"] && is_string($new_status)
        && isset(SCHEDULE_STATUS_TRANSITIONS[$new_status])
        && !in_array($new_status, SCHEDULE_STATUS_TRANSITIONS[$current["status"]], true)) {
        $conn->rollback();
        json_error("Cannot change a {$current["status"]} schedule to $new_status.", 409);
    }

    $data = validate_schedule_fields($body, $current);
    check_schedule_references($conn, $data);

    // Skip delivery dates that already have an order (see generate.php).
    $data["schedule_id"] = $schedule_id;
    $data["next_delivery_date"] = next_open_delivery_date($conn, $data) ?? $data["next_delivery_date"];

    update_schedule_row($conn, $schedule_id, $data);
    if ($data["items"] !== null) {
        replace_schedule_items($conn, $schedule_id, $data["items"]);
    }

    $conn->commit();
} catch (ScheduleValidationException $e) {
    $conn->rollback();
    json_error($e->getMessage(), 422, $e->errors);
} catch (Throwable $e) {
    $conn->rollback();
    json_error("Could not update schedule.", 500);
}

json_response(["success" => true, "schedule" => load_schedule($conn, $schedule_id)]);
