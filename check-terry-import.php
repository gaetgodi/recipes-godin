<?php
/**
 * Check Terry's Import Status
 * Usage: php check-terry-import.php /path/to/wordpress
 */

// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}

if ($argc < 2) {
    die("Usage: php check-terry-import.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

// Load WordPress
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

global $wpdb;

// Get Terry's user ID
$terry = get_user_by('login', 'terry');
if (!$terry) {
    echo "Terry user not found\n";
    exit(1);
}

echo "Terry user ID: {$terry->ID}\n\n";

// Count Terry's recipes
$recipe_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'recipe' AND post_author = %d AND post_status = 'publish'",
    $terry->ID
));
echo "Terry's recipes: {$recipe_count}\n\n";

// Check custom categories for Terry
$categories = $wpdb->get_results(
    "SELECT * FROM wp_recipe_categories WHERE user_id = " . $terry->ID
);
echo "Custom categories for Terry: " . count($categories) . "\n";
foreach ($categories as $cat) {
    echo "  - {$cat->cat_name} (ID: {$cat->cat_id})\n";
}
echo "\n";

// Check category relationships
$relationships = $wpdb->get_results(
    "SELECT COUNT(*) as cnt, c.cat_name 
    FROM wp_recipe_category_relationships r
    JOIN wp_recipe_categories c ON r.cat_id = c.cat_id
    WHERE c.user_id = " . $terry->ID . "
    GROUP BY r.cat_id, c.cat_name"
);

echo "Recipes per category:\n";
foreach ($relationships as $rel) {
    echo "  - {$rel->cat_name}: {$rel->cnt} recipes\n";
}

// Check if any recipes have no categories
$no_cats = $wpdb->get_results($wpdb->prepare(
    "SELECT p.ID, p.post_title 
    FROM $wpdb->posts p
    LEFT JOIN wp_recipe_category_relationships r ON p.ID = r.recipe_id
    WHERE p.post_type = 'recipe' 
    AND p.post_author = %d 
    AND p.post_status = 'publish'
    AND r.recipe_id IS NULL
    LIMIT 10",
    $terry->ID
));

if (!empty($no_cats)) {
    echo "\nRecipes WITHOUT categories (" . count($no_cats) . " found):\n";
    foreach ($no_cats as $recipe) {
        echo "  - {$recipe->post_title} (ID: {$recipe->ID})\n";
    }
}

echo "\nDone!\n";
?>
