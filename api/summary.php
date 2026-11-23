<?php
/*
 * GET /api/summary.php
 * Dashboard numbers for orders, products, stock and deliveries.
 *
 * Optional query parameters:
 *   section=orders,stock     only these sections (orders, products, stock, deliveries); default all
 *   farm_id=1                only this farm's orders, products and deliveries
 *   date_from=2026-10-01     orders (and their deliveries) placed on or after this date
 *   date_to=2026-10-31       orders (and their deliveries) placed on or before this date
 * Products and stock are always a snapshot of right now, so the dates do not apply to them.
 *
 * 200 {"success": true, "filters": {...}, "generated_at": "...",
 *      "orders": {...}, "products": {...}, "stock": {...}, "deliveries": {...}}
 * 422 bad parameter
 */

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/deliveries/delivery_helpers.php";
require_once __DIR__ . "/stock_rules.php";

const SUMMARY_SECTIONS = ["orders", "products", "stock", "deliveries"];

// The low-stock threshold (LOW_STOCK_KG) lives in stock_rules.php.
const LOW_STOCK_LIST_LIMIT = 10;

function summary_rows(mysqli $conn, string $sql, string $types = "", array $params = []): array
{
    // Validate that the number of placeholders matches the number of parameters
    $placeholder_count = substr_count($sql, '?');
    $param_count = strlen($types);

    if ($placeholder_count !== $param_count) {
        error_log("[summary_rows] SQL placeholder/parameter mismatch: $placeholder_count placeholders vs $param_count parameters. SQL: $sql");
        error_log("[summary_rows] Types: $types, Params: " . json_encode($params));
        throw new InvalidArgumentException("SQL query has $placeholder_count placeholders but $param_count parameters were provided.");
    }

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function summary_count(mysqli $conn, string $sql, string $types = "", array $params = []): int
{
    return (int) (summary_rows($conn, $sql, $types, $params)[0]["n"] ?? 0);
}

function summary_where(array $conditions): string
{
    return $conditions ? " WHERE " . implode(" AND ", $conditions) : "";
}

// Returns the trimmed value, or false if the parameter was sent as an array.
function summary_param(string $key)
{
    $value = $_GET[$key] ?? "";

    return is_string($value) ? trim($value) : false;
}

require_method("GET");

$user = require_login();

$errors = [];

$sections = SUMMARY_SECTIONS;
$section_param = summary_param("section");

if ($section_param === false) {
    $errors["section"] = "Must be text.";
} elseif ($section_param !== "") {
    $sections = [];
    foreach (explode(",", $section_param) as $name) {
        $name = strtolower(trim($name));
        if (!in_array($name, SUMMARY_SECTIONS, true)) {
            $errors["section"] = "Use any of: " . implode(", ", SUMMARY_SECTIONS) . " (comma separated).";
            break;
        }
        $sections[$name] = $name;
    }
    $sections = array_values($sections);
}

$farm_id = summary_param("farm_id");
if ($farm_id === false || ($farm_id !== "" && !is_positive_int($farm_id))) {
    $errors["farm_id"] = "Must be a positive integer.";
}

$date_from = summary_param("date_from");
if ($date_from === false || ($date_from !== "" && !is_valid_date($date_from))) {
    $errors["date_from"] = "Format YYYY-MM-DD.";
}

$date_to = summary_param("date_to");
if ($date_to === false || ($date_to !== "" && !is_valid_date($date_to))) {
    $errors["date_to"] = "Format YYYY-MM-DD.";
}

if (!isset($errors["date_from"]) && !isset($errors["date_to"])
    && $date_from !== "" && $date_to !== "" && $date_from > $date_to) {
    $errors["date_to"] = "Must be on or after date_from.";
}

if ($errors) {
    json_error("Validation failed.", 422, $errors);
}

$farm_id = $farm_id !== "" ? (int) $farm_id : null;

$conn = connect_db();

// Store seller's farm IDs separately for reuse in multiple queries
$seller_farm_ids = [];
if ($user["role"] === "farmer") {
    $seller_farm_ids = current_farm_ids($conn, $user);
}

// Filters for anything built on orders (alias "o"): orders and deliveries.
$order_where = [];
$order_types = "";
$order_params = [];

// Filter for products (alias "p"): only the farm applies.
$product_where = [];
$product_types = "";
$product_params = [];

// Apply farm ownership filter for sellers
if ($user["role"] === "farmer") {
    if (count($seller_farm_ids) === 0) {
        // Seller has no farms - no data to show
        $order_where[] = "1 = 0";
        $product_where[] = "1 = 0";
    } else {
        // Override farm_id parameter with seller's actual farms
        $placeholders = implode(",", array_fill(0, count($seller_farm_ids), "?"));
        $order_where[] = "o.farm_id IN ($placeholders)";
        $order_types .= str_repeat("i", count($seller_farm_ids));
        array_push($order_params, ...$seller_farm_ids);

        $product_where[] = "p.farm_id IN ($placeholders)";
        $product_types .= str_repeat("i", count($seller_farm_ids));
        array_push($product_params, ...$seller_farm_ids);

        // Ignore the farm_id parameter for sellers
        $farm_id = null;
    }
}

// For non-farmer roles (or if farm_id parameter is provided for admins), apply farm_id filter
if ($farm_id !== null) {
    $order_where[] = "o.farm_id = ?";
    $order_types .= "i";
    $order_params[] = $farm_id;

    $product_where[] = "p.farm_id = ?";
    $product_types .= "i";
    $product_params[] = $farm_id;
}

if ($date_from !== "") {
    $order_where[] = "o.ordered_at >= ?";
    $order_types .= "s";
    $order_params[] = $date_from;
}
if ($date_to !== "") {
    $order_where[] = "o.ordered_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $order_types .= "s";
    $order_params[] = $date_to;
}
$summary = [];

try {
    if (in_array("orders", $sections, true)) {
        $by_status = [];
        foreach (array_keys(ORDER_STATUS_TRANSITIONS) as $status) {
            $by_status[$status] = ["count" => 0, "total_amount" => 0.0];
        }

        $rows = summary_rows(
            $conn,
            "SELECT o.status, COUNT(*) AS n, COALESCE(SUM(o.total_amount), 0) AS amount
             FROM orders o" . summary_where($order_where) . "
             GROUP BY o.status",
            $order_types,
            $order_params
        );

        $order_total = 0;
        foreach ($rows as $row) {
            $by_status[$row["status"]] = [
                "count" => (int) $row["n"],
                "total_amount" => (float) $row["amount"],
            ];
            $order_total += (int) $row["n"];
        }

        // "Today" ignores the date filters but still respects farm ownership.
        $today = date("Y-m-d");
        $today_where = ["o.ordered_at >= ?", "o.ordered_at < DATE_ADD(?, INTERVAL 1 DAY)"];
        $today_types = "ss";
        $today_params = [$today, $today];

        // Rebuild farm filter for today's query using seller_farm_ids
        if ($user["role"] === "farmer") {
            if (count($seller_farm_ids) > 0) {
                $placeholders = implode(",", array_fill(0, count($seller_farm_ids), "?"));
                $today_where[] = "o.farm_id IN ($placeholders)";
                $today_types .= str_repeat("i", count($seller_farm_ids));
                $today_params = array_merge($today_params, $seller_farm_ids);
            }
        } elseif ($farm_id !== null) {
            $today_where[] = "o.farm_id = ?";
            $today_types .= "i";
            $today_params[] = $farm_id;
        }

        $placed_today = summary_count(
            $conn,
            "SELECT COUNT(*) AS n FROM orders o
             WHERE " . implode(" AND ", $today_where),
            $today_types,
            $today_params
        );

        // Recent orders for dashboard (5 most recent)
        $recent_orders = summary_rows(
            $conn,
            "SELECT o.order_id, o.status, o.total_amount, o.ordered_at,
                    r.res_name AS restaurant_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
             FROM orders o
             JOIN restaurants r ON r.restaurant_id = o.restaurant_id" . summary_where($order_where) . "
             ORDER BY o.ordered_at DESC
             LIMIT 5",
            $order_types,
            $order_params
        );

        $summary["orders"] = [
            "total" => $order_total,
            "placed_today" => $placed_today,
            // revenue = value of delivered orders only
            "revenue" => $by_status["delivered"]["total_amount"] ?? 0.0,
            "by_status" => $by_status,
            "recent_orders" => $recent_orders,
        ];
    }

    if (in_array("products", $sections, true)) {
        $product_total = summary_count(
            $conn,
            "SELECT COUNT(*) AS n FROM products p" . summary_where($product_where),
            $product_types,
            $product_params
        );

        // The farm filter sits in the JOIN so categories with no products still show with 0.
        $by_category = summary_rows(
            $conn,
            "SELECT c.category_id, c.name, COUNT(p.product_id) AS n
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.category_id"
            . summary_where($product_where) . "
             GROUP BY c.category_id, c.name
             ORDER BY c.name ASC",
            $product_types,
            $product_params
        );

        $products = [
            "total" => $product_total,
            "by_category" => array_map(static fn ($row) => [
                "category_id" => (int) $row["category_id"],
                "name" => $row["name"],
                "count" => (int) $row["n"],
            ], $by_category),
        ];

        if ($farm_id === null) {
            // For sellers, only show their farms. For admins, show all farms.
            if ($user["role"] === "farmer") {
                $by_farm = summary_rows(
                    $conn,
                    "SELECT f.farm_id, f.farm_name, COUNT(p.product_id) AS n
                     FROM farms f
                     LEFT JOIN products p ON p.farm_id = f.farm_id
                     WHERE f.farm_id IN (" . implode(",", array_fill(0, count($seller_farm_ids), "?")) . ")
                     GROUP BY f.farm_id, f.farm_name
                     ORDER BY f.farm_name ASC",
                    str_repeat("i", count($seller_farm_ids)),
                    $seller_farm_ids
                );
            } else {
                $by_farm = summary_rows(
                    $conn,
                    "SELECT f.farm_id, f.farm_name, COUNT(p.product_id) AS n
                     FROM farms f
                     LEFT JOIN products p ON p.farm_id = f.farm_id
                     GROUP BY f.farm_id, f.farm_name
                     ORDER BY f.farm_name ASC"
                );
            }
            $products["by_farm"] = array_map(static fn ($row) => [
                "farm_id" => (int) $row["farm_id"],
                "farm_name" => $row["farm_name"],
                "count" => (int) $row["n"],
            ], $by_farm);
        }

        $summary["products"] = $products;
    }

    if (in_array("stock", $sections, true)) {
        $totals = summary_rows(
            $conn,
            "SELECT COALESCE(SUM(p.stock_kg), 0) AS total_kg,
                    COALESCE(SUM(p.stock_kg * p.price_per_kg), 0) AS value,
                    COALESCE(SUM(p.stock_kg = 0), 0) AS out_of_stock,
                    COALESCE(SUM(p.stock_kg > 0 AND p.stock_kg <= ?), 0) AS low_stock
             FROM products p" . summary_where($product_where),
            "d" . $product_types,
            array_merge([LOW_STOCK_KG], $product_params)
        )[0];

        $low_items = summary_rows(
            $conn,
            "SELECT p.product_id, p.name, p.stock_kg, p.farm_id, f.farm_name
             FROM products p
             JOIN farms f ON f.farm_id = p.farm_id"
            . summary_where(array_merge(["p.stock_kg <= ?"], $product_where)) . "
             ORDER BY p.stock_kg ASC, p.product_id ASC
             LIMIT " . LOW_STOCK_LIST_LIMIT,
            "d" . $product_types,
            array_merge([LOW_STOCK_KG], $product_params)
        );

        $summary["stock"] = [
            "total_stock_kg" => (float) $totals["total_kg"],
            "stock_value" => (float) $totals["value"],
            "out_of_stock" => (int) $totals["out_of_stock"],
            "low_stock" => (int) $totals["low_stock"],
            "low_stock_threshold_kg" => LOW_STOCK_KG,
            "low_stock_items" => array_map(static fn ($row) => [
                "product_id" => (int) $row["product_id"],
                "name" => $row["name"],
                "stock_kg" => (float) $row["stock_kg"],
                "farm_id" => (int) $row["farm_id"],
                "farm_name" => $row["farm_name"],
            ], $low_items),
        ];
    }

    if (in_array("deliveries", $sections, true)) {
        $by_status = array_fill_keys(DELIVERY_STATUSES, 0);

        $rows = summary_rows(
            $conn,
            "SELECT d.status, COUNT(*) AS n
             FROM deliveries d
             JOIN orders o ON o.order_id = d.order_id" . summary_where($order_where) . "
             GROUP BY d.status",
            $order_types,
            $order_params
        );

        foreach ($rows as $row) {
            $by_status[$row["status"]] = (int) $row["n"];
        }

        // Confirmed orders whose delivery has no provider yet.
        $unassigned = summary_count(
            $conn,
            "SELECT COUNT(*) AS n
             FROM deliveries d
             JOIN orders o ON o.order_id = d.order_id"
            . summary_where(array_merge($order_where, [
                "o.status = 'confirmed'",
                "d.status = 'pending'",
                "d.provider_id IS NULL",
            ])),
            $order_types,
            $order_params
        );

        // Confirmed orders that have no delivery row at all.
        $without_delivery = summary_count(
            $conn,
            "SELECT COUNT(*) AS n
             FROM orders o
             LEFT JOIN deliveries d ON d.order_id = o.order_id"
            . summary_where(array_merge($order_where, [
                "o.status = 'confirmed'",
                "d.delivery_id IS NULL",
            ])),
            $order_types,
            $order_params
        );

        $summary["deliveries"] = [
            "total" => array_sum($by_status),
            // assigned, picked up or on the way
            "active" => $by_status["assigned"] + $by_status["picked_up"] + $by_status["in_transit"],
            "unassigned" => $unassigned,
            "confirmed_orders_without_delivery" => $without_delivery,
            "by_status" => $by_status,
        ];
    }
} catch (mysqli_sql_exception $e) {
    json_error("Could not load the summary.", 500);
}

json_response(array_merge([
    "success" => true,
    "filters" => [
        "farm_id" => $farm_id,
        "date_from" => $date_from !== "" ? $date_from : null,
        "date_to" => $date_to !== "" ? $date_to : null,
    ],
    "generated_at" => date("Y-m-d H:i:s"),
], $summary));