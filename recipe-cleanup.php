<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Recipe Cleanup Script
 * 
 * One-time script to convert all plain text recipes to formatted HTML lists
 * 
 * Usage: php recipe-cleanup.php /path/to/wordpress
 */

// Check command line argument
if ($argc < 2) {
    die("Usage: php recipe-cleanup.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

// Load WordPress
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: Could not load WordPress. Check path: {$wp_path}\n");
}

echo "WordPress loaded successfully.\n";
echo "Starting recipe cleanup...\n\n";

/**
 * Auto-format recipe text into lists
 */
function format_recipe_text($content, $type = 'ul') {
    if (empty($content)) {
        return '';
    }
    
    // If already has HTML list tags, return as-is
    if (strpos($content, '<ul>') !== false || strpos($content, '<ol>') !== false) {
        echo "  - Already formatted, skipping\n";
        return $content;
    }
    
    // Remove extra whitespace
    $content = trim($content);
    
    // Split by line breaks
    $lines = preg_split('/\r\n|\r|\n/', $content);
    
    // Filter out empty lines
    $lines = array_filter(array_map('trim', $lines), function($line) {
        return !empty($line);
    });
    
    // If only one line, wrap in paragraph
    if (count($lines) <= 1) {
        return '<p>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    
    // Convert to list
    $tag = ($type === 'ol') ? 'ol' : 'ul';
    $list = "<{$tag}>\n";
    
    foreach ($lines as $line) {
        // Remove any leading dashes, bullets, or numbers
        $line = preg_replace('/^[-•*\d+\.\)]\s*/', '', $line);
        $line = trim($line);
        $list .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>' . "\n";
    }
    
    $list .= "</{$tag}>";
    
    return $list;
}

// Get all recipes
$args = array(
    'post_type' => 'recipe',
    'posts_per_page' => -1,
    'post_status' => 'any',
);

$recipes = new WP_Query($args);

if (!$recipes->have_posts()) {
    die("No recipes found.\n");
}

echo "Found " . $recipes->post_count . " recipes to process.\n\n";

$updated = 0;
$skipped = 0;
$errors = 0;

while ($recipes->have_posts()) {
    $recipes->the_post();
    $post_id = get_the_ID();
    
    echo "Processing: " . get_the_title() . " (ID: {$post_id})\n";
    
    $changed = false;
    
    // Process ingredients
    $ingredients = get_post_meta($post_id, '_recipe_ingredients', true);
    if (!empty($ingredients)) {
        echo "  - Formatting ingredients...\n";
        $formatted_ingredients = format_recipe_text($ingredients, 'ul');
        
        if ($formatted_ingredients !== $ingredients) {
            update_post_meta($post_id, '_recipe_ingredients', $formatted_ingredients);
            $changed = true;
        }
    }
    
    // Process method
    $method = get_post_meta($post_id, '_recipe_method', true);
    if (!empty($method)) {
        echo "  - Formatting method...\n";
        $formatted_method = format_recipe_text($method, 'ol');
        
        if ($formatted_method !== $method) {
            update_post_meta($post_id, '_recipe_method', $formatted_method);
            $changed = true;
        }
    }
    
    // Process notes (keep as paragraphs)
    $notes = get_post_meta($post_id, '_recipe_notes', true);
    if (!empty($notes) && strpos($notes, '<p>') === false) {
        echo "  - Formatting notes...\n";
        $formatted_notes = '<p>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>';
        update_post_meta($post_id, '_recipe_notes', $formatted_notes);
        $changed = true;
    }
    
    if ($changed) {
        $updated++;
        echo "  ✓ Updated\n";
    } else {
        $skipped++;
        echo "  - Skipped (no changes needed)\n";
    }
    
    echo "\n";
}

wp_reset_postdata();

echo "\n===========================================\n";
echo "Cleanup Complete!\n";
echo "===========================================\n";
echo "Updated: {$updated} recipes\n";
echo "Skipped: {$skipped} recipes\n";
echo "Errors: {$errors}\n";
echo "Total: " . $recipes->post_count . " recipes\n";
echo "\n";
echo "All recipes have been formatted with proper HTML lists!\n";
