<?php
require_once __DIR__ . "/../common.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header("Content-Type: application/json; charset=utf-8");

const MAX_PHOTO_BYTES = 2 * 1024 * 1024; // 2 MB
const PHOTO_URL_PREFIX = "uploads/products/"; // value stored in products.photo
const PHOTO_DIR = __DIR__ . "/../uploads/products/"; // where files are saved

// real file type (detected from content) => extension we save it with
const ALLOWED_TYPES = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp"
];

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    fail(405, "POST method required");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    fail(400, "Valid product ID required");
}

require_owned_product($conn, $id);

// PHP empties $_FILES entirely when the request exceeds post_max_size
if (empty($_FILES) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
    fail(413, "File is too large (max 2 MB)");
}

if (!isset($_FILES["photo"]) || !is_array($_FILES["photo"]) ||
    is_array($_FILES["photo"]["error"])) {
    fail(422, "Send exactly one file in the 'photo' field");
}

$file = $_FILES["photo"];

switch ($file["error"]) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        fail(413, "File is too large (max 2 MB)");
    case UPLOAD_ERR_NO_FILE:
        fail(422, "No file was uploaded");
    default:
        fail(500, "Upload failed");
}

if ($file["size"] > MAX_PHOTO_BYTES) {
    fail(413, "File is too large (max 2 MB)");
}

if (!is_uploaded_file($file["tmp_name"])) {
    fail(400, "Invalid upload");
}

// check the real content, not the filename or the client-supplied type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file["tmp_name"]);

if (!isset(ALLOWED_TYPES[$mime])) {
    fail(415, "Only JPEG, PNG and WebP images are allowed");
}

if (@getimagesize($file["tmp_name"]) === false) {
    fail(415, "File is not a valid image");
}

$target = null;

try {
    $stmt = $conn->prepare("SELECT photo FROM products WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        fail(404, "Product not found");
    }

    if (!is_dir(PHOTO_DIR) && !mkdir(PHOTO_DIR, 0755, true) && !is_dir(PHOTO_DIR)) {
        fail(500, "Could not save photo");
    }

    // random name: no collisions, and the client's filename is never used
    $filename = bin2hex(random_bytes(16)) . "." . ALLOWED_TYPES[$mime];
    $target = PHOTO_DIR . $filename;

    if (!move_uploaded_file($file["tmp_name"], $target)) {
        $target = null;
        fail(500, "Could not save photo");
    }

    $photoPath = PHOTO_URL_PREFIX . $filename;

    $update = $conn->prepare(
        "UPDATE products SET photo = ? WHERE product_id = ?"
    );
    $update->bind_param("si", $photoPath, $id);
    $update->execute();
    $update->close();

    // remove the previous photo, but only if it is one of own uploads
    $old = $product["photo"];

    if (is_string($old) && strpos($old, PHOTO_URL_PREFIX) === 0) {
        $oldFile = PHOTO_DIR . basename($old);

        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    echo json_encode([
        "message" => "Photo uploaded successfully",
        "product_id" => $id,
        "photo" => $photoPath
    ]);
} catch (mysqli_sql_exception $e) {
    // don't leave an orphaned file behind if the database update failed
    if ($target !== null && is_file($target)) {
        @unlink($target);
    }

    fail(500, "Could not save photo");
}