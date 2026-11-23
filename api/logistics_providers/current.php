<?php
require_once __DIR__ . "/../common.php";

require_method("GET");
$conn = connect_db();

$user = require_role("logistics");

$providerIds = current_provider_ids($conn, $user);

if (count($providerIds) === 0) {
    json_error("No logistics provider found for this account.", 404);
}

$placeholders = implode(",", array_fill(0, count($providerIds), "?"));
$stmt = $conn->prepare(
    "SELECT provider_id, user_id, farm_id, provider_name, provider_type, contact_no
     FROM logistics_providers
     WHERE provider_id IN ($placeholders)"
);
$stmt->bind_param(str_repeat("i", count($providerIds)), ...$providerIds);
$stmt->execute();
$result = $stmt->get_result();

$providers = [];
while ($row = $result->fetch_assoc()) {
    $providers[] = [
        "provider_id" => (int) $row["provider_id"],
        "user_id" => (int) $row["user_id"],
        "farm_id" => $row["farm_id"] ? (int) $row["farm_id"] : null,
        "provider_name" => $row["provider_name"],
        "provider_type" => $row["provider_type"],
        "contact_no" => $row["contact_no"]
    ];
}
$stmt->close();

json_ok(["providers" => $providers]);
