<?php
const NOTIFICATION_DEFAULT_LIMIT = 20;
const NOTIFICATION_MAX_LIMIT = 100;

// Returns the trimmed query value, or false if it was sent as an array.
function notification_param(string $key)
{
    $value = $_GET[$key] ?? "";

    return is_string($value) ? trim($value) : false;
}

/**
 * Validates the parameters shared by the notification endpoints:
 * after_id, limit, farm_id and restaurant_id. Responds with a 422 and stops
 * if any of them is invalid.
 */
function read_event_params(): array
{
    $errors = [];

    $after_id = notification_param("after_id");
    if ($after_id === false
        || ($after_id !== "" && !(ctype_digit($after_id) && strlen($after_id) <= 10))) {
        $errors["after_id"] = "Must be 0 or a positive integer.";
    }

    $limit = notification_param("limit");
    if ($limit === "") {
        $limit = NOTIFICATION_DEFAULT_LIMIT;
    } elseif ($limit === false || !is_positive_int($limit) || $limit > NOTIFICATION_MAX_LIMIT) {
        $errors["limit"] = "Must be between 1 and " . NOTIFICATION_MAX_LIMIT . ".";
    }

    $ids = [];
    foreach (["farm_id", "restaurant_id"] as $key) {
        $value = notification_param($key);
        if ($value === false || ($value !== "" && !is_positive_int($value))) {
            $errors[$key] = "Must be a positive integer.";
        }
        $ids[$key] = $value !== "" && $value !== false ? (int) $value : null;
    }

    if ($errors) {
        json_error("Validation failed.", 422, $errors);
    }

    return [
        "after_id" => $after_id === "" ? null : (int) $after_id,
        "limit" => (int) $limit,
        "farm_id" => $ids["farm_id"],
        "restaurant_id" => $ids["restaurant_id"],
    ];
}

/**
 * Loads one page of events using an id cursor.
 *  - with $after_id: events with a bigger id, oldest first (has_more tells if more are waiting)
 *  - without it:     the newest $limit events, returned oldest first
 *
 * @return array{0: array, 1: bool} [rows, has_more]
 */
function fetch_event_rows(
    mysqli $conn,
    string $select,
    string $from,
    array $where,
    string $types,
    array $params,
    string $id_column,
    ?int $after_id,
    int $limit
): array {
    if ($after_id !== null) {
        $where[] = "$id_column > ?";
        $types .= "i";
        $params[] = $after_id;
        $direction = "ASC";
    } else {
        $direction = "DESC";
    }

    $sql = "SELECT $select FROM $from"
        . ($where ? " WHERE " . implode(" AND ", $where) : "")
        . " ORDER BY $id_column $direction LIMIT ?";
    $types .= "i";
    $params[] = $limit + 1; // one extra row tells us whether more events exist

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($after_id !== null) {
        return [array_slice($rows, 0, $limit), count($rows) > $limit];
    }

    return [array_reverse(array_slice($rows, 0, $limit)), false];
}

/** Sends the standard event-list response. last_id is the cursor for the next poll. */
function send_event_list(array $events, ?int $after_id, bool $has_more): void
{
    $last_id = $events ? $events[count($events) - 1]["event_id"] : ($after_id ?? 0);

    json_response([
        "success" => true,
        "events" => $events,
        "last_id" => $last_id,
        "has_more" => $has_more,
    ]);
}