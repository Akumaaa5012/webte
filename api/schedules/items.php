<?php
/*
 * Changes one item of a recurring schedule. (To replace all items at once, send "items" to update.php.)
 *
 *   POST   /api/schedules/items.php            {"schedule_id": 1, "product_id": 2, "quantity_kg": 3}
 *   PUT    /api/schedules/items.php?id=5       {"quantity_kg": 4}
 *   DELETE /api/schedules/items.php?id=5
 *
 * id is recurring_item_id. Every call returns the whole schedule:
 * 200/201 {"success": true, "schedule": {..., "items": [...]}}
 * 404 schedule or item not found
 * 409 schedule cancelled, product already in the schedule, or removing the last item
 * 422 bad input
 */

require_once __DIR__ . "/functions.php";

$method = $_SERVER["REQUEST_METHOD"];
if (!in_array($method, ["POST", "PUT", "DELETE"], true)) {
    header("Allow: POST, PUT, DELETE");
    json_error("Method not allowed.", 405);
}

$user = require_login();
$conn = connect_db();
$body = $method === "DELETE" ? [] : read_json_body();

// For PUT and DELETE, find which schedule the item belongs to.
$item = null;
if ($method === "POST") {
    if (!is_positive_int($body["schedule_id"] ?? null)) {
        json_error("Validation failed.", 422, ["schedule_id" => "Required and must be a positive integer."]);
    }
    $schedule_id = (int) $body["schedule_id"];
} else {
    $item_id = require_id_param();
    $stmt = $conn->prepare("SELECT * FROM recurring_items WHERE recurring_item_id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) {
        json_error("Schedule item not found.", 404);
    }
    $schedule_id = (int) $item["schedule_id"];
}

$conn->begin_transaction();
try {
    // Locking the schedule makes item changes to the same schedule run one at a time.
    $schedule = lock_schedule($conn, $schedule_id);
    if ($schedule === null) {
        $conn->rollback();
        json_error("Schedule not found.", 404);
    }
    if ($schedule["status"] === "cancelled") {
        $conn->rollback();
        json_error("This schedule is cancelled and can no longer be changed.", 409);
    }

    // Enforce ownership: restaurant users can only modify their own schedules
    if ($user["role"] === "restaurant") {
        $restaurantId = current_restaurant_id($conn, $user);
        if ($restaurantId <= 0 || (int) $schedule["restaurant_id"] !== $restaurantId) {
            $conn->rollback();
            json_error("Schedule not found.", 404);
        }
    }

    // Farmers can only modify schedules for their farms
    if ($user["role"] === "farmer") {
        $farmIds = current_farm_ids($conn, $user);
        if (!in_array((int) $schedule["farm_id"], $farmIds, true)) {
            $conn->rollback();
            json_error("Schedule not found.", 404);
        }
    }

    if ($method === "DELETE") {
        $stmt = $conn->prepare("SELECT COUNT(*) AS items FROM recurring_items WHERE schedule_id = ?");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $item_count = (int) $stmt->get_result()->fetch_assoc()["items"];
        $stmt->close();
        if ($item_count <= 1) {
            $conn->rollback();
            json_error("A schedule needs at least one item. Cancel or delete the schedule instead.", 409);
        }

        $stmt = $conn->prepare("DELETE FROM recurring_items WHERE recurring_item_id = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $stmt->close();
        $status_code = 200;
    } else {
        $product_id = $method === "POST" ? ($body["product_id"] ?? null) : (int) $item["product_id"];
        $quantity_kg = $body["quantity_kg"] ?? null;

        $errors = [];
        if (!is_positive_int($product_id)) {
            $errors["product_id"] = "Required and must be a positive integer.";
        }
        $quantity_error = quantity_kg_error($quantity_kg);
        if ($quantity_error !== null) {
            $errors["quantity_kg"] = $quantity_error;
        }
        if (!$errors) {
            $product_id = (int) $product_id;
            $quantity_kg = round((float) $quantity_kg, 2);
            // Same "items.product_<id>" keys as create.php and update.php.
            $errors = check_schedule_products($conn, $schedule["farm_id"], [$product_id => $quantity_kg]);
        }
        if ($errors) {
            throw new ScheduleValidationException($errors);
        }

        if ($method === "POST") {
            $stmt = $conn->prepare(
                "INSERT INTO recurring_items (schedule_id, product_id, quantity_kg) VALUES (?, ?, ?)"
            );
            $stmt->bind_param("iid", $schedule_id, $product_id, $quantity_kg);
            $status_code = 201;
        } else {
            $stmt = $conn->prepare("UPDATE recurring_items SET quantity_kg = ? WHERE recurring_item_id = ?");
            $stmt->bind_param("di", $quantity_kg, $item_id);
            $status_code = 200;
        }
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
} catch (ScheduleValidationException $e) {
    $conn->rollback();
    json_error($e->getMessage(), 422, $e->errors);
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    // uq_recurring_schedule_product: one row per product per schedule.
    if ($e->getCode() === 1062) {
        json_error("This product is already in the schedule. Change its quantity instead.", 409);
    }
    json_error("Could not save schedule item.", 500);
} catch (Throwable $e) {
    $conn->rollback();
    json_error("Could not save schedule item.", 500);
}

json_response(["success" => true, "schedule" => load_schedule($conn, $schedule_id)], $status_code);
