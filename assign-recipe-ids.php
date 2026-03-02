<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Assign Permanent Recipe IDs
 * 
 * One-time script to assign permanent IDs to all recipes based on post ID
 * 
 * Usage: php assign-recipe-ids.php /path/to/wordpress
 */

if ($argc < 2) {
    die("Usage: php assign-recipe-ids.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

echo "Assign Permanent Recipe IDs\n";
echo "============================\n\n";

// Get all recipes
$args = array(
    'post_type' => 'recipe',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'orderby' => 'ID',
    'order' => 'ASC',
);

$recipes = new WP_Query($args);

if (!$recipes->have_posts()) {
    die("No recipes found.\n");
}

echo "Found " . $recipes->post_count . " recipes\n\n";
echo "Assigning permanent IDs based on WordPress post ID...\n\n";

$assigned = 0;

while ($recipes->have_posts()) {
    $recipes->the_post();
    $post_id = get_the_ID();
    
    // Check if already has an ID
    $existing_id = get_post_meta($post_id, '_recipe_id', true);
    
    // Generate ID based on post ID (e.g., post 7 = R007, post 142 = R142)
    $recipe_id = 'R' . str_pad($post_id, 4, '0', STR_PAD_LEFT);
    
    if ($existing_id && $existing_id === $recipe_id) {
        // Already has correct ID
        continue;
    }
    
    // Assign new permanent ID
    update_post_meta($post_id, '_recipe_id', $recipe_id);
    
    echo "  Post ID {$post_id}: " . get_the_title() . " → {$recipe_id}\n";
    $assigned++;
}

wp_reset_postdata();

echo "\n";
echo "========================================\n";
echo "Assigned permanent IDs to {$assigned} recipes\n";
echo "========================================\n\n";
echo "✅ Done! Recipe IDs are now permanent and will never change.\n";
echo "\nExamples:\n";
echo "  Post ID 7 → R0007\n";
echo "  Post ID 142 → R0142\n";
echo "  Post ID 1523 → R1523\n";
