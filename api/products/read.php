<?php
require_once __DIR__ . "/../common.php";

require_method("GET");

$id = query_id();
if (isset($_GET["id"]) && $id <= 0) {
    json_error("Invalid product ID.", 400);
}

$user = current_user();
$farmIds = null;
if ($id <= 0 && $user && $user["role"] === "farmer") {
    $farmIds = current_farm_ids($conn, $user);
}

$products = load_products($conn, $id, $farmIds);

if ($id > 0) {
    if (count($products) === 0) {
        json_error("Product not found.", 404);
    }
    echo json_encode($products[0]);
    exit;
}

echo json_encode($products);
