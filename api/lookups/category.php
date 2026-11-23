<?php

require_once __DIR__ . "/../common.php";

require_method("GET");
require_login();

$id = query_id();
$categories = fetch_categories($conn, $id);

if ($id > 0 && count($categories) === 0) {
    json_error("Category not found.", 404);
}

json_ok(["categories" => $categories]);
