<?php

require_once __DIR__ . "/../common.php";

require_method("POST");

$body = read_json_body();
$email = str_input($body, "email", 150);
$password = isset($body["password"]) ? (string) $body["password"] : "";
$requestedPortal = str_input($body, "role", 20);

if ($email === "" || $password === "") {
    json_error("Email and password are required.");
}

$stmt = $conn->prepare(
    "SELECT user_id, username, email, password, role
     FROM user
     WHERE email = ?
     LIMIT 1"
);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user || !password_verify($password, $user["password"])) {
    json_error("Invalid email or password.", 401);
}

$portal = portal_for_role($user["role"]);



if ($requestedPortal === "buyer" && $portal !== "buyer") {
    json_error("This account is a seller. Please use the seller login.");
}

if ($requestedPortal === "seller" && $portal !== "seller") {
    json_error("This account is a buyer. Please use the buyer login.");
}

if ($requestedPortal === "logistics" && $portal !== "logistics") {
    json_error("This account is not a logistics account. Please use the correct login.");
}

session_regenerate_id(true);
$_SESSION["user_id"] = (int) $user["user_id"];
$_SESSION["username"] = $user["username"];
$_SESSION["email"] = $user["email"];
$_SESSION["role"] = $user["role"];
$_SESSION["portal"] = $portal;

$redirect = $portal === "seller"
    ? "../seller/seller-dashboard.html"
    : ($portal === "logistics"
        ? "../logistics/logistics-dashboard.html"
        : "../buyer/browse.html");

json_ok([
    "user" => [
        "user_id" => (int) $user["user_id"],
        "username" => $user["username"],
        "email" => $user["email"],
        "role" => $user["role"],
        "portal" => $portal
    ],
    "redirect" => $redirect
]);
