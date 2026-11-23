<?php

require_once __DIR__ . "/../helpers.php";

const MAX_ITEMS_PER_ORDER = 50;
const MAX_DAYS_AHEAD = 60;         // furthest requested_date allowed, from today
const MAX_ORDER_AGE_DAYS = 30;     // oldest offline order (ordered_at) still accepted
const CLOCK_SKEW_MINUTES = 10;     // device clocks may run a little ahead of the server
const MAX_ORDER_TOTAL_CENTS = 999999999999;  // orders.total_amount is decimal(12,2)

class OrderValidationException extends Exception
{
    public $errors;

    public function __construct($errors)
    {
        parent::__construct("Validation failed.");
        $this->errors = $errors;
    }
}

// Returns ["order" => array, "duplicate" => bool].
function create_order($conn, $body, $user = null, $require_uuid = false)
{
    if (!is_array($body)) {
        throw new OrderValidationException(["order" => "Must be an object."]);
    }

    // Look for a duplicate before validating: a resent order that was already saved must
    // come back as a duplicate even if it would fail today's checks (e.g. its date has passed).
    $client_uuid = $body["client_uuid"] ?? null;
    if (is_valid_uuid($client_uuid)) {
        $existing_id = find_order_id_by_uuid($conn, $client_uuid);
        if ($existing_id !== null) {
            return ["order" => load_order($conn, $existing_id), "duplicate" => true];
        }
    }

    $data = validate_order_fields($body, $require_uuid);

    // Verify the user can create this order (restaurant creates for their own restaurant)
    if ($user && $user["role"] === "restaurant") {
        $restaurantId = current_restaurant_id($conn, $user);
        if ($restaurantId <= 0 || (int) $data["restaurant_id"] !== $restaurantId) {
            throw new OrderValidationException(["restaurant_id" => "You can only create orders for your own restaurant."]);
        }

        // For offline orders, verify the user_id matches the authenticated session
        $queued_user_id = $body["user_id"] ?? null;
        if ($queued_user_id !== null && (int) $queued_user_id !== (int) $user["user_id"]) {
            throw new OrderValidationException(["user_id" => "This order was created by a different user and cannot be submitted by your account."]);
        }
    }

    // The order, its items, delivery point and first status log row are saved together or not at all.
    // The product checks run inside the transaction too: check_order_references() locks the ordered
    // products, so their price, stock and farm cannot change between the checks and the inserts.
    $conn->begin_transaction();
    try {
        $products = check_order_references($conn, $data);
        $total_amount = check_order_total($data, $products);

        $status = "pending";
        $stmt = $conn->prepare(
            "INSERT INTO orders
                (client_uuid, restaurant_id, farm_id, schedule_id, status,
                 total_amount, requested_date, ordered_at, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), NOW())"
        );
        $stmt->bind_param(
            "siiisdss",
            $data["client_uuid"],
            $data["restaurant_id"],
            $data["farm_id"],
            $data["schedule_id"],
            $status,
            $total_amount,
            $data["requested_date"],
            $data["ordered_at"]
        );
        $stmt->execute();
        $order_id = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO order_items (order_id, product_id, quantity_kg, unit_price) VALUES (?, ?, ?, ?)"
        );
        foreach ($data["items"] as $product_id => $quantity_kg) {
            $unit_price = (float) $products[$product_id]["price_per_kg"];
            $stmt->bind_param("iidd", $order_id, $product_id, $quantity_kg, $unit_price);
            $stmt->execute();
        }
        $stmt->close();

        // Deduct stock atomically for each product
        deduct_stock($conn, $data["items"], $order_id);

        // Create delivery record for all orders (with or without coordinates)
        // This ensures recurring orders can be assigned to logistics providers
        if ($data["delivery_lat"] !== null) {
            $stmt = $conn->prepare(
                "INSERT INTO deliveries (order_id, delivery_lat, delivery_lng) VALUES (?, ?, ?)"
            );
            $stmt->bind_param("idd", $order_id, $data["delivery_lat"], $data["delivery_lng"]);
            $stmt->execute();
            $stmt->close();
        } else {
            // Create delivery record without coordinates (null lat/lng)
            // Coordinates can be added later via update or assign endpoints
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO deliveries (order_id) VALUES (?)"
                );
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                // If delivery already exists (from coordinate path in parallel call), that's fine
                if ((int) $e->getCode() !== 1062) {
                    throw $e;
                }
            }
        }

        log_order_status($conn, $order_id, $status, "Order placed.");

        $conn->commit();
    } catch (Throwable $e) {
        // Any failure (validation, SQL or PHP error) undoes every insert above.
        $conn->rollback();

        // Duplicate client_uuid: another request saved this order first.
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            $existing_id = find_order_id_by_uuid($conn, $data["client_uuid"]);
            if ($existing_id !== null) {
                return ["order" => load_order($conn, $existing_id), "duplicate" => true];
            }
            // uq_orders_schedule_date: a different request already made this schedule's order for that date.
            if (str_contains($e->getMessage(), "uq_orders_schedule_date")) {
                throw new OrderValidationException(["requested_date" => "This schedule already has an order on this date."]);
            }
        }
        throw $e;
    }

    return ["order" => load_order($conn, $order_id), "duplicate" => false];
}

