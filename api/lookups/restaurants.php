<?php

require_once __DIR__ . "/../common.php";

require_method("GET");
require_login();

$id = query_id();
$restaurants = fetch_restaurants($conn, $id);

if ($id > 0 && count($restaurants) === 0) {
    json_error("Restaurant not found.", 404);
}

json_ok(["restaurants" => $restaurants]);
