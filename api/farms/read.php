<?php
require_once __DIR__ . "/../db.php";
header("Content-Type: application/json; charset=utf-8");

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (isset($_GET["id"]) && (!$id || $id < 1)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid farm ID"]);
    exit;
}

if ($id) {
    $stmt = $conn->prepare("SELECT * FROM farms WHERE farm_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $farm = $result->fetch_assoc();

    if (!$farm) {
        http_response_code(404);
        echo json_encode(["error" => "Farm not found"]);
    } else {
        echo json_encode($farm);
    }

    $stmt->close();
} else {
    $result = $conn->query("SELECT * FROM farms ORDER BY farm_id DESC");
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
}
