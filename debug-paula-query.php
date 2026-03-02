<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Debug Paula's Recipe Query
 */

if ($argc < 2) {
    die("Usage: php debug-paula-query.php /path/to/wordpress\n");
}

$wp_path = $argv[1];
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

global $wpdb;

echo "=== DEBUGGING PAULA'S COLLECTION QUERY ===\n\n";

$paula_id = 6;

// Get all Paula's recipe IDs from posts table
$all_paula_recipes = $wpdb->get_col($wpdb->prepare("
    SELECT ID FROM {$wpdb->posts} 
    WHERE post_type = 'recipe' 
    AND post_author = %d
    AND post_status = 'publish'
    ORDER BY post_title
", $paula_id));

echo "Paula's recipes from posts table: " . count($all_paula_recipes) . "\n";
echo "IDs: " . implode(', ', $all_paula_recipes) . "\n\n";

// Test WP_Query without filter
$query1 = new WP_Query(array(
    'post_type' => 'recipe',
    'posts_per_page' => -1,
    'author' => $paula_id,
    'orderby' => 'title',
    'order' => 'ASC',
));

echo "WP_Query (no category filter):\n";
echo "  Found: {$query1->found_posts}\n";
echo "  Post count: {$query1->post_count}\n\n";

// Get soups category
$soups_cat = $wpdb->get_var($wpdb->prepare("
    SELECT cat_id FROM {$wpdb->prefix}recipe_categories 
    WHERE cat_name = 'soups' AND user_id = %d
", $paula_id));

echo "Paula's 'soups' category ID: {$soups_cat}\n";

// Get recipe IDs in soups category
$soups_recipe_ids = $wpdb->get_col($wpdb->prepare("
    SELECT recipe_id FROM {$wpdb->prefix}recipe_category_relationships 
    WHERE cat_id = %d
", $soups_cat));

echo "Recipe IDs in soups category: " . count($soups_recipe_ids) . "\n";
echo "IDs: " . implode(', ', $soups_recipe_ids) . "\n\n";

// Test WP_Query WITH soups filter
$query2 = new WP_Query(array(
    'post_type' => 'recipe',
    'posts_per_page' => -1,
    'author' => $paula_id,
    'post__in' => $soups_recipe_ids,
    'orderby' => 'title',
    'order' => 'ASC',
));

echo "WP_Query (with soups category filter):\n";
echo "  Found: {$query2->found_posts}\n";
echo "  Post count: {$query2->post_count}\n\n";

// Find the missing recipe
$missing = array_diff($all_paula_recipes, $soups_recipe_ids);
echo "Recipes NOT in any category: " . count($missing) . "\n";
if (!empty($missing)) {
    foreach ($missing as $recipe_id) {
        $title = get_the_title($recipe_id);
        echo "  - ID {$recipe_id}: {$title}\n";
        
        // Check what categories it HAS
        $cats = $wpdb->get_results($wpdb->prepare("
            SELECT c.cat_id, c.cat_name 
            FROM {$wpdb->prefix}recipe_categories c
            INNER JOIN {$wpdb->prefix}recipe_category_relationships r ON c.cat_id = r.cat_id
            WHERE r.recipe_id = %d
        ", $recipe_id));
        
        echo "    Categories: ";
        if (empty($cats)) {
            echo "NONE\n";
        } else {
            echo implode(', ', array_map(function($c) { return $c->cat_name; }, $cats)) . "\n";
        }
    }
}

// Check Mulligatawny specifically
echo "\n=== MULLIGATAWNY CHECK ===\n";
$mull = $wpdb->get_row("
    SELECT ID, post_title, post_author 
    FROM {$wpdb->posts} 
    WHERE post_title LIKE '%Mulligatawny%' 
    AND post_author = {$paula_id}
");

if ($mull) {
    echo "Found: ID {$mull->ID}, Author {$mull->post_author}\n";
    
    $cats = $wpdb->get_results($wpdb->prepare("
        SELECT c.cat_id, c.cat_name 
        FROM {$wpdb->prefix}recipe_categories c
        INNER JOIN {$wpdb->prefix}recipe_category_relationships r ON c.cat_id = r.cat_id
        WHERE r.recipe_id = %d
    ", $mull->ID));
    
    echo "Categories: ";
    foreach ($cats as $cat) {
        echo "{$cat->cat_name} (ID: {$cat->cat_id}) ";
    }
    echo "\n";
    
    echo "In all_paula_recipes? " . (in_array($mull->ID, $all_paula_recipes) ? 'YES' : 'NO') . "\n";
    echo "In soups_recipe_ids? " . (in_array($mull->ID, $soups_recipe_ids) ? 'YES' : 'NO') . "\n";
}
