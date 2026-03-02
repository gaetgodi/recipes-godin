<?php
/**
 * Recipe Import Script V3
 * Imports recipes by loading SQL dump into temp tables in WordPress database
 * 
 * Usage: php import-recipes-v3.php /path/to/wordpress username sql_file.sql
 * Example: php import-recipes-v3.php /var/www/vhosts/godin.com/recipes.godin.com terry admin_terry.sql
 */

// Only run if called from command line (not when WordPress loads theme files)
// Check if $argc exists - it's only defined in CLI mode
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return; // Exit silently if loaded by WordPress
}

// Check command line arguments
if ($argc < 4) {
    die("Usage: php import-recipes-v3.php /path/to/wordpress username sql_file.sql\n");
}

$wp_path = $argv[1];
$username = $argv[2];
$sql_file = $argv[3];

// Load WordPress
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: Could not load WordPress. Check path: {$wp_path}\n");
}

echo "WordPress loaded successfully.\n";
echo "Starting recipe import...\n";
echo "  - Target user: {$username}\n";
echo "  - SQL file: {$sql_file}\n";

// Get WordPress database credentials
global $wpdb;

// Set charset to UTF-8 for proper French character handling
$wpdb->query("SET NAMES 'utf8mb4'");
$wpdb->query("SET CHARACTER SET utf8mb4");

if (!file_exists($sql_file)) {
    die("Error: SQL file not found: {$sql_file}\n");
}

// Read and modify SQL file to use temp table names
echo "Reading SQL dump...\n";
$sql_content = file_get_contents($sql_file);

// First, drop any existing unwanted tables from previous imports
echo "Cleaning up old tables if they exist...\n";
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS book, book_toc, login_users, menu");

// Replace table names with temporary ones (ONLY for tables we want)
$temp_prefix = 'temp_import_' . time() . '_';
$sql_content = str_replace('`recipe`', '`' . $temp_prefix . 'recipe`', $sql_content);
$sql_content = str_replace('`catrecipe`', '`' . $temp_prefix . 'catrecipe`', $sql_content);

// Remove ALL unwanted tables from SQL - multiple patterns to catch all formats
$unwanted_tables = ['book', 'book_toc', 'login_users', 'menu'];

foreach ($unwanted_tables as $table) {
    // Pattern 1: DROP TABLE IF EXISTS ... through UNLOCK TABLES
    $sql_content = preg_replace('/DROP TABLE IF EXISTS\s+`?' . $table . '`?;.*?UNLOCK TABLES;/s', '', $sql_content);
    
    // Pattern 2: CREATE TABLE ... through UNLOCK TABLES (if no DROP statement)
    $sql_content = preg_replace('/CREATE TABLE\s+`?' . $table . '`?\s*\(.*?UNLOCK TABLES;/s', '', $sql_content);
    
    // Pattern 3: Just the CREATE TABLE statement without data
    $sql_content = preg_replace('/CREATE TABLE\s+`?' . $table . '`?\s*\([^;]*\);/s', '', $sql_content);
    
    // Pattern 4: LOCK/UNLOCK statements for these tables
    $sql_content = preg_replace('/LOCK TABLES\s+`?' . $table . '`?\s+WRITE;.*?UNLOCK TABLES;/s', '', $sql_content);
    
    echo "  - Removed table: {$table}\n";
}

// Remove all VIEW-related content (they require SUPER privileges and we don't need them)
// Pattern 1: Stand-in structure comments
$sql_content = preg_replace('/--\s*Stand-in structure for view.*?\n/i', '', $sql_content);
// Pattern 2: Stand-in CREATE TABLE for views (lines 789-800 in Terry's file)
$sql_content = preg_replace('/CREATE TABLE `recipe_view`[^;]+;/is', '', $sql_content);
// Pattern 3: Structure for view comments
$sql_content = preg_replace('/--\s*Structure for view.*?\n/i', '', $sql_content);
// Pattern 4: DROP TABLE for views
$sql_content = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+`recipe_view`\s*;/i', '', $sql_content);
// Pattern 5: Actual CREATE VIEW statement (all on one line ending with semicolon)
$sql_content = preg_replace('/CREATE\s+ALGORITHM=\w+\s+DEFINER=`[^`]+`@`[^`]+`\s+SQL\s+SECURITY\s+\w+\s+VIEW\s+`[^`]+`[^;]+;/i', '', $sql_content);
// Pattern 6: Generic DROP VIEW
$sql_content = preg_replace('/DROP\s+VIEW\s+IF\s+EXISTS\s+`?[\w_]+`?\s*;/i', '', $sql_content);
echo "  - Removed all VIEW definitions and stand-in structures\n";

// Save modified SQL
$temp_sql_file = '/tmp/temp_import_' . time() . '.sql';
file_put_contents($temp_sql_file, $sql_content);

echo "Importing data into temporary tables...\n";

