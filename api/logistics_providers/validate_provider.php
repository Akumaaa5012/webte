<?php
// Allowed values for logistics_providers.provider_type.
const PROVIDER_TYPES = ["farm_managed", "third_party"];

function provider_text($value, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return ($value === "" || mb_strlen($value) > $max) ? null : $value;
}

/**
 * Validates provider input shared by create.php and update.php.
 *
 * Rules:
 *  - farm_managed: farm_id is required (the farm runs its own deliveries)
 *  - third_party:  farm_id must be empty (an independent service)
 *  - user_id is optional (the logistics account linked to the provider)
 *
 * $requireAllKeys = true makes user_id and farm_id mandatory keys (null allowed),
 * so a full update can't wipe them by accident.
 *
 * @return array{values: ?array, error: ?string}
 */
function validate_provider_input(array $data, bool $requireAllKeys = false): array
{
    if ($requireAllKeys) {
        foreach (["user_id", "farm_id"] as $key) {
            if (!array_key_exists($key, $data)) {
                return [
                    "values" => null,
                    "error" => "$key is required (use null for none)"
                ];
            }
        }
    }

    $name = provider_text($data["provider_name"] ?? null, 100);

    if ($name === null) {
        return [
            "values" => null,
            "error" => "provider_name is required (max 100 characters)"
        ];
    }

    $contact = provider_text($data["contact_no"] ?? null, 15);

    if ($contact === null) {
        return [
            "values" => null,
            "error" => "contact_no is required (max 15 characters)"
        ];
    }

    $type = $data["provider_type"] ?? null;

    if (!is_string($type) || !in_array($type, PROVIDER_TYPES, true)) {
        return [
            "values" => null,
            "error" => "provider_type must be one of: " . implode(", ", PROVIDER_TYPES)
        ];
    }

    $ids = [];

    foreach (["user_id", "farm_id"] as $key) {
        $raw = $data[$key] ?? null;

        if ($raw === null || $raw === "") {
            $ids[$key] = null;
            continue;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        if (!$value || $value < 1) {
            return [
                "values" => null,
                "error" => "$key must be a positive integer or null"
            ];
        }

        $ids[$key] = $value;
    }

    if ($type === "farm_managed" && $ids["farm_id"] === null) {
        return [
            "values" => null,
            "error" => "farm_managed providers require a farm_id"
        ];
    }

    if ($type === "third_party" && $ids["farm_id"] !== null) {
        return [
            "values" => null,
            "error" => "third_party providers must not have a farm_id"
        ];
    }

    return [
        "values" => [
            "user_id" => $ids["user_id"],
            "farm_id" => $ids["farm_id"],
            "provider_name" => $name,
            "provider_type" => $type,
            "contact_no" => $contact
        ],
        "error" => null
    ];
}