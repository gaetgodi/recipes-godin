<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Test French Character Encoding
 * Tests if accented characters display correctly
 * 
 * Usage: Upload to WordPress root, access via browser
 */

// Load WordPress
define('WP_USE_THEMES', false);
require_once('./wp-load.php');

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Test caractères français</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; }
        .test-box { background: #f0f0f0; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
<h1>Test des caractères français accentués</h1>

<div class="test-box">
<h2>Test 1: Affichage direct</h2>
<p><strong>Caractères à afficher:</strong></p>
<p style="font-size: 20px;">
    à â é è ê ë ï î ô ù û ü ÿ ç<br>
    À Â É È Ê Ë Ï Î Ô Ù Û Ü Ÿ Ç
</p>
<p><strong>Exemples de mots:</strong></p>
<p style="font-size: 18px;">
    Crème brûlée • Bœuf bourguignon • Tarte tatin • Crêpes • Pâté • Naïve • Noël • Français
</p>
</div>

<div class="test-box">
<h2>Test 2: Base de données WordPress</h2>
<?php
global $wpdb;

// Test database charset
$charset = $wpdb->get_var("SELECT @@character_set_database");
$collation = $wpdb->get_var("SELECT @@collation_database");

echo "<p><strong>Database Charset:</strong> $charset</p>";
echo "<p><strong>Database Collation:</strong> $collation</p>";

// Test connection charset
$connection_charset = $wpdb->get_var("SELECT @@character_set_connection");
echo "<p><strong>Connection Charset:</strong> $connection_charset</p>";

// Check posts table
$posts_table_info = $wpdb->get_row("SHOW TABLE STATUS WHERE Name='{$wpdb->posts}'");
if ($posts_table_info) {
    echo "<p><strong>Posts Table Collation:</strong> {$posts_table_info->Collation}</p>";
}

// Check if UTF8MB4 is used
if (strpos($charset, 'utf8') !== false) {
    echo '<p class="success" style="color: green; font-weight: bold;">✓ UTF-8 encoding is enabled</p>';
} else {
    echo '<p class="error" style="color: red; font-weight: bold;">✗ UTF-8 encoding is NOT enabled</p>';
}
?>
</div>

<div class="test-box">
<h2>Test 3: Test avec une vraie recette</h2>
<?php
// Try to find a recipe with French characters
$test_recipe = $wpdb->get_row("
    SELECT post_title, post_content 
    FROM {$wpdb->posts} 
    WHERE post_type = 'recipe' 
    AND (post_title LIKE '%é%' OR post_title LIKE '%è%' OR post_title LIKE '%à%')
    LIMIT 1
");

if ($test_recipe) {
    echo "<p><strong>Recette trouvée:</strong></p>";
    echo "<h3>" . esc_html($test_recipe->post_title) . "</h3>";
    echo "<div style='white-space: pre-wrap;'>" . wp_kses_post(substr($test_recipe->post_content, 0, 500)) . "...</div>";
    
    // Check if characters look correct
    $has_weird_chars = preg_match('/Ã|â€™|Ã©|Ã¨|Ã /u', $test_recipe->post_title . $test_recipe->post_content);
    if ($has_weird_chars) {
        echo '<p class="error" style="color: red; font-weight: bold;">✗ Les caractères ne sont pas corrects (encodage incorrect)</p>';
    } else {
        echo '<p class="success" style="color: green; font-weight: bold;">✓ Les caractères semblent corrects</p>';
    }
} else {
    echo "<p><em>Aucune recette avec caractères accentués trouvée pour tester.</em></p>";
}
?>
</div>

<div class="test-box">
<h2>Test 4: PHP Info</h2>
<?php
echo "<p><strong>PHP Default Charset:</strong> " . ini_get('default_charset') . "</p>";
echo "<p><strong>mbstring Enabled:</strong> " . (function_exists('mb_strlen') ? 'Yes ✓' : 'No ✗') . "</p>";
if (function_exists('mb_internal_encoding')) {
    echo "<p><strong>MB Internal Encoding:</strong> " . mb_internal_encoding() . "</p>";
}
?>
</div>

<div class="test-box">
<h2>Diagnostic</h2>
<p><strong>Si tous les tests sont verts:</strong> Vos caractères français sont bien encodés! ✓</p>
<p><strong>Si vous voyez des caractères bizarres (Ã©, â€™, etc.):</strong></p>
<ul>
    <li>Le fichier SQL source n'était pas en UTF-8</li>
    <li>L'import n'a pas spécifié --default-character-set=utf8mb4</li>
    <li>La base de données n'utilise pas utf8mb4</li>
</ul>
</div>

</body>
</html>';
?>
