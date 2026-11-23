<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/validate_provider.php";

header("Content-Type: application/json; charset=utf-8");

function bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(["error" => $message]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["error" => "GET method required"]);
    exit;
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (isset($_GET["id"]) && (!$id || $id < 1)) {
    bad_request("Invalid provider ID");
}

$where = [];
$types = "";
$params = [];

if ($id) {
    $where[] = "lp.provider_id = ?";
    $types .= "i";
    $params[] = $id;
}

// optional list filters: ?provider_type=third_party&farm_id=1
if (isset($_GET["provider_type"]) && $_GET["provider_type"] !== "") {
    if (!is_string($_GET["provider_type"]) ||
        !in_array($_GET["provider_type"], PROVIDER_TYPES, true)) {
        bad_request("provider_type must be one of: " . implode(", ", PROVIDER_TYPES));
    }

    $where[] = "lp.provider_type = ?";
    $types .= "s";
    $params[] = $_GET["provider_type"];
}

if (isset($_GET["farm_id"]) && $_GET["farm_id"] !== "") {
    $farmId = filter_var($_GET["farm_id"], FILTER_VALIDATE_INT);

    if (!$farmId || $farmId < 1) {
        bad_request("Invalid farm_id");
    }

    $where[] = "lp.farm_id = ?";
    $types .= "i";
    $params[] = $farmId;
}

$sql = "SELECT lp.*, f.farm_name
        FROM logistics_providers lp
        LEFT JOIN farms f ON lp.farm_id = f.farm_id";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY lp.provider_id DESC";

try {
    $stmt = $conn->prepare($sql);

    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($id) {
        $provider = $result->fetch_assoc();

        if (!$provider) {
            http_response_code(404);
            echo json_encode(["error" => "Logistics provider not found"]);
        } else {
            echo json_encode($provider);
        }
    } else {
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    }

    $stmt->close();
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Could not load logistics providers"]);
}