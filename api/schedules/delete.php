<?php


require_once __DIR__ . "/functions.php";

require_method("DELETE");
$schedule_id = require_id_param();
$user = require_login();
$conn = connect_db();

$conn->begin_transaction();
try {
    $schedule = lock_schedule($conn, $schedule_id);
    if ($schedule === null) {
        $conn->rollback();
        json_error("Schedule not found.", 404);
    }

    // Enforce ownership: restaurant users can only delete their own schedules
    if ($user["role"] === "restaurant") {
        $restaurantId = current_restaurant_id($conn, $user);
        if ($restaurantId <= 0 || (int) $schedule["restaurant_id"] !== $restaurantId) {
            $conn->rollback();
            json_error("Schedule not found.", 404);
        }
    }

    // Farmers can only delete schedules for their farms
    if ($user["role"] === "farmer") {
        $farmIds = current_farm_ids($conn, $user);
        if (!in_array((int) $schedule["farm_id"], $farmIds, true)) {
            $conn->rollback();
            json_error("Schedule not found.", 404);
        }
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS orders FROM orders WHERE schedule_id = ?");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $order_count = (int) $stmt->get_result()->fetch_assoc()["orders"];
    $stmt->close();

    if ($order_count > 0) {
        $conn->rollback();
        json_error("This schedule has $order_count order(s). Cancel it instead of deleting it.", 409);
    }

    // recurring_items rows are removed by ON DELETE CASCADE.
    $stmt = $conn->prepare("DELETE FROM recurring_schedules WHERE schedule_id = ?");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_error("Could not delete schedule.", 500);
}

json_response(["success" => true]);
