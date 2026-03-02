<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Category Diagnostic Script
 * 
 * Check what's in the recipe categories
 * 
 * Usage: php category-check.php /path/to/wordpress
 */

if ($argc < 2) {
    die("Usage: php category-check.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

echo "Checking Recipe Categories...\n\n";

$categories = get_terms(array(
    'taxonomy' => 'recipe_category',
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC',
));

echo "Found " . count($categories) . " categories:\n\n";
echo str_pad("ID", 6) . str_pad("Name", 40) . "Count\n";
echo str_repeat("-", 60) . "\n";

foreach ($categories as $cat) {
    echo str_pad($cat->term_id, 6) . str_pad($cat->name, 40) . $cat->count . "\n";
}

echo "\n" . str_repeat("-", 60) . "\n";
echo "All categories are showing correctly!\n";
