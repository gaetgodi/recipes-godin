<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Allergen Checker v2 — Create the recipe-to-product attachment table.
 *
 * Lets a recipe carry a durable list of attached candidate products
 * (from the global allergen_products library) alongside its ingredient
 * list. Mirrors recipe_category_relationships exactly — same
 * many-to-many join-table pattern already used for recipe categories.
 *
 * Usage: php migrate-recipe-products-table.php /path/to/wordpress
 */

if ($argc < 2) {
    die("Usage: php migrate-recipe-products-table.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: Could not load WordPress. Check path: {$wp_path}\n");
}

global $wpdb;

echo "WordPress loaded successfully.\n";
echo "Creating allergen_recipe_products table...\n\n";

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_recipe_products (
    recipe_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recipe_id, product_id),
    INDEX idx_recipe_id (recipe_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "  \xE2\x9C\x93 Table created\n\n";

$exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}allergen_recipe_products'");

if ($exists) {
    echo "===========================================\n";
    echo "allergen_recipe_products table ready.\n";
    echo "===========================================\n";
} else {
    echo "ERROR: table was not created. Check DB permissions.\n";
}
