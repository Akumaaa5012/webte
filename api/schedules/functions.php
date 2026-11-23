<?php
// Shared validation and queries for the recurring schedule endpoints in this folder.

require_once __DIR__ . "/../helpers.php";

const SCHEDULE_FREQUENCIES = ["daily", "weekly", "biweekly", "monthly"];
const WEEKDAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const MAX_SCHEDULE_ITEMS = 50;

// Keys are statuses, values are the statuses a schedule may change to.
const SCHEDULE_STATUS_TRANSITIONS = [
    "active" => ["paused", "cancelled"],
    "paused" => ["active", "cancelled"],
    "cancelled" => [],
];

class ScheduleValidationException extends Exception
{
    public $errors;

    public function __construct($errors)
    {
        parent::__construct("Validation failed.");
        $this->errors = $errors;
    }
}

// Checks the schedule fields and returns cleaned values, including the calculated next_delivery_date.
// For an update, pass the saved schedule as $current: fields missing from $body keep their saved value.
function validate_schedule_fields($body, $current = null)
{
    $errors = [];
    $is_update = $current !== null;
    $value = function ($name) use ($body, $current) {
        return array_key_exists($name, $body) ? $body[$name] : ($current[$name] ?? null);
    };

    // Items belong to one farm, so a schedule cannot move to another restaurant or farm.
    foreach (["restaurant_id", "farm_id"] as $field) {
        if ($is_update) {
            if (array_key_exists($field, $body) && (string) $body[$field] !== (string) $current[$field]) {
                $errors[$field] = "Cannot be changed. Create a new schedule instead.";
            }
        } elseif (!is_positive_int($body[$field] ?? null)) {
            $errors[$field] = "Required and must be a positive integer.";
        }
    }

    $frequency = $value("frequency");
    if (!in_array($frequency, SCHEDULE_FREQUENCIES, true)) {
        $errors["frequency"] = "Must be one of: " . implode(", ", SCHEDULE_FREQUENCIES) . ".";
    }

    // weekly/biweekly: a weekday name. monthly: day of the month, 1-28 so every month has it. daily: none.
    $preferred_day = $value("preferred_day");
    if ($frequency === "daily") {
        $preferred_day = null;
    } elseif ($frequency === "weekly" || $frequency === "biweekly") {
        $preferred_day = is_string($preferred_day) ? ucfirst(strtolower(trim($preferred_day))) : null;
        if (!in_array($preferred_day, WEEKDAYS, true)) {
            $errors["preferred_day"] = "Required for $frequency: a weekday name, e.g. Monday.";
        }
    } elseif ($frequency === "monthly") {
        if (!filter_var($preferred_day, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 28]])) {
            $errors["preferred_day"] = "Required for monthly: day of the month from 1 to 28.";
        } else {
            $preferred_day = (string) (int) $preferred_day;
        }
    }

    $preferred_time = $value("preferred_time");
    if (!is_string($preferred_time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $preferred_time)) {
        $errors["preferred_time"] = "Required, format HH:MM.";
    } elseif (strlen($preferred_time) === 5) {
        $preferred_time .= ":00";
    }

    $today = (new DateTimeImmutable("today"))->format("Y-m-d");
    $start_date = $value("start_date");
    if (!is_valid_date($start_date)) {
        $errors["start_date"] = "Required, format YYYY-MM-DD.";
    } elseif ($start_date < $today && (!$is_update || $start_date !== $current["start_date"])) {
        $errors["start_date"] = "Cannot be in the past.";
    }

    $end_date = $value("end_date");
    if ($end_date === "") {
        $end_date = null;
    }
    if ($end_date !== null) {
        if (!is_valid_date($end_date)) {
            $errors["end_date"] = "Format YYYY-MM-DD, or null for no end date.";
        } elseif (!isset($errors["start_date"]) && $end_date < $start_date) {
            $errors["end_date"] = "Must be on or after start_date.";
        }
    }

    // New schedules always start active; status changes are checked in update.php.
    $status = $is_update ? $value("status") : "active";
    if (!is_string($status) || !isset(SCHEDULE_STATUS_TRANSITIONS[$status])) {
        $errors["status"] = "Must be one of: " . implode(", ", array_keys(SCHEDULE_STATUS_TRANSITIONS)) . ".";
    }

    // Items are required on create; on update they are optional and replace all saved items.
    $items = null;
    if (!$is_update || array_key_exists("items", $body)) {
        $items = validate_schedule_items($body["items"] ?? null, $errors);
    }

    if ($errors) {
        throw new ScheduleValidationException($errors);
    }

    $data = [
        "restaurant_id" => (int) ($is_update ? $current["restaurant_id"] : $body["restaurant_id"]),
        "farm_id" => (int) ($is_update ? $current["farm_id"] : $body["farm_id"]),
        "frequency" => $frequency,
        "preferred_day" => $preferred_day,
        "preferred_time" => $preferred_time,
        "start_date" => $start_date,
        "end_date" => $end_date,
        "status" => $status,
        "items" => $items,
    ];

    $data["next_delivery_date"] = calculate_next_delivery_date($data);
    if ($data["next_delivery_date"] === null) {
        throw new ScheduleValidationException(["end_date" => "No delivery dates fall between start_date and end_date."]);
    }

    return $data;
}

