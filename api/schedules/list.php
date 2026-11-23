<?php
/*
 * GET /api/schedules/list.php
 * Lists schedules, soonest next delivery first. All parameters are optional.
 *   restaurant_id, farm_id   only this restaurant's / farm's schedules
 *   status                   active, paused or cancelled; comma separated for several
 *   frequency                daily, weekly, biweekly or monthly
 *   page, per_page           default 1 and 20, per_page at most 100
 *
 * 200 {"success": true, "schedules": [...], "pagination": {"page", "per_page", "total", "total_pages"}}
 */

require_once __DIR__ . "/functions.php";

const DEFAULT_PER_PAGE = 20;
const MAX_PER_PAGE = 100;

require_method("GET");

// Require authentication
$user = require_login();

$errors = [];
$where = [];
$types = "";
$params = [];

// Enforce restaurant ownership: restaurant users can only see their own schedules
// Do not trust restaurant_id from query parameters for authorization
if ($user["role"] === "restaurant") {
    $conn = connect_db();
    $restaurantId = current_restaurant_id($conn, $user);
    if ($restaurantId <= 0) {
        json_error("Restaurant account not found.", 404);
    }
    $where[] = "s.restaurant_id = ?";
    $types .= "i";
    $params[] = $restaurantId;
}

// Allow farmers to view their farms' schedules (for future use if needed)
if ($user["role"] === "farmer") {
    $conn = connect_db();
    $farmIds = current_farm_ids($conn, $user);
    if (count($farmIds) === 0) {
        $where[] = "1 = 0"; // No farms, return empty list
    } else {
        $placeholders = implode(",", array_fill(0, count($farmIds), "?"));
        $where[] = "s.farm_id IN ($placeholders)";
        $types .= str_repeat("i", count($farmIds));
        array_push($params, ...$farmIds);
    }
}

// Admin users: allow filtering by restaurant_id or farm_id (only for admins)
if ($user["role"] === "admin") {
    foreach (["restaurant_id", "farm_id"] as $field) {
        $id = $_GET[$field] ?? "";
        if ($id === "") {
            continue;
        }
        if (!is_positive_int($id)) {
            $errors[$field] = "Must be a positive integer.";
            continue;
        }
        $where[] = "s.$field = ?";
        $types .= "i";
        $params[] = (int) $id;
    }
}

$status_param = trim((string) ($_GET["status"] ?? ""));
if ($status_param !== "") {
    $statuses = array_unique(array_filter(array_map("trim", explode(",", strtolower($status_param)))));
    foreach ($statuses as $status) {
        if (!isset(SCHEDULE_STATUS_TRANSITIONS[$status])) {
            $errors["status"] = "Use: " . implode(", ", array_keys(SCHEDULE_STATUS_TRANSITIONS)) . ".";
        }
    }
    if ($statuses && !isset($errors["status"])) {
        $where[] = "s.status IN (" . implode(",", array_fill(0, count($statuses), "?")) . ")";
        $types .= str_repeat("s", count($statuses));
        array_push($params, ...$statuses);
    }
}

$frequency = $_GET["frequency"] ?? "";
if ($frequency !== "") {
    if (!in_array($frequency, SCHEDULE_FREQUENCIES, true)) {
        $errors["frequency"] = "Use: " . implode(", ", SCHEDULE_FREQUENCIES) . ".";
    } else {
        $where[] = "s.frequency = ?";
        $types .= "s";
        $params[] = $frequency;
    }
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
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";
$conn = connect_db();

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM recurring_schedules s $where_sql");
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    $offset = ($page - 1) * $per_page;
    $stmt = $conn->prepare(
        "SELECT s.*, r.res_name AS restaurant_name, f.farm_name,
                (SELECT COUNT(*) FROM recurring_items ri WHERE ri.schedule_id = s.schedule_id) AS item_count
         FROM recurring_schedules s
         JOIN restaurants r ON r.restaurant_id = s.restaurant_id
         JOIN farms f ON f.farm_id = s.farm_id
         $where_sql
         ORDER BY s.next_delivery_date, s.schedule_id
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param($types . "ii", ...[...$params, $per_page, $offset]);
    $stmt->execute();
    $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Load items for each schedule
    foreach ($schedules as &$schedule) {
        $schedule_id = (int) $schedule["schedule_id"];
        $stmt = $conn->prepare(
            "SELECT ri.recurring_item_id, ri.product_id, p.name AS product_name,
                    ri.quantity_kg, p.price_per_kg,
                    ROUND(ri.quantity_kg * p.price_per_kg, 2) AS estimated_subtotal
             FROM recurring_items ri
             JOIN products p ON p.product_id = ri.product_id
             WHERE ri.schedule_id = ?
             ORDER BY ri.recurring_item_id"
        );
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $schedule["items"] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    json_error("Could not load schedules.", 500);
}

json_response([
    "success" => true,
    "schedules" => $schedules,
    "pagination" => [
        "page" => $page,
        "per_page" => $per_page,
        "total" => $total,
        "total_pages" => (int) ceil($total / $per_page),
    ],
]);