// Returns cleaned values; items become [product_id => quantity_kg].
function validate_order_fields($body, $require_uuid)
{
    $errors = [];

    $client_uuid = $body["client_uuid"] ?? null;
    if ($client_uuid === null || $client_uuid === "") {
        if ($require_uuid) {
            $errors["client_uuid"] = "Required.";
        }
        $client_uuid = generate_uuid();
    } elseif (!is_valid_uuid($client_uuid)) {
        $errors["client_uuid"] = "Must be a valid UUID.";
    }

    $restaurant_id = $body["restaurant_id"] ?? null;
    if (!is_positive_int($restaurant_id)) {
        $errors["restaurant_id"] = "Required and must be a positive integer.";
    }

    $farm_id = $body["farm_id"] ?? null;
    if (!is_positive_int($farm_id)) {
        $errors["farm_id"] = "Required and must be a positive integer.";
    }

    $schedule_id = $body["schedule_id"] ?? null;
    if ($schedule_id === "") {
        $schedule_id = null;
    }
    if ($schedule_id !== null && !is_positive_int($schedule_id)) {
        $errors["schedule_id"] = "Must be a positive integer or null.";
    }

    $requested_date = $body["requested_date"] ?? null;
    $date_error = requested_date_error($requested_date);
    if ($date_error !== null) {
        $errors["requested_date"] = $date_error;
    }

    // ordered_at comes from the device when an order was placed offline.
    $ordered_at = $body["ordered_at"] ?? null;
    if ($ordered_at === "") {
        $ordered_at = null;
    }
    if ($ordered_at !== null) {
        $now = new DateTimeImmutable();
        if (!is_valid_datetime($ordered_at)) {
            $errors["ordered_at"] = "Format YYYY-MM-DD HH:MM:SS.";
        } elseif ($ordered_at > $now->modify("+" . CLOCK_SKEW_MINUTES . " minutes")->format("Y-m-d H:i:s")) {
            $errors["ordered_at"] = "Cannot be in the future.";
        } elseif ($ordered_at < $now->modify("-" . MAX_ORDER_AGE_DAYS . " days")->format("Y-m-d H:i:s")) {
            $errors["ordered_at"] = "Orders older than " . MAX_ORDER_AGE_DAYS . " days can no longer be synced.";
        }
    }

    [$delivery_lat, $delivery_lng] = validate_delivery_location(
        $body["delivery_lat"] ?? null, $body["delivery_lng"] ?? null, $errors
    );

    // the total app showed the user. Kept in centavos for an exact comparison.
    $total_amount = $body["total_amount"] ?? null;
    if ($total_amount === "") {
        $total_amount = null;
    }
    if ($total_amount !== null) {
        if (is_bool($total_amount) || !is_numeric($total_amount) || $total_amount < 0
            || !preg_match('/^\d+(\.\d{1,2})?$/', (string) $total_amount)) {
            $errors["total_amount"] = "Must be an amount of 0 or more with at most 2 decimal places.";
        } else {
            $total_amount = (int) round($total_amount * 100);
        }
    }

    $items = $body["items"] ?? null;
    if (!is_array($items) || count($items) === 0) {
        $errors["items"] = "At least one item is required.";
        $items = [];
    } elseif (count($items) > MAX_ITEMS_PER_ORDER) {
        $errors["items"] = "At most " . MAX_ITEMS_PER_ORDER . " items per order.";
        $items = [];
    }

    $clean_items = [];
    foreach ($items as $i => $item) {
        $product_id = $item["product_id"] ?? null;
        $quantity_kg = $item["quantity_kg"] ?? null;

        if (!is_positive_int($product_id)) {
            $errors["items.$i.product_id"] = "Required and must be a positive integer.";
            continue;
        }
        $quantity_error = quantity_kg_error($quantity_kg);
        if ($quantity_error !== null) {
            $errors["items.$i.quantity_kg"] = $quantity_error;
            continue;
        }
        if (isset($clean_items[(int) $product_id])) {
            $errors["items.$i.product_id"] = "Product appears more than once in this order.";
            continue;
        }
        $clean_items[(int) $product_id] = round((float) $quantity_kg, 2);
    }

    if ($errors) {
        throw new OrderValidationException($errors);
    }

    return [
        "client_uuid" => $client_uuid,
        "restaurant_id" => (int) $restaurant_id,
        "farm_id" => (int) $farm_id,
        "schedule_id" => $schedule_id === null ? null : (int) $schedule_id,
        "requested_date" => $requested_date,
        "ordered_at" => $ordered_at,
        "total_amount" => $total_amount,
        "delivery_lat" => $delivery_lat,
        "delivery_lng" => $delivery_lng,
        "items" => $clean_items,
    ];
}

