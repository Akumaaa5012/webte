<?php
// Stock rules shared by summary.php and the notification endpoints.
const LOW_STOCK_KG = 10;

/** Returns "out_of_stock", "low_stock", or null when the stock is fine. */
function stock_level(float $stock_kg): ?string
{
    if ($stock_kg <= 0) {
        return "out_of_stock";
    }

    if ($stock_kg <= LOW_STOCK_KG) {
        return "low_stock";
    }

    return null;
}