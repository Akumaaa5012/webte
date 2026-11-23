<?php
/*
 * GET /api/schedules/read.php?id=1
 * 200 {"success": true, "schedule": {..., "restaurant_name", "farm_name", "items": [...]}}
 * 404 schedule not found
 * 403 not authorized (user does not own this schedule)
 */

require_once __DIR__ . "/functions.php";

require_method("GET");
$schedule_id = require_id_param();
$user = require_login();
$conn = connect_db();

try {
    $schedule = load_schedule($conn, $schedule_id);
} catch (mysqli_sql_exception $e) {
    json_error("Could not load schedule.", 500);
}

if ($schedule === null) {
    json_error("Schedule not found.", 404);
}

// Enforce ownership: restaurant users can only read their own schedules
if ($user["role"] === "restaurant") {
    $restaurantId = current_restaurant_id($conn, $user);
    if ($restaurantId <= 0 || (int) $schedule["restaurant_id"] !== $restaurantId) {
        json_error("Schedule not found.", 404);
    }
}

// Farmers can only read schedules for their farms
if ($user["role"] === "farmer") {
    $farmIds = current_farm_ids($conn, $user);
    if (!in_array((int) $schedule["farm_id"], $farmIds, true)) {
        json_error("Schedule not found.", 404);
    }
}

json_response(["success" => true, "schedule" => $schedule]);