// Returns an error message, or null when $date is a usable requested_date.
function requested_date_error($date)
{
    $today = new DateTimeImmutable("today");
    if (!is_string($date) || !is_valid_date($date)) {
        return "Required, format YYYY-MM-DD.";
    }
    if ($date < $today->format("Y-m-d")) {
        return "Cannot be in the past.";
    }
    if ($date > $today->modify("+" . MAX_DAYS_AHEAD . " days")->format("Y-m-d")) {
        return "Must be within " . MAX_DAYS_AHEAD . " days from today.";
    }
    return null;
}

// The delivery point is optional, but latitude and longitude must come together.
// Returns [lat, lng] rounded to the 6 decimals the database stores (phone GPS sends more),
// or [null, null]; adds any problems to $errors.
function validate_delivery_location($lat, $lng, &$errors)
{
    $lat = $lat === "" ? null : $lat;
    $lng = $lng === "" ? null : $lng;
    if ($lat === null && $lng === null) {
        return [null, null];
    }
    if ($lat === null || $lng === null) {
        $errors["delivery_location"] = "Send delivery_lat and delivery_lng together.";
        return [null, null];
    }
    if (!is_coordinate($lat, -90, 90)) {
        $errors["delivery_lat"] = "Must be a number from -90 to 90.";
    }
    if (!is_coordinate($lng, -180, 180)) {
        $errors["delivery_lng"] = "Must be a number from -180 to 180.";
    }
    if (!isset($errors["delivery_lat"]) && !isset($errors["delivery_lng"]) && !is_in_service_area($lat, $lng)) {
        $errors["delivery_location"] = "Must be inside the Philippines.";
    }
    return [round((float) $lat, 6), round((float) $lng, 6)];
}

