<?php

require_once __DIR__ . "/../common.php";

require_method("GET");

$user = require_login();
$id = query_id();
$farms = fetch_farms($conn, $id);

if ($user["role"] === "farmer") {
    $userId = (int) $user["user_id"];
    $farms = array_values(array_filter($farms, static function ($farm) use ($userId) {
        return (int) $farm["user_id"] === $userId;
    }));
}

if ($id > 0 && count($farms) === 0) {
    json_error("Farm not found.", 404);
}

json_ok(["farms" => $farms]);