// Get database connection details from WordPress
$db_host = DB_HOST;
$db_user = DB_USER;
$db_pass = DB_PASSWORD;
$db_name = DB_NAME;

// Handle host:port format
$host_parts = explode(':', $db_host);
$mysql_host = $host_parts[0];
$mysql_port = isset($host_parts[1]) ? $host_parts[1] : '3306';

// Import using mysql command with UTF-8 charset
$cmd = "mysql -h '{$mysql_host}' -P {$mysql_port} -u '{$db_user}' -p'{$db_pass}' --default-character-set=utf8mb4 '{$db_name}' < '{$temp_sql_file}' 2>&1";
exec($cmd, $output, $return_var);

if ($return_var !== 0) {
    echo "MySQL output: " . implode("\n", $output) . "\n";
    die("Error importing SQL file\n");
}

// Clean up temp SQL file
unlink($temp_sql_file);

echo "Data imported successfully.\n";

// Read categories from temp table
echo "Reading categories...\n";
$categories = [];
$result = $wpdb->get_results("SELECT catID, cat_title FROM `{$temp_prefix}catrecipe`");
foreach ($result as $row) {
    $categories[$row->catID] = $row->cat_title;
}
echo "Found " . count($categories) . " categories\n";

// Read recipes from temp table
echo "Reading recipes...\n";
$recipes = [];
$result = $wpdb->get_results("SELECT recipeID, recipe_title, recipe_ingredients, recipe_method, recipe_note, catID FROM `{$temp_prefix}recipe`");
foreach ($result as $row) {
    $recipes[] = [
        'id' => $row->recipeID,
        'title' => $row->recipe_title,
        'ingredients' => $row->recipe_ingredients,
        'method' => $row->recipe_method,
        'notes' => $row->recipe_note,
        'category_id' => $row->catID
    ];
}
echo "Found " . count($recipes) . " recipes\n\n";

// Clean up temp tables
echo "Cleaning up temporary tables...\n";
$wpdb->query("DROP TABLE IF EXISTS `{$temp_prefix}recipe`");
$wpdb->query("DROP TABLE IF EXISTS `{$temp_prefix}catrecipe`");

// Get WordPress user (username from command line parameter)
$user = get_user_by('login', $username);

if (!$user) {
    echo "User '{$username}' not found. Please create user first.\n";
    exit(1);
}

echo "Using user: {$user->user_login} (ID: {$user->ID})\n\n";

/**
 * Clean HTML formatting
 */
function clean_html($html) {
    if (empty($html)) {
        return '';
    }
    
    // Convert to UTF-8 if needed
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }
    
    // Basic cleanup - preserve structure but clean up
    $html = str_replace(['\r\n', '\n', '\r'], "\n", $html);
    
    // Remove excessive whitespace but preserve intentional spacing
    $html = preg_replace('/\n\s*\n/', "\n\n", $html);
    
    return trim($html);
}

/**
 * Auto-format field content (converts plain text to HTML lists)
 */
function auto_format_field($content, $is_method = false) {
    if (empty($content)) {
        return '';
    }
    
    // If already contains HTML lists, return as-is
    if (strpos($content, '<ul>') !== false || strpos($content, '<ol>') !== false) {
        return $content;
    }
    
    // Split by line breaks
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $lines = array_filter(array_map('trim', $lines)); // Remove empty lines
    
    if (empty($lines)) {
        return '';
    }
    
    // Single line becomes paragraph
    if (count($lines) === 1) {
        return '<p>' . esc_html($lines[0]) . '</p>';
    }
    
    // Multiple lines become list
    $list_items = [];
    foreach ($lines as $line) {
        // Remove leading bullets, dashes (but NOT recipe numbers like "1 cup")
        $line = preg_replace('/^[\-•*]\s*/', '', $line); // Fixed: escaped dash
        $line = preg_replace('/^\d+[\.)]\s*/', '', $line);
        
        if (!empty($line)) {
            $list_items[] = '<li>' . esc_html($line) . '</li>';
        }
    }
    
    if (empty($list_items)) {
        return '';
    }
    
    // Method uses ordered list (numbered), ingredients use unordered (bullets)
    $tag = $is_method ? 'ol' : 'ul';
    return '<' . $tag . '>' . implode('', $list_items) . '</' . $tag . '>';
}

/**
 * Import categories into custom recipe_categories table
 */