// Checks that the restaurant, farm, schedule and products exist and fit together.
// Returns the ordered products keyed by product_id, locked (FOR UPDATE) until the caller's transaction ends.
function check_order_references($conn, $data)
{
    $errors = [];

    if (!row_exists($conn, "SELECT 1 FROM restaurants WHERE restaurant_id = ?", $data["restaurant_id"])) {
        $errors["restaurant_id"] = "Restaurant not found.";
    }
    if (!row_exists($conn, "SELECT 1 FROM farms WHERE farm_id = ?", $data["farm_id"])) {
        $errors["farm_id"] = "Farm not found.";
    }

    if ($data["schedule_id"] !== null) {
        $stmt = $conn->prepare("SELECT restaurant_id, farm_id FROM recurring_schedules WHERE schedule_id = ?");
        $stmt->bind_param("i", $data["schedule_id"]);
        $stmt->execute();
        $schedule = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$schedule) {
            $errors["schedule_id"] = "Schedule not found.";
        } elseif ((int) $schedule["restaurant_id"] !== $data["restaurant_id"]
            || (int) $schedule["farm_id"] !== $data["farm_id"]) {
            $errors["schedule_id"] = "Schedule does not belong to this restaurant and farm.";
        } else {
            // One order per schedule per delivery date (also enforced by uq_orders_schedule_date).
            $stmt = $conn->prepare("SELECT order_id FROM orders WHERE schedule_id = ? AND requested_date = ?");
            $stmt->bind_param("is", $data["schedule_id"], $data["requested_date"]);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existing) {
                $errors["requested_date"] = "This schedule already has an order on this date (order #{$existing["order_id"]}).";
            }
        }
    }

    $product_ids = array_keys($data["items"]);
    $placeholders = implode(",", array_fill(0, count($product_ids), "?"));
    $stmt = $conn->prepare(
        "SELECT product_id, farm_id, name, price_per_kg, min_order_kg, stock_kg
         FROM products WHERE product_id IN ($placeholders)
         FOR UPDATE"
    );
    $stmt->bind_param(str_repeat("i", count($product_ids)), ...$product_ids);
    $stmt->execute();
    $products = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $products[(int) $row["product_id"]] = $row;
    }
    $stmt->close();

    foreach ($data["items"] as $product_id => $quantity_kg) {
        $key = "items.product_$product_id";
        if (!isset($products[$product_id])) {
            $errors[$key] = "Product not found.";
            continue;
        }

        $product = $products[$product_id];
        if ((int) $product["farm_id"] !== $data["farm_id"]) {
            $errors[$key] = "{$product["name"]} is not sold by this farm.";
        } elseif ($quantity_kg < (float) $product["min_order_kg"]) {
            $errors[$key] = "Minimum order for {$product["name"]} is {$product["min_order_kg"]} kg.";
        } elseif ($quantity_kg > (float) $product["stock_kg"]) {
            $errors[$key] = "Only {$product["stock_kg"]} kg of {$product["name"]} is in stock.";
        }
    }

    if ($errors) {
        throw new OrderValidationException($errors);
    }

    return $products;
}

// Each line is quantity_kg x price_per_kg rounded to the centavo (same as load_order's subtotal),
// and the total is their sum. Works in whole centavos so float rounding cannot shift the total.
function calculate_order_total_cents($items, $products)
{
    $total_cents = 0;
    foreach ($items as $product_id => $quantity_kg) {
        $quantity_hundredths = (int) round($quantity_kg * 100);
        $price_cents = (int) round((float) $products[$product_id]["price_per_kg"] * 100);
        $total_cents += (int) round($quantity_hundredths * $price_cents / 100);
    }
    return $total_cents;
}

