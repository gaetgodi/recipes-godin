<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Recipe Gallery — Create the recipe_photos table.
 *
 * Stores up to 10 additional photos per recipe, separate from the featured
 * image (post thumbnail). Photos display in upload order (display_order),
 * no captions, no drag-reorder. Mirrors the standalone-CREATE-TABLE pattern
 * used by migrate-recipe-products-table.php / migrate-allergen-checker.php.
 *
 * Usage: php migrate-recipe-photos.php /path/to/wordpress
 */

if ($argc < 2) {
    die("Usage: php migrate-recipe-photos.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: Could not load WordPress. Check path: {$wp_path}\n");
}

global $wpdb;

echo "WordPress loaded successfully.\n";
echo "Creating recipe_photos table...\n\n";

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_photos (
    photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipe_id BIGINT UNSIGNED NOT NULL,
    photo_url VARCHAR(500) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    uploaded_at DATETIME NOT NULL,
    PRIMARY KEY (photo_id),
    INDEX idx_recipe_id (recipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "  [OK] Table created\n\n";

$exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}recipe_photos'");

if ($exists) {
    echo "===========================================\n";
    echo "recipe_photos table ready.\n";
    echo "===========================================\n";
} else {
    echo "ERROR: table was not created. Check DB permissions.\n";
}
