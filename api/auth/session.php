<?php

require_once __DIR__ . "/../common.php";
require_once __DIR__ . "/../helpers.php";

require_method("GET");

$user = current_user();
if (!$user) {
    json_error("Please log in.", 401);
}

// Include restaurant_id for restaurant users to support offline checkout
if ($user["role"] === "restaurant") {
    $conn = connect_db();
    $restaurantId = current_restaurant_id($conn, $user);
    if ($restaurantId > 0) {
        $user["restaurant_id"] = $restaurantId;
    }
}

json_ok(["user" => $user]);