// Returns [product_id => quantity_kg]; adds any problems to $errors.
function validate_schedule_items($items, &$errors)
{
    if (!is_array($items) || count($items) === 0) {
        $errors["items"] = "At least one item is required.";
        return [];
    }
    if (count($items) > MAX_SCHEDULE_ITEMS) {
        $errors["items"] = "At most " . MAX_SCHEDULE_ITEMS . " items per schedule.";
        return [];
    }

    $clean_items = [];
    foreach ($items as $i => $item) {
        $product_id = is_array($item) ? ($item["product_id"] ?? null) : null;
        $quantity_kg = is_array($item) ? ($item["quantity_kg"] ?? null) : null;

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
            $errors["items.$i.product_id"] = "Product appears more than once in this schedule.";
            continue;
        }
        $clean_items[(int) $product_id] = round((float) $quantity_kg, 2);
    }
    return $clean_items;
}

// First delivery date on or after start_date, today and $on_or_after (YYYY-MM-DD, optional),
// or null if that is after end_date.
function calculate_next_delivery_date($data, $on_or_after = null)
{
    $start = new DateTimeImmutable($data["start_date"]);
    $today = new DateTimeImmutable("today");
    $from = max($start, $today, new DateTimeImmutable($on_or_after ?? "today"));

    if ($data["frequency"] === "daily") {
        $next = $from;
    } elseif ($data["frequency"] === "monthly") {
        $next = $from->setDate((int) $from->format("Y"), (int) $from->format("n"), (int) $data["preferred_day"]);
        if ($next < $from) {
            $next = $next->modify("+1 month");
        }
    } else {
        // First preferred weekday on or after start_date, then every 7 or 14 days from there.
        $step = $data["frequency"] === "weekly" ? 7 : 14;
        $next = $start->modify("-1 day")->modify("next " . $data["preferred_day"]);
        if ($next < $from) {
            $periods = (int) ceil($next->diff($from)->days / $step);
            $next = $next->modify("+" . ($periods * $step) . " days");
        }
    }

    if ($data["end_date"] !== null && $next->format("Y-m-d") > $data["end_date"]) {
        return null;
    }
    return $next->format("Y-m-d");
}

// True when $date is one of the schedule's delivery dates and is today or later.
function is_schedule_occurrence($schedule, $date)
{
    return is_valid_date($date) && calculate_next_delivery_date($schedule, $date) === $date;
}

function find_schedule_order_id($conn, $schedule_id, $date)
{
    $stmt = $conn->prepare("SELECT order_id FROM orders WHERE schedule_id = ? AND requested_date = ?");
    $stmt->bind_param("is", $schedule_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row["order_id"] : null;
}

// First upcoming delivery date that has no order yet, or null if end_date leaves none.
// Worked out from the orders, so generating dates out of order cannot skip a delivery.
function next_open_delivery_date($conn, $schedule)
{
    $next = calculate_next_delivery_date($schedule);
    for ($i = 0; $next !== null && $i < 400 && find_schedule_order_id($conn, $schedule["schedule_id"], $next) !== null; $i++) {
        $next = calculate_next_delivery_date($schedule, (new DateTimeImmutable($next))->modify("+1 day")->format("Y-m-d"));
    }
    return $next;
}

// The same schedule and date always give the same UUID (SHA-1 name-based, version 5 layout),
// so every attempt to generate one occurrence is the same order as far as create_order() is concerned.
function schedule_occurrence_uuid($schedule_id, $date)
{
    $hash = sha1("sproutroute:schedule:$schedule_id:$date");
    $hash[12] = "5";
    $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);
    return vsprintf("%s-%s-%s-%s-%s", [
        substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12),
    ]);
}

// Checks the restaurant, farm and products exist, and every product is sold by the schedule's farm.
function check_schedule_references($conn, $data)
{
    $errors = [];

    $stmt = $conn->prepare("SELECT 1 FROM restaurants WHERE restaurant_id = ?");
    $stmt->bind_param("i", $data["restaurant_id"]);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $errors["restaurant_id"] = "Restaurant not found.";
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT 1 FROM farms WHERE farm_id = ?");
    $stmt->bind_param("i", $data["farm_id"]);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $errors["farm_id"] = "Farm not found.";
    }
    $stmt->close();

    if ($data["items"]) {
        $errors += check_schedule_products($conn, $data["farm_id"], $data["items"]);
    }

    if ($errors) {
        throw new ScheduleValidationException($errors);
    }
}

