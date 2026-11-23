<?php
/*
 * GET /api/notifications/low_stock.php
 * Products that are low on stock or sold out right now, lowest stock first.
 * Unlike orders, stock has no event log, so this returns the current state: a product
 * keeps appearing until it is restocked. The client can compare product_id and stock_kg
 * with what it already showed to decide what is new.
 *
 * Low stock = more than 0 kg and at most LOW_STOCK_KG (see stock_rules.php); out of stock = 0 kg.
 *
 * Parameters (all optional):
 *   level=all|low|out   default all
 *   farm_id=1           only this farm's products
 *   limit=20            1-100
 *
 * 200 {"success": true, "threshold_kg": 10, "total": 1, "events": [{"type": "low_stock",
 *      "event_id": 4, "occurred_at": "...", "message": "...", "product": {...}}]}
 * 422 bad parameter
 */

require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/../stock_rules.php";
require_once __DIR__ . "/notification_helpers.php";

require_method("GET");
$user = require_login();

$level = notification_param("level");
if ($level === "") {
    $level = "all";
}
if ($level === false || !in_array(strtolower($level), ["all", "low", "out"], true)) {
    json_error("Validation failed.", 422, ["level" => "Must be all, low or out."]);
}
$level = strtolower($level);

// after_id is accepted but ignored here: stock alerts are a current-state list, not a stream.
$params = read_event_params();

$where = [];
$types = "";
$bind = [];

if ($level === "out") {
    $where[] = "p.stock_kg = 0";
} elseif ($level === "low") {
    $where[] = "p.stock_kg > 0 AND p.stock_kg <= ?";
    $types .= "d";
    $bind[] = LOW_STOCK_KG;
} else {
    $where[] = "p.stock_kg <= ?";
    $types .= "d";
    $bind[] = LOW_STOCK_KG;
}

// Enforce ownership based on user role
$conn = connect_db();
if ($user["role"] === "farmer") {
    // Farmers can only see their own farms' stock alerts
    $userFarmIds = current_farm_ids($conn, $user);
    if (empty($userFarmIds)) {
        $conn->close();
        json_response([
            "success" => true,
            "threshold_kg" => LOW_STOCK_KG,
            "total" => 0,
            "events" => [],
        ]);
    }
    $where[] = "p.farm_id IN (" . implode(",", array_fill(0, count($userFarmIds), "?")) . ")";
    $types .= str_repeat("i", count($userFarmIds));
    $bind = array_merge($bind, $userFarmIds);
} elseif ($user["role"] === "restaurant") {
    // Restaurants don't have stock alerts - return empty
    $conn->close();
    json_response([
        "success" => true,
        "threshold_kg" => LOW_STOCK_KG,
        "total" => 0,
        "events" => [],
    ]);
} elseif ($user["role"] === "logistics") {
    // Logistics providers don't have stock alerts - return empty
    $conn->close();
    json_response([
        "success" => true,
        "threshold_kg" => LOW_STOCK_KG,
        "total" => 0,
        "events" => [],
    ]);
} elseif ($user["role"] === "admin") {
    // Admin can view specific farms with explicit farm_id parameter
    if ($params["farm_id"] !== null) {
        $where[] = "p.farm_id = ?";
        $types .= "i";
        $bind[] = $params["farm_id"];
    } else {
        // No farm_id provided - return empty for safety
        $conn->close();
        json_response([
            "success" => true,
            "threshold_kg" => LOW_STOCK_KG,
            "total" => 0,
            "events" => [],
        ]);
    }
} else {
    // Unknown role - deny access
    $conn->close();
    json_response([
        "success" => true,
        "threshold_kg" => LOW_STOCK_KG,
        "total" => 0,
        "events" => [],
    ]);
}

$where_sql = " WHERE " . implode(" AND ", $where);

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM products p" . $where_sql);
    if ($bind) {
        $stmt->bind_param($types, ...$bind);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()["n"];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT p.product_id, p.name, p.stock_kg, p.min_order_kg, p.updated_at,
                p.farm_id, f.farm_name
         FROM products p
         JOIN farms f ON f.farm_id = p.farm_id" . $where_sql . "
         ORDER BY p.stock_kg ASC, p.product_id ASC
         LIMIT ?"
    );
    $stmt->bind_param($types . "i", ...[...$bind, $params["limit"]]);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    json_error("Could not load stock alerts.", 500);
}

$events = array_map(static function ($row) {
    $stock = (float) $row["stock_kg"];
    $type = stock_level($stock) ?? "low_stock";

    return [
        "type" => $type,
        "event_id" => (int) $row["product_id"],
        "occurred_at" => $row["updated_at"],
        "message" => $type === "out_of_stock"
            ? sprintf("%s is out of stock (%s).", $row["name"], $row["farm_name"])
            : sprintf("%s is running low: %s kg left (%s).", $row["name"], number_format($stock, 2), $row["farm_name"]),
        "product" => [
            "product_id" => (int) $row["product_id"],
            "name" => $row["name"],
            "stock_kg" => $stock,
            "min_order_kg" => (float) $row["min_order_kg"],
            "farm_id" => (int) $row["farm_id"],
            "farm_name" => $row["farm_name"],
        ],
    ];
}, $rows);

json_response([
    "success" => true,
    "threshold_kg" => LOW_STOCK_KG,
    "total" => $total,
    "events" => $events,
]);