function import_categories($categories, $user_id) {
    global $wpdb;
    $category_map = [];
    
    echo "Importing categories for user ID {$user_id}...\n";
    
    foreach ($categories as $old_id => $cat_name) {
        // Check if category exists for this user in custom table
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}recipe_categories 
             WHERE user_id = %d AND cat_name = %s",
            $user_id,
            $cat_name
        ));
        
        if ($existing) {
            // Use existing category
            $category_map[$old_id] = $existing->cat_id;
            echo "  - Category exists: {$cat_name} (ID: {$existing->cat_id})\n";
        } else {
            // Create new category in custom table
            $result = $wpdb->insert(
                $wpdb->prefix . 'recipe_categories',
                array(
                    'cat_name' => $cat_name,
                    'user_id' => $user_id,
                ),
                array('%s', '%d')
            );
            
            if ($result === false) {
                echo "  - Error creating category {$cat_name}: Database error\n";
            } else {
                $cat_id = $wpdb->insert_id;
                $category_map[$old_id] = $cat_id;
                echo "  - Created category: {$cat_name} (ID: {$cat_id})\n";
            }
        }
    }
    
    echo "\n";
    return $category_map;
}

/**
 * Import recipes into WordPress
 */
function import_recipes($recipes, $category_map, $user_id) {
    global $wpdb;
    
    $imported = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "Importing recipes...\n";
    
    foreach ($recipes as $recipe) {
        // Check if recipe already exists (by title)
        $existing = get_posts([
            'post_type' => 'recipe',
            'post_status' => 'any',
            'title' => $recipe['title'],
            'posts_per_page' => 1,
            'author' => $user_id,
        ]);
        
        $post_id = null;
        
        if (!empty($existing)) {
            // Recipe exists - update it
            $post_id = $existing[0]->ID;
            $existing_post = $existing[0];
            echo "  - Updating existing: {$recipe['title']}\n";
            
            // Auto-format and update meta fields
            $ingredients = auto_format_field(clean_html($recipe['ingredients']), false);
            $method = auto_format_field(clean_html($recipe['method']), true);
            $notes = clean_html($recipe['notes']); // Notes stay as paragraph
            
            update_post_meta($post_id, '_recipe_ingredients', $ingredients);
            update_post_meta($post_id, '_recipe_method', $method);
            update_post_meta($post_id, '_recipe_notes', $notes);
            
            // Assign category using custom table
            if (!empty($recipe['category_id']) && isset($category_map[$recipe['category_id']])) {
                $cat_id = $category_map[$recipe['category_id']];
                
                // Remove existing category relationships for this recipe
                $wpdb->delete(
                    $wpdb->prefix . 'recipe_category_relationships',
                    array('recipe_id' => $post_id),
                    array('%d')
                );
                
                // Add new relationship
                $wpdb->insert(
                    $wpdb->prefix . 'recipe_category_relationships',
                    array(
                        'recipe_id' => $post_id,
                        'cat_id' => $cat_id
                    ),
                    array('%d', '%d')
                );
            }
            
            // Update the post with preserved dates
            wp_update_post([
                'ID' => $post_id,
                'post_modified' => current_time('mysql'), // Set modified to now
                'post_modified_gmt' => current_time('mysql', 1),
            ]);
            
            $imported++;
        } else {
            // Recipe doesn't exist - create it
            // Prepare post data
            $post_data = [
                'post_title' => $recipe['title'],
                'post_type' => 'recipe',
                'post_status' => 'publish',
                'post_author' => $user_id,
            ];
            
            // Insert post
            $post_id = wp_insert_post($post_data);
            
            if (is_wp_error($post_id)) {
                echo "  - Error: {$recipe['title']} - " . $post_id->get_error_message() . "\n";
                $errors++;
                continue;
            }
            
            // Save meta fields with auto-formatting
            $ingredients = auto_format_field(clean_html($recipe['ingredients']), false);
            $method = auto_format_field(clean_html($recipe['method']), true);
            $notes = clean_html($recipe['notes']);
            
            update_post_meta($post_id, '_recipe_ingredients', $ingredients);
            update_post_meta($post_id, '_recipe_method', $method);
            update_post_meta($post_id, '_recipe_notes', $notes);
            
            // Assign category using custom table
            if (!empty($recipe['category_id']) && isset($category_map[$recipe['category_id']])) {
                $cat_id = $category_map[$recipe['category_id']];
                
                // Add relationship
                $wpdb->insert(
                    $wpdb->prefix . 'recipe_category_relationships',
                    array(
                        'recipe_id' => $post_id,
                        'cat_id' => $cat_id
                    ),
                    array('%d', '%d')
                );
            }
            
            $imported++;
            echo "  - Imported: {$recipe['title']}\n";
        }
        
        // Progress indicator
        if ($imported % 10 == 0) {
            echo "  - Imported {$imported} recipes...\n";
        }
    }
    
    echo "\n";
    echo "Import Summary:\n";
    echo "  - Imported: {$imported}\n";
    echo "  - Skipped (duplicates): {$skipped}\n";
    echo "  - Errors: {$errors}\n";
    echo "  - Total recipes: " . count($recipes) . "\n";
}

// Import
$category_map = import_categories($categories, $user->ID);
import_recipes($recipes, $category_map, $user->ID);

echo "\nImport complete!\n";