// Returns errors keyed "items.product_<id>". Stock is not checked: deliveries are in the future.
function check_schedule_products($conn, $farm_id, $items)
{
    $product_ids = array_keys($items);
    $placeholders = implode(",", array_fill(0, count($product_ids), "?"));
    $stmt = $conn->prepare(
        "SELECT product_id, farm_id, name, min_order_kg FROM products WHERE product_id IN ($placeholders)"
    );
    $stmt->bind_param(str_repeat("i", count($product_ids)), ...$product_ids);
    $stmt->execute();
    $products = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $products[(int) $row["product_id"]] = $row;
    }
    $stmt->close();

    $errors = [];
    foreach ($items as $product_id => $quantity_kg) {
        $key = "items.product_$product_id";
        if (!isset($products[$product_id])) {
            $errors[$key] = "Product not found.";
        } elseif ((int) $products[$product_id]["farm_id"] !== (int) $farm_id) {
            $errors[$key] = "{$products[$product_id]["name"]} is not sold by this farm.";
        } elseif ($quantity_kg < (float) $products[$product_id]["min_order_kg"]) {
            $errors[$key] = "Minimum order for {$products[$product_id]["name"]} is {$products[$product_id]["min_order_kg"]} kg.";
        }
    }
    return $errors;
}

function insert_schedule($conn, $data)
{
    $stmt = $conn->prepare(
        "INSERT INTO recurring_schedules
            (restaurant_id, farm_id, frequency, preferred_day, preferred_time,
             start_date, end_date, next_delivery_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "iisssssss",
        $data["restaurant_id"],
        $data["farm_id"],
        $data["frequency"],
        $data["preferred_day"],
        $data["preferred_time"],
        $data["start_date"],
        $data["end_date"],
        $data["next_delivery_date"],
        $data["status"]
    );
    $stmt->execute();
    $schedule_id = $conn->insert_id;
    $stmt->close();
    return $schedule_id;
}

function update_schedule_row($conn, $schedule_id, $data)
{
    $stmt = $conn->prepare(
        "UPDATE recurring_schedules
         SET frequency = ?, preferred_day = ?, preferred_time = ?, start_date = ?,
             end_date = ?, next_delivery_date = ?, status = ?
         WHERE schedule_id = ?"
    );
    $stmt->bind_param(
        "sssssssi",
        $data["frequency"],
        $data["preferred_day"],
        $data["preferred_time"],
        $data["start_date"],
        $data["end_date"],
        $data["next_delivery_date"],
        $data["status"],
        $schedule_id
    );
    $stmt->execute();
    $stmt->close();
}

// Replaces all of a schedule's items with $items ([product_id => quantity_kg]).
function replace_schedule_items($conn, $schedule_id, $items)
{
    $stmt = $conn->prepare("DELETE FROM recurring_items WHERE schedule_id = ?");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO recurring_items (schedule_id, product_id, quantity_kg) VALUES (?, ?, ?)");
    foreach ($items as $product_id => $quantity_kg) {
        $stmt->bind_param("iid", $schedule_id, $product_id, $quantity_kg);
        $stmt->execute();
    }
    $stmt->close();
}

// Locks the schedule row until the transaction ends. Returns the row, or null if it does not exist.
function lock_schedule($conn, $schedule_id)
{
    $stmt = $conn->prepare("SELECT * FROM recurring_schedules WHERE schedule_id = ? FOR UPDATE");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

// Returns the schedule with restaurant/farm names and its items, or null if it does not exist.
function load_schedule($conn, $schedule_id)
{
    $stmt = $conn->prepare(
        "SELECT s.*, r.res_name AS restaurant_name, f.farm_name
         FROM recurring_schedules s
         JOIN restaurants r ON r.restaurant_id = s.restaurant_id
         JOIN farms f ON f.farm_id = s.farm_id
         WHERE s.schedule_id = ?"
    );
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$schedule) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT ri.recurring_item_id, ri.product_id, p.name AS product_name,
                ri.quantity_kg, p.price_per_kg,
                ROUND(ri.quantity_kg * p.price_per_kg, 2) AS estimated_subtotal
         FROM recurring_items ri
         JOIN products p ON p.product_id = ri.product_id
         WHERE ri.schedule_id = ?
         ORDER BY ri.recurring_item_id"
    );
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $schedule["items"] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $schedule;
}

// Reads ?id= as a positive int, or ends the request with a 400.
function require_id_param()
{
    $id = $_GET["id"] ?? null;
    if (!is_positive_int($id)) {
        json_error("Valid id required, e.g. ?id=1", 400);
    }
    return (int) $id;
}
