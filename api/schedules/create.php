<?php
/*
 *
 * Creates a recurring schedule with its items. The schedule and items are saved together or not at all.
 * Body: {
 *   "restaurant_id": 1, "farm_id": 1,
 *   "frequency": "weekly",            daily, weekly, biweekly or monthly
 *   "preferred_day": "Monday",        weekday for weekly/biweekly, 1-28 for monthly, omit for daily
 *   "preferred_time": "07:00",
 *   "start_date": "2026-11-02", "end_date": null,
 *   "items": [{"product_id": 1, "quantity_kg": 5}]
 * }
 * next_delivery_date is calculated by the server. New schedules are "active".
 *
 * 201 {"success": true, "schedule": {..., "items": [...]}}
 * 422 {"success": false, "error": "Validation failed.", "details": {...}}
 */

require_once __DIR__ . "/functions.php";

require_method("POST");
$conn = connect_db();
$body = read_json_body();

try {
    $data = validate_schedule_fields($body);

    $conn->begin_transaction();
    try {
        check_schedule_references($conn, $data);
        $schedule_id = insert_schedule($conn, $data);
        replace_schedule_items($conn, $schedule_id, $data["items"]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (ScheduleValidationException $e) {
    json_error($e->getMessage(), 422, $e->errors);
} catch (mysqli_sql_exception $e) {
    json_error("Could not create schedule.", 500);
}

json_response(["success" => true, "schedule" => load_schedule($conn, $schedule_id)], 201);
