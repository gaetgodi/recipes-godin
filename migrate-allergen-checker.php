<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Create Allergen Checker tables and seed the Health Canada priority allergen list.
 *
 * Usage: php migrate-allergen-checker.php /path/to/wordpress
 */

// Check command line argument
if ($argc < 2) {
    die("Usage: php migrate-allergen-checker.php /path/to/wordpress\n");
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
echo "Starting Allergen Checker table creation...\n\n";

// Step 1: Create tables
echo "Step 1: Creating tables...\n";

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_definitions (
    allergen_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allergen_name VARCHAR(255) NOT NULL,
    is_seed_allergen TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    user_id BIGINT UNSIGNED NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_allergen (user_id, allergen_name),
    INDEX idx_user_id (user_id),
    INDEX idx_is_seed (is_seed_allergen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_aliases (
    alias_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allergen_id INT UNSIGNED NOT NULL,
    alias_text VARCHAR(255) NOT NULL,
    INDEX idx_allergen_id (allergen_id),
    INDEX idx_alias_text (alias_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_profiles (
    profile_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_name VARCHAR(255) NOT NULL,
    profile_age SMALLINT UNSIGNED NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_profile_items (
    profile_id INT UNSIGNED NOT NULL,
    allergen_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (profile_id, allergen_id),
    INDEX idx_profile (profile_id),
    INDEX idx_allergen (allergen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_profile_shares (
    profile_id INT UNSIGNED NOT NULL,
    shared_with_user_id BIGINT UNSIGNED NOT NULL,
    shared_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id, shared_with_user_id),
    INDEX idx_user (shared_with_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}allergen_products (
    product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(255) NOT NULL,
    ingredient_text LONGTEXT NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    source_image_attachment_id BIGINT UNSIGNED NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "  \xE2\x9C\x93 Tables created\n\n";

// Step 2: Seed the Health Canada priority allergen list + common aliases
echo "Step 2: Seeding priority allergens and aliases...\n";

// Each entry: canonical name => list of aliases (canonical name itself is
// always inserted as an alias row too, so matching never special-cases it).
$seed_allergens = array(
    'Milk' => array('milk', 'casein', 'caseinate', 'whey', 'lactose', 'lactalbumin', 'lactoglobulin', 'butter', 'ghee', 'cream', 'buttermilk', 'yogurt', 'cheese'),
    'Egg' => array('egg', 'eggs', 'albumin', 'albumen', 'ovalbumin', 'ovomucin', 'ovomucoid', 'lysozyme', 'egg white', 'egg yolk', 'mayonnaise'),
    'Peanut' => array('peanut', 'peanuts', 'groundnut', 'groundnuts', 'peanut oil', 'peanut butter', 'arachis oil'),
    'Tree nuts' => array('tree nut', 'tree nuts', 'almond', 'almonds', 'brazil nut', 'brazil nuts', 'cashew', 'cashews', 'hazelnut', 'hazelnuts', 'macadamia', 'pecan', 'pecans', 'pistachio', 'pistachios', 'walnut', 'walnuts', 'pine nut', 'pine nuts'),
    'Soy' => array('soy', 'soya', 'soybean', 'soybeans', 'soy protein', 'soy lecithin', 'edamame', 'tofu', 'tempeh', 'miso'),
    'Wheat/gluten' => array('wheat', 'gluten', 'flour', 'wheat flour', 'wheat gluten', 'semolina', 'durum', 'spelt', 'farro', 'barley', 'rye', 'malt', 'triticale'),
    'Sesame' => array('sesame', 'sesame seed', 'sesame seeds', 'sesame oil', 'tahini', 'benne', 'benne seed'),
    'Fish' => array('fish', 'anchovy', 'anchovies', 'cod', 'salmon', 'tuna', 'tilapia', 'haddock', 'halibut', 'fish sauce', 'fish oil', 'worcestershire sauce'),
    'Crustaceans/molluscs' => array('crustacean', 'crustaceans', 'mollusc', 'molluscs', 'mollusk', 'mollusks', 'shellfish', 'shrimp', 'prawn', 'prawns', 'crab', 'lobster', 'clam', 'clams', 'mussel', 'mussels', 'oyster', 'oysters', 'scallop', 'scallops', 'squid', 'calamari'),
    'Mustard' => array('mustard', 'mustard seed', 'mustard seeds', 'mustard powder', 'mustard flour'),
    'Sulphites' => array('sulphite', 'sulphites', 'sulfite', 'sulfites', 'sulphur dioxide', 'sulfur dioxide', 'sodium metabisulphite', 'sodium metabisulfite', 'potassium metabisulphite', 'potassium metabisulfite'),
);

$total_allergens = 0;
$total_aliases = 0;

foreach ($seed_allergens as $allergen_name => $aliases) {
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT allergen_id FROM {$wpdb->prefix}allergen_definitions
         WHERE user_id IS NULL AND allergen_name = %s",
        $allergen_name
    ));

    if ($existing) {
        echo "  - Seed allergen already exists: '{$allergen_name}'\n";
        continue;
    }

    $wpdb->insert(
        $wpdb->prefix . 'allergen_definitions',
        array(
            'allergen_name' => $allergen_name,
            'is_seed_allergen' => 1,
            'user_id' => null,
        ),
        array('%s', '%d', '%d')
    );

    $allergen_id = $wpdb->insert_id;
    $total_allergens++;

    // Canonical name itself is stored as an alias row too.
    $all_alias_texts = array_unique(array_merge(array(strtolower($allergen_name)), $aliases));

    foreach ($all_alias_texts as $alias_text) {
        $wpdb->insert(
            $wpdb->prefix . 'allergen_aliases',
            array(
                'allergen_id' => $allergen_id,
                'alias_text' => $alias_text,
            ),
            array('%d', '%s')
        );
        $total_aliases++;
    }

    echo "  \xE2\x9C\x93 Seeded: '{$allergen_name}' (allergen_id: {$allergen_id}, " . count($all_alias_texts) . " aliases)\n";
}

echo "\n  Seeded: {$total_allergens} allergens, {$total_aliases} aliases\n\n";

// Step 3: Verification
echo "Step 3: Verifying...\n";

$total_definitions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}allergen_definitions");
$total_alias_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}allergen_aliases");

echo "  \xE2\x9C\x93 Total rows in allergen_definitions: {$total_definitions}\n";
echo "  \xE2\x9C\x93 Total rows in allergen_aliases: {$total_alias_rows}\n\n";

echo "Sample seed allergens:\n";
$samples = $wpdb->get_results("
    SELECT allergen_id, allergen_name
    FROM {$wpdb->prefix}allergen_definitions
    WHERE is_seed_allergen = 1
    ORDER BY allergen_id
");

foreach ($samples as $sample) {
    $alias_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}allergen_aliases WHERE allergen_id = %d",
        $sample->allergen_id
    ));
    echo "  - {$sample->allergen_name} (ID: {$sample->allergen_id}, {$alias_count} aliases)\n";
}

echo "\n===========================================\n";
echo "Allergen Checker schema setup complete!\n";
echo "===========================================\n";
