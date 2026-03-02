<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Delete Numeric Categories
 * 
 * Removes categories with numeric names only
 * 
 * Usage: php delete-numeric-categories.php /path/to/wordpress
 */

if ($argc < 2) {
    die("Usage: php delete-numeric-categories.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

echo "Delete Numeric Categories\n";
echo "==========================\n\n";

// Get all categories
$all_cats = get_terms(array(
    'taxonomy' => 'recipe_category',
    'hide_empty' => false,
));

echo "Checking categories...\n\n";

$numeric_cats = array();
$named_cats = array();

foreach ($all_cats as $cat) {
    if (is_numeric($cat->name)) {
        $numeric_cats[] = $cat;
        echo "  NUMERIC: ID {$cat->term_id}, Name '{$cat->name}' ({$cat->count} recipes)\n";
    } else {
        $named_cats[] = $cat;
    }
}

echo "\n";
echo "Found " . count($numeric_cats) . " numeric categories (will delete)\n";
echo "Found " . count($named_cats) . " named categories (will keep)\n\n";

if (empty($numeric_cats)) {
    echo "✅ No numeric categories found! Nothing to delete.\n";
    exit(0);
}

echo "This will delete these categories:\n";
foreach ($numeric_cats as $cat) {
    echo "  - Term ID {$cat->term_id}: '{$cat->name}'\n";
}

echo "\nRecipes in these categories will be UNASSIGNED (no category).\n";
echo "You should re-run the import script after this to reassign proper categories.\n\n";

echo "Proceed? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$answer = trim(strtolower($line));
fclose($handle);

if ($answer !== 'yes') {
    die("\nCancelled. No changes made.\n");
}

echo "\nDeleting numeric categories...\n";

$deleted = 0;
foreach ($numeric_cats as $cat) {
    $result = wp_delete_term($cat->term_id, 'recipe_category');
    
    if ($result && !is_wp_error($result)) {
        echo "  ✓ Deleted: '{$cat->name}' (term_id {$cat->term_id})\n";
        $deleted++;
    } else {
        $error = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
        echo "  ✗ Error deleting '{$cat->name}': {$error}\n";
    }
}

echo "\n";
echo "========================================\n";
echo "Deleted {$deleted} numeric categories\n";
echo "========================================\n\n";
echo "✅ Done!\n\n";
echo "Next step: Re-run the import script to reassign proper categories:\n";
echo "  php import-recipes-v3.php /path/to/wordpress\n";
