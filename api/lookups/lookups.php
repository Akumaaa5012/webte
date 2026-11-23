<?php

require_once __DIR__ . "/../common.php";

$action = str_input($_GET, "action", 40);

if (request_method() === "GET") {
    $user = require_login();
    $categories = fetch_categories($conn);
    $farms = fetch_farms($conn);
    $restaurants = fetch_restaurants($conn);

    if ($user["role"] === "farmer") {
        $userId = (int) $user["user_id"];
        $farms = array_values(array_filter($farms, static function ($farm) use ($userId) {
            return (int) $farm["user_id"] === $userId;
        }));
    }

    if ($action === "categories") {
        json_ok(["categories" => $categories]);
    }

    if ($action === "farms") {
        json_ok(["farms" => $farms]);
    }

    if ($action === "restaurants") {
        json_ok(["restaurants" => $restaurants]);
    }

    json_ok([
        "categories" => $categories,
        "farms" => $farms,
        "restaurants" => $restaurants
    ]);
}

if (request_method() !== "POST") {
    json_error("Method not allowed.", 405);
}

$body = read_json_body();

if ($action === "add_category") {
    require_login();
    $name = str_input($body, "name", 50);
    if ($name === "") {
        json_error("Category name is required.");
    }

    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    $stmt->bind_param("s", $name);

    if (!$stmt->execute()) {
        if ($conn->errno === 1062) {
            json_error("That category already exists.", 409);
        }
        json_error("Could not add category.", 500);
    }

    json_ok([
        "message" => "Category added.",
        "category" => [
            "id" => $stmt->insert_id,
            "category_id" => $stmt->insert_id,
            "name" => $name
        ]
    ], 201);
}

json_error("Unknown action.");
