# SproutRoute API (B1 lookups)

Simple JSON endpoints for shared lookup data. These files do **not** handle login, product CRUD, orders, logistics, or recurring-order logic.

Default WAMP URL (adjust the folder name if needed):

`http://localhost/312team-inttowinit-mid-prototype/`

All responses use `Content-Type: application/json`.

Success:

```json
{ "ok": true }
```

Error:

```json
{ "ok": false, "error": "Message for developers." }
```

Each lookup row includes both a short `id` / `name` (used by the seller page) and the actual database column names.

---

## GET `api/lookups.php`

Returns farms, categories, and restaurants in one response.

Optional query values:

- `?action=categories`
- `?action=farms`
- `?action=restaurants`

Example:

```json
{
  "ok": true,
  "categories": [
    { "id": 1, "category_id": 1, "name": "Vegetables" }
  ],
  "farms": [
    {
      "id": 1,
      "farm_id": 1,
      "user_id": 1,
      "name": "Benguet Highland Farm",
      "farm_name": "Benguet Highland Farm",
      "owner_name": "Ana Mendoza",
      "contact_no": "09171234567",
      "address": "La Trinidad, Benguet"
    }
  ],
  "restaurants": [
    {
      "id": 1,
      "restaurant_id": 1,
      "user_id": 3,
      "name": "Baguio Kitchen Co.",
      "res_name": "Baguio Kitchen Co.",
      "res_owner_name": "Liza Cruz",
      "res_contact_no": "09181234567",
      "res_address": "Session Road, Baguio City"
    }
  ]
}
```

---

## POST `api/lookups.php?action=add_category`

JSON body:

```json
{ "name": "Vegetables" }
```

---

## POST `api/lookups.php?action=add_farm`

The `farms` table requires more than a farm name. JSON body:

```json
{
  "user_id": 1,
  "farm_name": "Benguet Highland Farm",
  "owner_name": "Ana Mendoza",
  "contact_no": "09171234567",
  "address": "La Trinidad, Benguet"
}
```

`name` is accepted as an alias for `farm_name`.

Note: the current seller page only sends `{ "name": "..." }`. That request will return a validation error until another member updates the form. B1 does not change the table structure or the frontend.

---

## GET `api/categories.php`

- All categories: `api/categories.php`
- One category: `api/categories.php?id=1`

---

## GET `api/farms.php`

- All farms: `api/farms.php`
- One farm: `api/farms.php?id=1`

---

## GET `api/restaurants.php`

- All restaurants: `api/restaurants.php`
- One restaurant: `api/restaurants.php?id=1`

---

## Connection

`api/db.php` uses the existing WAMP settings:

- Host: `localhost`
- User: `root`
- Password: *(empty)*
- Database: `SproutRoute`
- Port: `3306`

Shared helpers live in `api/common.php`.