// Returns the order total. If the client sent the total it showed the user, it must match:
// a mismatch means prices changed (e.g. while the order waited offline) and the user should confirm again.
function check_order_total($data, $products)
{
    $total_cents = calculate_order_total_cents($data["items"], $products);

    if ($total_cents > MAX_ORDER_TOTAL_CENTS) {
        throw new OrderValidationException(["total_amount" => "Order total is too large."]);
    }

    if ($data["total_amount"] !== null && $data["total_amount"] !== $total_cents) {
        throw new OrderValidationException([
            "total_amount" => sprintf(
                "Does not match the items. Expected %s at current prices, got %s.",
                number_format($total_cents / 100, 2, ".", ""),
                number_format($data["total_amount"] / 100, 2, ".", "")
            ),
        ]);
    }

    return $total_cents / 100;
}

function find_order_id_by_uuid($conn, $client_uuid)
{
    $stmt = $conn->prepare("SELECT order_id FROM orders WHERE client_uuid = ?");
    $stmt->bind_param("s", $client_uuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row["order_id"] : null;
}

function load_order($conn, $order_id)
{
    $stmt = $conn->prepare(
        "SELECT o.*, d.delivery_lat, d.delivery_lng
         FROM orders o
         LEFT JOIN deliveries d ON d.order_id = o.order_id
         WHERE o.order_id = ?"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT oi.item_id, oi.product_id, p.name AS product_name,
                oi.quantity_kg, oi.unit_price,
                ROUND(oi.quantity_kg * oi.unit_price, 2) AS subtotal
         FROM order_items oi
         JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.item_id"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order["items"] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Convert stock_deducted from database value to boolean for API consistency
    if (isset($order["stock_deducted"])) {
        $order["stock_deducted"] = (bool) $order["stock_deducted"];
    }

    return $order;
}

// Every status an order gets is written here, so order_status_log is its full history.
// Call it inside the same transaction that sets orders.status.
function log_order_status($conn, $order_id, $status, $remarks)
{
    $stmt = $conn->prepare("INSERT INTO order_status_log (order_id, status, remarks) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $order_id, $status, $remarks);
    $stmt->execute();
    $stmt->close();
}

// Locks the order row until the transaction ends. Returns the row, or null if it does not exist.
function lock_order($conn, $order_id)
{
    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_id = ? FOR UPDATE");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $order;
}

// Sets total_amount to the sum of the saved item subtotals, each rounded to the centavo
// (DECIMAL math in MySQL, so it matches calculate_order_total_cents() and load_order()).
function recalculate_order_total($conn, $order_id)
{
    $stmt = $conn->prepare(
        "UPDATE orders SET total_amount = (
             SELECT COALESCE(SUM(ROUND(oi.quantity_kg * oi.unit_price, 2)), 0)
             FROM order_items oi WHERE oi.order_id = ?
         ) WHERE order_id = ?"
    );
    $stmt->bind_param("ii", $order_id, $order_id);
    $stmt->execute();
    $stmt->close();
}

function load_order_history($conn, $order_id)
{
    $stmt = $conn->prepare(
        "SELECT status, remarks, changed_at FROM order_status_log WHERE order_id = ? ORDER BY changed_at, log_id"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

// Reads ?id= as a positive int, or ends the request with a 400.
function require_order_id_param()
{
    $id = $_GET["id"] ?? null;
    if (!is_positive_int($id)) {
        json_error("Valid id required, e.g. ?id=5", 400);
    }
    return (int) $id;
}

function row_exists($conn, $sql, $id)
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Handle the request only when create.php is called directly, not when sync.php loads it.
if (!defined("LOAD_ORDER_FUNCTIONS_ONLY")) {
    require_method("POST");
    $conn = connect_db();
    $user = require_login();
    $body = read_json_body();

    try {
        $result = create_order($conn, $body, $user);
    } catch (OrderValidationException $e) {
        json_error($e->getMessage(), 422, $e->errors);
    } catch (mysqli_sql_exception $e) {
        json_error("Could not create order.", 500);
    }

    json_response(
        ["success" => true, "duplicate" => $result["duplicate"], "order" => $result["order"]],
        $result["duplicate"] ? 200 : 201
    );
}
