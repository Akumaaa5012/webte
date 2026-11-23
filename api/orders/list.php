<?php

require_once __DIR__ . "/../helpers.php";

const DEFAULT_PER_PAGE = 20;
const MAX_PER_PAGE = 100;

// Allowed sort keys mapped to SQL columns, so user input never reaches the query directly.
const ORDER_SORT_COLUMNS = [
    "ordered_at" => "o.ordered_at",
    "requested_date" => "o.requested_date",
    "total_amount" => "o.total_amount",
    "status" => "o.status",
    "order_id" => "o.order_id",
    "restaurant_name" => "r.res_name",
    "farm_name" => "f.farm_name",
];

require_method("GET");

$user = require_login();

$errors = [];

$search = trim((string) ($_GET["q"] ?? ""));

$statuses = [];
$status_param = trim((string) ($_GET["status"] ?? ""));
if ($status_param !== "") {
    foreach (explode(",", $status_param) as $status) {
        $status = strtolower(trim($status));
        if ($status === "") {
            continue;
        }
        if (!is_valid_order_status($status)) {
            $errors["status"] = "Unknown status \"$status\". Use: " . implode(", ", array_keys(ORDER_STATUS_TRANSITIONS)) . ".";
            break;
        }
        $statuses[$status] = true;
    }
    $statuses = array_keys($statuses);
}

$restaurant_id = $_GET["restaurant_id"] ?? "";
if ($restaurant_id !== "" && !is_positive_int($restaurant_id)) {
    $errors["restaurant_id"] = "Must be a positive integer.";
}

$farm_id = $_GET["farm_id"] ?? "";
if ($farm_id !== "" && !is_positive_int($farm_id)) {
    $errors["farm_id"] = "Must be a positive integer.";
}

$date_field = $_GET["date_field"] ?? "ordered_at";
if (!in_array($date_field, ["ordered_at", "requested_date"], true)) {
    $errors["date_field"] = "Must be ordered_at or requested_date.";
}

$date_from = $_GET["date_from"] ?? "";
if ($date_from !== "" && !is_valid_date($date_from)) {
    $errors["date_from"] = "Format YYYY-MM-DD.";
}

$date_to = $_GET["date_to"] ?? "";
if ($date_to !== "" && !is_valid_date($date_to)) {
    $errors["date_to"] = "Format YYYY-MM-DD.";
}

if ($date_from !== "" && $date_to !== "" && !isset($errors["date_from"]) && !isset($errors["date_to"])
    && $date_from > $date_to) {
    $errors["date_to"] = "Must be on or after date_from.";
}

$sort = $_GET["sort"] ?? "ordered_at";
if (!isset(ORDER_SORT_COLUMNS[$sort])) {
    $errors["sort"] = "Must be one of: " . implode(", ", array_keys(ORDER_SORT_COLUMNS)) . ".";
}

$direction = strtolower($_GET["order"] ?? "desc");
if (!in_array($direction, ["asc", "desc"], true)) {
    $errors["order"] = "Must be asc or desc.";
}

$page = $_GET["page"] ?? 1;
if (!is_positive_int($page)) {
    $errors["page"] = "Must be a positive integer.";
}

$per_page = $_GET["per_page"] ?? DEFAULT_PER_PAGE;
if (!is_positive_int($per_page) || $per_page > MAX_PER_PAGE) {
    $errors["per_page"] = "Must be between 1 and " . MAX_PER_PAGE . ".";
}

if ($errors) {
    json_error("Validation failed.", 422, $errors);
}

$page = (int) $page;
$per_page = (int) $per_page;

// Build the WHERE clause and its bound values together.
$where = [];
$types = "";
$params = [];

if ($search !== "") {
    $like = "%" . addcslashes($search, "\\%_") . "%";
    $uuid_prefix = addcslashes($search, "\\%_") . "%";
    $conditions = ["r.res_name LIKE ?", "f.farm_name LIKE ?", "o.client_uuid LIKE ?"];
    $types .= "sss";
    array_push($params, $like, $like, $uuid_prefix);

    // "42" or "#42" also matches order 42.
    $order_number = ltrim($search, "#");
    if (is_positive_int($order_number)) {
        $conditions[] = "o.order_id = ?";
        $types .= "i";
        $params[] = (int) $order_number;
    }
    $where[] = "(" . implode(" OR ", $conditions) . ")";
}

if ($statuses) {
    $where[] = "o.status IN (" . implode(",", array_fill(0, count($statuses), "?")) . ")";
    $types .= str_repeat("s", count($statuses));
    array_push($params, ...$statuses);
}

if ($restaurant_id !== "") {
    $where[] = "o.restaurant_id = ?";
    $types .= "i";
    $params[] = (int) $restaurant_id;
}

if ($farm_id !== "") {
    $where[] = "o.farm_id = ?";
    $types .= "i";
    $params[] = (int) $farm_id;
}

// ordered_at is a datetime, so the end of the range is "before the next day".
if ($date_from !== "") {
    $where[] = "o.$date_field >= ?";
    $types .= "s";
    $params[] = $date_from;
}
if ($date_to !== "") {
    if ($date_field === "ordered_at") {
        $where[] = "o.ordered_at < DATE_ADD(?, INTERVAL 1 DAY)";
    } else {
        $where[] = "o.requested_date <= ?";
    }
    $types .= "s";
    $params[] = $date_to;
}

$from_sql = "FROM orders o
             JOIN restaurants r ON r.restaurant_id = o.restaurant_id
             JOIN farms f ON f.farm_id = o.farm_id";

$conn = connect_db();

apply_order_list_scope($conn, $user, $where, $types, $params);

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total $from_sql $where_sql");
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    // order_id is a tie-breaker so pages stay stable when sort values repeat.
    $order_by = ORDER_SORT_COLUMNS[$sort] . " " . strtoupper($direction);
    if ($sort !== "order_id") {
        $order_by .= ", o.order_id " . strtoupper($direction);
    }

    $offset = ($page - 1) * $per_page;
    $stmt = $conn->prepare(
        "SELECT o.order_id, o.client_uuid, o.status,
                o.restaurant_id, r.res_name AS restaurant_name,
                o.farm_id, f.farm_name,
                o.schedule_id, o.total_amount, o.requested_date,
                o.ordered_at, o.synced_at,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
         $from_sql
         $where_sql
         ORDER BY $order_by
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param($types . "ii", ...[...$params, $per_page, $offset]);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    json_error("Could not load orders.", 500);
}

json_response([
    "success" => true,
    "orders" => $orders,
    "pagination" => [
        "page" => $page,
        "per_page" => $per_page,
        "total" => $total,
        "total_pages" => (int) ceil($total / $per_page),
    ],
]);
