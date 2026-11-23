<?php
require_once __DIR__ . "/helpers.php";

// type => file in the notifications/ folder; only these can ever be loaded
const NOTIFICATION_TYPES = [
    "new_orders" => "new_orders.php",
    "status_changes" => "status_changes.php",
    "low_stock" => "low_stock.php",
];

require_method("GET");

$type = $_GET["type"] ?? "";

if (!is_string($type) || !isset(NOTIFICATION_TYPES[$type])) {
    json_error("Validation failed.", 422, [
        "type" => "Must be one of: " . implode(", ", array_keys(NOTIFICATION_TYPES)) . ".",
    ]);
}

require __DIR__ . "/notifications/" . NOTIFICATION_TYPES[$type];