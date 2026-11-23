<?php
const DELIVERY_STATUSES = [
    "pending", "assigned", "picked_up", "in_transit", "delivered", "cancelled"
];

const DELIVERY_TRANSITIONS = [
    "pending" => ["assigned", "cancelled"],
    "assigned" => ["picked_up", "cancelled"],
    "picked_up" => ["in_transit", "cancelled"],
    "in_transit" => ["delivered", "cancelled"],
    "delivered" => [],
    "cancelled" => []
];

const ORDER_STATUS_FOR_DELIVERY = [
    "picked_up" => "in_transit",
    "in_transit" => "in_transit",
    "delivered" => "delivered"
];

const DELIVERABLE_ORDER_STATUS = "confirmed";
const TRACKABLE_STATUSES = ["assigned", "picked_up", "in_transit"];
function positive_id($value): ?int
{
    if (is_bool($value) || !is_positive_int($value)) {
        return null;
    }

    return (int) $value;
}

/**
 * Reads delivery_lat / delivery_lng from the request data.
 *
 * @return array{0: ?float, 1: ?float, 2: ?string} [lat, lng, error message]
 */
function read_coordinates(array $data, bool $required): array
{
    $lat = $data["delivery_lat"] ?? null;
    $lng = $data["delivery_lng"] ?? null;

    if ($lat === null && $lng === null) {
        return $required
            ? [null, null, "delivery_lat and delivery_lng are required."]
            : [null, null, null];
    }

    if ($lat === null || $lng === null) {
        return [null, null, "delivery_lat and delivery_lng must be sent together."];
    }

    if (!is_coordinate($lat, -90, 90) || !is_coordinate($lng, -180, 180)) {
        return [null, null, "delivery_lat and delivery_lng must be valid coordinates."];
    }

    $lat = (float) $lat;
    $lng = (float) $lng;

    if (!is_in_service_area($lat, $lng)) {
        return [null, null, "The position is outside the service area."];
    }

    return [$lat, $lng, null];
}

/**
 * Checks that a provider exists and may deliver an order from the given farm.
 * Farm-managed providers can only deliver their own farm's orders.
 * Returns an error message, or null if the provider is fine.
 */
function provider_error_for_order(mysqli $conn, int $providerId, int $orderFarmId): ?string
{
    $stmt = $conn->prepare(
        "SELECT provider_type, farm_id FROM logistics_providers WHERE provider_id = ?"
    );
    $stmt->bind_param("i", $providerId);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$provider) {
        return "provider_id does not exist.";
    }

    if ($provider["provider_type"] === "farm_managed" &&
        (int) $provider["farm_id"] !== $orderFarmId) {
        return "This farm-managed provider can only deliver orders from its own farm.";
    }

    return null;
}