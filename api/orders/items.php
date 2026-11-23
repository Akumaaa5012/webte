<?php
/*
 * Changes the items of a pending order. total_amount is recalculated after every change.
 *
 * IMPORTANT: This endpoint is now DISABLED for orders that have inventory reserved.
 * Since stock is deducted when orders are created, modifying order items would cause
 * inventory inconsistencies. Cancel the order and place a new one instead.
 *
 * Historical orders (stock_deducted = 0) can still have items modified for cleanup purposes.
 *
 *   POST   /api/orders/items.php           {"order_id": 5, "product_id": 2, "quantity_kg": 3}
 *   PUT    /api/orders/items.php?id=12     {"quantity_kg": 4}
 *   DELETE /api/orders/items.php?id=12
 *
 * id is order_items.item_id. New items use the product's current price; a quantity change keeps the
 * price the item was ordered at. Same checks as create.php: product sold by the order's farm,
 * at least min_order_kg, no more than stock_kg.
 *
 * 200/201 {"success": true, "order": {..., "items": [...]}}
 * 404 order or item not found
 * 409 order not pending, order has reserved inventory (cancel and reorder instead), product already in the order, or removing the last item
 * 422 bad input or product checks failed
 */

require_once __DIR__ . "/../helpers.php";
define("LOAD_ORDER_FUNCTIONS_ONLY", true);
require_once __DIR__ . "/create.php";

$method = $_SERVER["REQUEST_METHOD"];
if (!in_array($method, ["POST", "PUT", "DELETE"], true)) {
    header("Allow: POST, PUT, DELETE");
    json_error("Method not allowed.", 405);
}

$conn = connect_db();
$user = require_login();
$body = $method === "DELETE" ? [] : read_json_body();

// For PUT and DELETE, find which order the item belongs to.
$item = null;
if ($method === "POST") {
    if (!is_positive_int($body["order_id"] ?? null)) {
        json_error("Validation failed.", 422, ["order_id" => "Required and must be a positive integer."]);
    }
    $order_id = (int) $body["order_id"];
} else {
    $item_id = require_order_id_param();
    $stmt = $conn->prepare("SELECT * FROM order_items WHERE item_id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) {
        json_error("Order item not found.", 404);
    }
    $order_id = (int) $item["order_id"];
}

$conn->begin_transaction();
try {
    // Locking the order makes changes to the same order run one at a time.
    $order = lock_order($conn, $order_id);
    if (!$order) {
        $conn->rollback();
        json_error("Order not found.", 404);
    }

    require_order_access($conn, $user, $order);
    if ($order["status"] !== "pending") {
        $conn->rollback();
        json_error("Only pending orders can be changed. This order is {$order["status"]}.", 409);
    }

    // Check if inventory was reserved for this order
    if ((int) $order["stock_deducted"] === 1) {
        $conn->rollback();
        json_error("This order has reserved inventory. Cancel it and place a new order instead to modify items. Use PUT /api/orders/update.php with {\"status\": \"cancelled\"}.", 409);
    }

    if ($method === "DELETE") {
        $stmt = $conn->prepare("SELECT COUNT(*) AS items FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $item_count = (int) $stmt->get_result()->fetch_assoc()["items"];
        $stmt->close();
        if ($item_count <= 1) {
            $conn->rollback();
            json_error("An order needs at least one item. Cancel or delete the order instead.", 409);
        }

        $stmt = $conn->prepare("DELETE FROM order_items WHERE item_id = ?");
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
        if ($errors) {
            throw new OrderValidationException($errors);
        }
        $product_id = (int) $product_id;
        $quantity_kg = round((float) $quantity_kg, 2);

        // Same farm / minimum / stock checks as a new order, with the product row locked.
        $products = check_order_references($conn, [
            "restaurant_id" => (int) $order["restaurant_id"],
            "farm_id" => (int) $order["farm_id"],
            "schedule_id" => null,
            "items" => [$product_id => $quantity_kg],
        ]);

        if ($method === "POST") {
            $unit_price = (float) $products[$product_id]["price_per_kg"];
            $stmt = $conn->prepare(
                "INSERT INTO order_items (order_id, product_id, quantity_kg, unit_price) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("iidd", $order_id, $product_id, $quantity_kg, $unit_price);
            $status_code = 201;
        } else {
            $stmt = $conn->prepare("UPDATE order_items SET quantity_kg = ? WHERE item_id = ?");
            $stmt->bind_param("di", $quantity_kg, $item_id);
            $status_code = 200;
        }
        $stmt->execute();
        $stmt->close();
    }

    recalculate_order_total($conn, $order_id);
    $conn->commit();
} catch (OrderValidationException $e) {
    $conn->rollback();
    json_error($e->getMessage(), 422, $e->errors);
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    // uq_order_product: one row per product per order.
    if ($e->getCode() === 1062) {
        json_error("This product is already in the order. Change its quantity instead.", 409);
    }
    json_error("Could not save order item.", 500);
} catch (Throwable $e) {
    $conn->rollback();
    json_error("Could not save order item.", 500);
}

json_response(["success" => true, "order" => load_order($conn, $order_id)], $status_code);
