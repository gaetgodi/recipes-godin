<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Debug Mulligatawny Recipe
 * 
 * Usage: php debug-mulligatawny.php /path/to/wordpress
 */

if ($argc < 2) {
    die("Usage: php debug-mulligatawny.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

global $wpdb;

echo "=== DEBUGGING MULLIGATAWNY ===\n\n";

// Find the recipe
$recipes = $wpdb->get_results("
    SELECT ID, post_title, post_author, post_status, post_type
    FROM {$wpdb->posts}
    WHERE post_title LIKE '%Mulligatawny%'
    AND post_type = 'recipe'
");

if (empty($recipes)) {
    echo "ERROR: No Mulligatawny recipe found!\n";
    exit;
}

foreach ($recipes as $recipe) {
    echo "Recipe Found:\n";
    echo "  ID: {$recipe->ID}\n";
    echo "  Title: {$recipe->post_title}\n";
    echo "  Author: {$recipe->post_author}\n";
    echo "  Status: {$recipe->post_status}\n";
    echo "  Type: {$recipe->post_type}\n\n";
    
    // Get categories from custom table
    $cats = $wpdb->get_results($wpdb->prepare("
        SELECT c.cat_id, c.cat_name, c.user_id
        FROM {$wpdb->prefix}recipe_categories c
        INNER JOIN {$wpdb->prefix}recipe_category_relationships r ON c.cat_id = r.cat_id
        WHERE r.recipe_id = %d
    ", $recipe->ID));
    
    echo "Categories from custom tables:\n";
    if (empty($cats)) {
        echo "  NONE!\n";
    } else {
        foreach ($cats as $cat) {
            echo "  - {$cat->cat_name} (ID: {$cat->cat_id}, User: {$cat->user_id})\n";
        }
    }
    echo "\n";
    
    // Check if it appears in a simple WP_Query
    $query = new WP_Query(array(
        'post_type' => 'recipe',
        'p' => $recipe->ID,
    ));
    
    echo "WP_Query test (by ID):\n";
    echo "  Found: " . ($query->have_posts() ? 'YES' : 'NO') . "\n";
    echo "  Post count: {$query->post_count}\n\n";
    
    // Check with author filter
    $query2 = new WP_Query(array(
        'post_type' => 'recipe',
        'author' => $recipe->post_author,
        'posts_per_page' => -1,
    ));
    
    echo "WP_Query test (all recipes by author {$recipe->post_author}):\n";
    echo "  Found posts: {$query2->found_posts}\n";
    
    $found = false;
    while ($query2->have_posts()) {
        $query2->the_post();
        if (get_the_ID() == $recipe->ID) {
            $found = true;
            break;
        }
    }
    wp_reset_postdata();
    
    echo "  Mulligatawny in results: " . ($found ? 'YES' : 'NO') . "\n\n";
    
    // Check category relationship IDs
    $cat_ids = $wpdb->get_col($wpdb->prepare("
        SELECT cat_id FROM {$wpdb->prefix}recipe_category_relationships WHERE recipe_id = %d
    ", $recipe->ID));
    
    if (!empty($cat_ids)) {
        echo "Testing category filter for soups:\n";
        $soups_cat = $wpdb->get_var("SELECT cat_id FROM {$wpdb->prefix}recipe_categories WHERE cat_name = 'soups' AND user_id = {$recipe->post_author}");
        
        if ($soups_cat) {
            echo "  Soups category ID: {$soups_cat}\n";
            echo "  Recipe has soups? " . (in_array($soups_cat, $cat_ids) ? 'YES' : 'NO') . "\n";
            
            $test_ids = $wpdb->get_col($wpdb->prepare("
                SELECT recipe_id FROM {$wpdb->prefix}recipe_category_relationships WHERE cat_id = %d
            ", $soups_cat));
            
            echo "  Recipe in soups query? " . (in_array($recipe->ID, $test_ids) ? 'YES' : 'NO') . "\n";
            echo "  Total recipes in soups: " . count($test_ids) . "\n";
        }
    }
}
