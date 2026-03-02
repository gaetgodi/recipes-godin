<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Migrate WordPress Taxonomy Categories to Custom Tables
 * 
 * Usage: php migrate-to-custom-categories.php /path/to/wordpress
 */

// Check command line argument
if ($argc < 2) {
    die("Usage: php migrate-to-custom-categories.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

// Load WordPress
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: Could not load WordPress. Check path: {$wp_path}\n");
}

global $wpdb;

echo "WordPress loaded successfully.\n";
echo "Starting migration to custom category tables...\n\n";

// Step 1: Create tables
echo "Step 1: Creating custom tables...\n";

$sql_create_categories = "
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_categories (
    cat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cat_name VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_category (user_id, cat_name),
    INDEX idx_user_id (user_id),
    INDEX idx_cat_name (cat_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$sql_create_relationships = "
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_category_relationships (
    recipe_id BIGINT UNSIGNED NOT NULL,
    cat_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recipe_id, cat_id),
    INDEX idx_recipe_id (recipe_id),
    INDEX idx_cat_id (cat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$wpdb->query($sql_create_categories);
$wpdb->query($sql_create_relationships);

echo "  ✓ Tables created\n\n";

// Step 2: Migrate categories
echo "Step 2: Migrating categories...\n";

$all_terms = get_terms(array(
    'taxonomy' => 'recipe_category',
    'hide_empty' => false,
));

$category_map = array(); // old_term_id => new_cat_id
$migrated_categories = 0;
$skipped_categories = 0;

foreach ($all_terms as $term) {
    $user_id = get_term_meta($term->term_id, 'user_id', true);
    
    if (empty($user_id)) {
        echo "  - Skipping '{$term->name}' (no user_id)\n";
        $skipped_categories++;
        continue;
    }
    
    // Check if already migrated
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT cat_id FROM {$wpdb->prefix}recipe_categories 
         WHERE user_id = %d AND cat_name = %s",
        $user_id,
        $term->name
    ));
    
    if ($existing) {
        $category_map[$term->term_id] = $existing->cat_id;
        echo "  - Category exists: '{$term->name}' (user: {$user_id})\n";
        continue;
    }
    
    // Insert into custom table
    $wpdb->insert(
        $wpdb->prefix . 'recipe_categories',
        array(
            'cat_name' => $term->name,
            'user_id' => $user_id,
        ),
        array('%s', '%d')
    );
    
    $new_cat_id = $wpdb->insert_id;
    $category_map[$term->term_id] = $new_cat_id;
    
    echo "  ✓ Migrated: '{$term->name}' (user: {$user_id}) -> cat_id: {$new_cat_id}\n";
    $migrated_categories++;
}

echo "\n  Migrated: {$migrated_categories} categories\n";
echo "  Skipped: {$skipped_categories} categories\n\n";

// Step 3: Migrate relationships
echo "Step 3: Migrating recipe-category relationships...\n";

$recipes = get_posts(array(
    'post_type' => 'recipe',
    'posts_per_page' => -1,
    'post_status' => 'any',
));

$migrated_relationships = 0;
$skipped_relationships = 0;

foreach ($recipes as $recipe) {
    $terms = wp_get_post_terms($recipe->ID, 'recipe_category');
    
    if (empty($terms) || is_wp_error($terms)) {
        continue;
    }
    
    foreach ($terms as $term) {
        if (!isset($category_map[$term->term_id])) {
            $skipped_relationships++;
            continue;
        }
        
        $new_cat_id = $category_map[$term->term_id];
        
        // Check if already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}recipe_category_relationships 
             WHERE recipe_id = %d AND cat_id = %d",
            $recipe->ID,
            $new_cat_id
        ));
        
        if ($existing > 0) {
            continue;
        }
        
        // Insert relationship
        $wpdb->insert(
            $wpdb->prefix . 'recipe_category_relationships',
            array(
                'recipe_id' => $recipe->ID,
                'cat_id' => $new_cat_id,
            ),
            array('%d', '%d')
        );
        
        $migrated_relationships++;
    }
    
    if ($migrated_relationships % 50 == 0 && $migrated_relationships > 0) {
        echo "  - Migrated {$migrated_relationships} relationships...\n";
    }
}

echo "\n  ✓ Migrated: {$migrated_relationships} relationships\n";
echo "  Skipped: {$skipped_relationships} relationships\n\n";

// Step 4: Verification
echo "Step 4: Verifying migration...\n";

$total_categories = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}recipe_categories");
$total_relationships = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}recipe_category_relationships");

echo "  ✓ Total categories in new table: {$total_categories}\n";
echo "  ✓ Total relationships in new table: {$total_relationships}\n\n";

// Show sample data
echo "Sample categories:\n";
$samples = $wpdb->get_results("
    SELECT c.cat_id, c.cat_name, c.user_id, u.user_login
    FROM {$wpdb->prefix}recipe_categories c
    LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
    ORDER BY c.cat_id
    LIMIT 10
");

foreach ($samples as $sample) {
    echo "  - {$sample->cat_name} (ID: {$sample->cat_id}, User: {$sample->user_login})\n";
}

echo "\n===========================================\n";
echo "Migration Complete!\n";
echo "===========================================\n";
echo "Categories migrated: {$migrated_categories}\n";
echo "Relationships migrated: {$migrated_relationships}\n";
echo "\n";
echo "Next steps:\n";
echo "1. Update all PHP code to use new tables\n";
echo "2. Test thoroughly\n";
echo "3. Once verified, can delete old WordPress taxonomy data\n";
echo "\n";
