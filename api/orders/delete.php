<?php
/*
 * DELETE /api/orders/delete.php?id=5
 * Permanently deletes a pending order with its items, delivery point and status log.
 *
 * IMPORTANT: This endpoint is now RESTRICTED for orders that have inventory reserved.
 * Since stock is deducted when orders are created, deleting an order with stock_deducted = 1
 * would leave inventory in an incorrect state. Use cancellation (update.php with {"status": "cancelled"})
 * instead, which properly restores inventory.
 *
 * Historical orders (stock_deducted = 0) can still be deleted for cleanup purposes.
 *
 * Note: deleting a schedule-generated order frees that date, so generate.php can create it again.
 * Cancelling it keeps the date skipped.
 *
 * 200 {"success": true}
 * 404 order not found
 * 409 order is not pending, or order has reserved inventory (use cancellation instead)
 */

require_once __DIR__ . "/../helpers.php";
define("LOAD_ORDER_FUNCTIONS_ONLY", true);
require_once __DIR__ . "/create.php";

require_method("DELETE");
$order_id = require_order_id_param();
$conn = connect_db();
$user = require_login();

$conn->begin_transaction();
try {
    $order = lock_order($conn, $order_id);
    if (!$order) {
        $conn->rollback();
        json_error("Order not found.", 404);
    }

    require_order_access($conn, $user, $order);
    if ($order["status"] !== "pending") {
        $conn->rollback();
        json_error("Only pending orders can be deleted. This order is {$order["status"]}; cancel it instead.", 409);
    }

    // Check if inventory was reserved for this order
    if ((int) $order["stock_deducted"] === 1) {
        $conn->rollback();
        json_error("This order has reserved inventory. Cancel it instead to restore stock properly. Use PUT /api/orders/update.php with {\"status\": \"cancelled\"}.", 409);
    }

    // order_items, order_status_log and deliveries rows are removed by ON DELETE CASCADE.
    $stmt = $conn->prepare("DELETE FROM orders WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_error("Could not delete order.", 500);
}

json_response(["success" => true]);
