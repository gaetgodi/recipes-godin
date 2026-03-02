<?php
/**
 * Cleanup Orphaned Category Relationships
 * 
 * Removes relationships that point to deleted recipes
 * 
 * Usage: php cleanup-orphaned-relationships.php /path/to/wordpress
 */

// Only run from command line
if (!isset($argc) || php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

if ($argc < 2) {
    die("Usage: php cleanup-orphaned-relationships.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

// Load WordPress
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

echo "Cleaning up orphaned category relationships...\n\n";

global $wpdb;

// Find orphaned relationships (recipe_id doesn't exist in posts table)
$orphaned = $wpdb->get_results("
    SELECT r.recipe_id, COUNT(*) as count
    FROM {$wpdb->prefix}recipe_category_relationships r
    LEFT JOIN {$wpdb->posts} p ON r.recipe_id = p.ID
    WHERE p.ID IS NULL
    GROUP BY r.recipe_id
");

if (empty($orphaned)) {
    echo "✅ No orphaned relationships found. Database is clean!\n";
    exit(0);
}

echo "Found orphaned relationships for " . count($orphaned) . " deleted recipes:\n";
foreach ($orphaned as $item) {
    echo "  - Recipe ID {$item->recipe_id}: {$item->count} orphaned relationships\n";
}

echo "\nDeleting orphaned relationships...\n";

// Delete all orphaned relationships
$deleted = $wpdb->query("
    DELETE r FROM {$wpdb->prefix}recipe_category_relationships r
    LEFT JOIN {$wpdb->posts} p ON r.recipe_id = p.ID
    WHERE p.ID IS NULL
");

echo "✅ Deleted {$deleted} orphaned relationships\n";
echo "\nCleanup complete!\n";
?>
