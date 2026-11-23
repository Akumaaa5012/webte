<?php
require_once __DIR__ . "/../common.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header("Content-Type: application/json; charset=utf-8");

const DEFAULT_LIMIT = 20;
const MAX_LIMIT = 100;

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(["error" => $message]);
    exit;
}

function run_query(mysqli $conn, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $conn->prepare($sql);

    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["error" => "GET method required"]);
    exit;
}

$where = [];
$types = "";
$params = [];

// Search: matches product name or farm name
if (isset($_GET["search"])) {
    if (!is_string($_GET["search"])) {
        bad_request("search must be text");
    }

    $search = trim($_GET["search"]);

    if (mb_strlen($search) > 100) {
        bad_request("search must be at most 100 characters");
    }

    if ($search !== "") {
        // escape LIKE wildcards so "%" and "_" are searched literally
        $like = "%" . addcslashes($search, "\\%_") . "%";
        $where[] = "(p.name LIKE ? OR f.farm_name LIKE ?)";
        $types .= "ss";
        $params[] = $like;
        $params[] = $like;
    }
}

// Filters: farm_id and category_id
$filters = [
    "farm_id" => "p.farm_id",
    "category_id" => "p.category_id"
];

foreach ($filters as $param => $column) {
    if (isset($_GET[$param]) && $_GET[$param] !== "") {
        $value = filter_var($_GET[$param], FILTER_VALIDATE_INT);

        if (!$value || $value < 1) {
            bad_request("Invalid $param");
        }

        $where[] = "$column = ?";
        $types .= "i";
        $params[] = $value;
    }
}

// Sorting: only allowlisted columns reach the SQL
$sortColumns = [
    "price" => "p.price_per_kg",
    "name" => "p.name"
];

$sort = $_GET["sort"] ?? "name";

if (!is_string($sort) || !isset($sortColumns[$sort])) {
    bad_request("sort must be one of: price, name");
}

$order = $_GET["order"] ?? "asc";

if (!is_string($order) || !in_array(strtolower($order), ["asc", "desc"], true)) {
    bad_request("order must be asc or desc");
}

$orderSql = $sortColumns[$sort] . " " . strtoupper($order);

// Pagination
$page = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);
$limit = filter_var($_GET["limit"] ?? DEFAULT_LIMIT, FILTER_VALIDATE_INT);

if (!$page || $page < 1) {
    bad_request("page must be a positive integer");
}

if (!$limit || $limit < 1 || $limit > MAX_LIMIT) {
    bad_request("limit must be between 1 and " . MAX_LIMIT);
}

$offset = ($page - 1) * $limit;

$from = "FROM products p
         JOIN farms f ON p.farm_id = f.farm_id
         JOIN categories c ON p.category_id = c.category_id";

$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

try {
    // total number of matches, for pagination
    $countResult = run_query(
        $conn,
        "SELECT COUNT(*) AS total $from $whereSql",
        $types,
        $params
    );
    $total = (int) $countResult->fetch_assoc()["total"];

    // product_id as a tie-breaker keeps the order stable between pages
    $dataSql = "SELECT p.*, f.farm_name, f.address AS farm_address, c.name AS category_name
                $from $whereSql
                ORDER BY $orderSql, p.product_id ASC
                LIMIT ? OFFSET ?";

    $dataResult = run_query(
        $conn,
        $dataSql,
        $types . "ii",
        array_merge($params, [$limit, $offset])
    );

    $rows = [];
    while ($row = $dataResult->fetch_assoc()) {
        $rows[] = format_product_row($row);
    }

    echo json_encode([
        "ok" => true,
        "data" => $rows,
        "products" => $rows,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "total_pages" => (int) ceil($total / $limit)
        ]
    ]);
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Could not load products"]);
}