<?php
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/delivery_helpers.php";

require_method("PATCH");
$conn = connect_db();

$id = positive_id($_GET["id"] ?? null);

if ($id === null) {
    json_error("Valid delivery ID required.", 400);
}

$data = read_json_body();
$newStatus = $data["status"] ?? null;

if (!is_string($newStatus) || !in_array($newStatus, DELIVERY_STATUSES, true)) {
    json_error("status must be one of: " . implode(", ", DELIVERY_STATUSES) . ".", 422);
}

$remarks = $data["remarks"] ?? null;

if ($remarks !== null) {
    if (!is_string($remarks) || mb_strlen(trim($remarks)) > 255) {
        json_error("remarks must be text up to 255 characters.", 422);
    }

    $remarks = trim($remarks);

    if ($remarks === "") {
        $remarks = null;
    }
}

$now = date("Y-m-d H:i:s"); // local time (Asia/Manila, set in helpers.php)

try {
    // delivery and order change together or not at all
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT status, order_id, provider_id
         FROM deliveries WHERE delivery_id = ? FOR UPDATE"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$delivery) {
        $conn->rollback();
        json_error("Delivery not found.", 404);
    }

    $current = $delivery["status"];
    $allowed = DELIVERY_TRANSITIONS[$current] ?? [];

    if (!in_array($newStatus, $allowed, true)) {
        $conn->rollback();
        json_error(
            "Cannot change status from $current to $newStatus.",
            409,
            ["allowed" => $allowed]
        );
    }

    if (!in_array($newStatus, ["pending", "cancelled"], true) &&
        $delivery["provider_id"] === null) {
        $conn->rollback();
        json_error("Assign a provider before changing the status to $newStatus.", 409);
    }

    // work out whether the order has to move too, and whether it may
    $targetOrderStatus = ORDER_STATUS_FOR_DELIVERY[$newStatus] ?? null;
    $orderChanged = false;

    if ($targetOrderStatus !== null) {
        $orderStmt = $conn->prepare(
            "SELECT status FROM orders WHERE order_id = ? FOR UPDATE"
        );
        $orderStmt->bind_param("i", $delivery["order_id"]);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        $orderStmt->close();

        if (!$order) {
            $conn->rollback();
            json_error("The order for this delivery no longer exists.", 409);
        }

        $orderStatus = $order["status"];

        if ($orderStatus !== $targetOrderStatus) {
            $orderAllowed = ORDER_STATUS_TRANSITIONS[$orderStatus] ?? [];

            if (!in_array($targetOrderStatus, $orderAllowed, true)) {
                $conn->rollback();
                json_error(
                    "The order is $orderStatus and cannot move to $targetOrderStatus.",
                    409
                );
            }

            $orderChanged = true;
        }
    }

    // pickup_at and delivered_at are filled in automatically
    $pickupAt = $newStatus === "picked_up" ? $now : null;
    $deliveredAt = $newStatus === "delivered" ? $now : null;

    // Build dynamic UPDATE statement based on which timestamps need to be set
    $updateFields = ["status = ?"];
    $updateParams = [$newStatus];
    $updateTypes = "s";

    if ($pickupAt !== null) {
        $updateFields[] = "pickup_at = ?";
        $updateParams[] = $pickupAt;
        $updateTypes .= "s";
    }

    if ($deliveredAt !== null) {
        $updateFields[] = "delivered_at = ?";
        $updateParams[] = $deliveredAt;
        $updateTypes .= "s";
    }

    $updateParams[] = $id;
    $updateTypes .= "i";

    $updateSql = "UPDATE deliveries SET " . implode(", ", $updateFields) . " WHERE delivery_id = ?";
    $update = $conn->prepare($updateSql);
    $update->bind_param($updateTypes, ...$updateParams);
    try {
        $update->execute();
        $update->close();
    } catch (mysqli_sql_exception $e) {
        error_log("[Delivery Status Update] DELIVERY UPDATE FAILED: " . $e->getMessage());
        throw $e;
    }

    if ($orderChanged) {
        $orderUpdate = $conn->prepare(
            "UPDATE orders SET status = ? WHERE order_id = ?"
        );
        $orderUpdate->bind_param("si", $targetOrderStatus, $delivery["order_id"]);
        try {
            $orderUpdate->execute();
            $orderUpdate->close();
        } catch (mysqli_sql_exception $e) {
            error_log("[Delivery Status Update] ORDER UPDATE FAILED: " . $e->getMessage());
            throw $e;
        }

        $logRemark = $remarks ?? "Delivery marked as $newStatus.";

        $log = $conn->prepare(
            "INSERT INTO order_status_log (order_id, status, remarks, changed_at)
             VALUES (?, ?, ?, ?)"
        );
        $log->bind_param(
            "isss", $delivery["order_id"], $targetOrderStatus, $logRemark, $now
        );
        try {
            $log->execute();
            $log->close();
        } catch (mysqli_sql_exception $e) {
            error_log("[Delivery Status Update] LOG INSERT FAILED: " . $e->getMessage());
            throw $e;
        }
    }

    $conn->commit();

    json_response([
        "success" => true,
        "message" => "Delivery status updated successfully.",
        "delivery_id" => $id,
        "status" => $newStatus,
        "order_status" => $orderChanged ? $targetOrderStatus : null
    ]);
} catch (mysqli_sql_exception $e) {
    $conn->rollback();

    $errorMsg = $e->getMessage();
    $errorCode = $e->getCode();
    $sqlState = $e->getSqlState();

    error_log(
        "[Delivery Status Update FATAL] " . $errorMsg .
        " | Code: " . $errorCode .
        " | SQL State: " . $sqlState .
        " | Delivery ID: " . ($id ?? 'null') .
        " | New Status: " . ($newStatus ?? 'null') .
        " | Order Changed: " . ($orderChanged ?? 'false') .
        " | Target Order Status: " . ($targetOrderStatus ?? 'null') .
        " | Order ID: " . ($delivery['order_id'] ?? 'null')
    );

    json_error("Could not update delivery status. SQL Error [$sqlState]: $errorMsg", 500);
}