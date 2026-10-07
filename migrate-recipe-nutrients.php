<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Nutrient Engine Integration — Phase 1: Create the five approved tables
 * and seed the single initial/global nutrient profile.
 *
 * DATABASE ONLY. Creates no calculations, no results, no requirements.
 * Does not touch any existing Recipes table. Does not touch any PHP/GUI file.
 *
 * Mirrors the standalone-CREATE-TABLE pattern used by
 * migrate-recipe-photos.php / migrate-allergen-checker.php /
 * migrate-recipe-products-table.php.
 *
 * Usage: php migrate-recipe-nutrients.php /path/to/wordpress
 */

// ============================================================
// REQUIRED: VERIFIED CNF NUTRIENT CODES
// ============================================================
// Weight is fixed by agreed convention: CANDAT nutrient code 0.
// The other eight are verified against Health Canada's official
// CNF 2015 "NUTRIENT NAME.csv" (NutrientID == NutrientCode for all eight).

define('CODE_WEIGHT',       0);        // fixed by convention — do not change
define('CODE_MOISTURE',     255);      // CNF 2015: MOISTURE (H2O, g)
define('CODE_CARBOHYDRATE', 205);      // CNF 2015: CARBOHYDRATE, TOTAL (BY DIFFERENCE) (CARB, g)
define('CODE_PROTEIN',      203);      // CNF 2015: PROTEIN (PROT, g)
define('CODE_FAT',          204);      // CNF 2015: FAT (TOTAL LIPIDS) (FAT, g)
define('CODE_FIBRE',        291);      // CNF 2015: FIBRE, TOTAL DIETARY (TDF, g)
define('CODE_SODIUM',       307);      // CNF 2015: SODIUM (NA, mg)
define('CODE_CALCIUM',      301);      // CNF 2015: CALCIUM (CA, mg)
define('CODE_MAGNESIUM',    304);      // CNF 2015: MAGNESIUM (MG, mg)

$profile_items = [
    // [display_order, nutrient_code, nutrient_name (verification label only — not stored)]
    [1, CODE_WEIGHT,       'Weight'],
    [2, CODE_MOISTURE,     'Moisture'],
    [3, CODE_CARBOHYDRATE, 'Carbohydrate'],
    [4, CODE_PROTEIN,      'Protein'],
    [5, CODE_FAT,          'Fat'],
    [6, CODE_FIBRE,        'Fibre'],
    [7, CODE_SODIUM,       'Sodium'],
    [8, CODE_CALCIUM,      'Calcium'],
    [9, CODE_MAGNESIUM,    'Magnesium'],
];

foreach ($profile_items as $item) {
    if ($item[1] === null) {
        die("REFUSING TO RUN: nutrient code for '{$item[2]}' has not been verified/filled in.\n" .
            "Replace every 'null' placeholder at the top of this script with the actual\n" .
            "verified CANDAT/CNF nutrient code before running. Do not guess.\n");
    }
}

if ($argc < 2) {
    die("Usage: php migrate-recipe-nutrients.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: Could not load WordPress. Check path: {$wp_path}\n");
}

global $wpdb;

echo "WordPress loaded successfully.\n";
echo "Table prefix: {$wpdb->prefix}\n\n";

// ------------------------------------------------------------
// Pre-flight: refuse to run if any target table already exists
// ------------------------------------------------------------
$target_tables = [
    'recipe_nutrient_calculations',
    'recipe_nutrient_results',
    'recipe_nutrient_requirements',
    'recipe_nutrient_profiles',
    'recipe_nutrient_profile_items',
];

$already_exists = [];
foreach ($target_tables as $t) {
    $full = $wpdb->prefix . $t;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full}'");
    if ($exists) {
        $already_exists[] = $full;
    }
}
if (!empty($already_exists)) {
    die("REFUSING TO RUN: the following target table(s) already exist:\n  " .
        implode("\n  ", $already_exists) .
        "\nNo table was created or altered. Investigate before proceeding.\n");
}

echo "Pre-flight check passed: none of the five target tables exist yet.\n\n";
echo "Creating nutrient engine tables...\n\n";

// ------------------------------------------------------------
// 1. recipe_nutrient_calculations
// ------------------------------------------------------------
$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_nutrient_calculations (
    calculation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipe_id BIGINT UNSIGNED NOT NULL,
    is_current TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    calculation_status VARCHAR(32) NOT NULL,
    source_fingerprint VARCHAR(64) NOT NULL,
    calculation_context_version VARCHAR(128) NOT NULL,
    engine_name VARCHAR(64) NOT NULL,
    engine_version VARCHAR(32) NULL,
    cnf_version VARCHAR(32) NULL,
    requested_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    last_enriched_at DATETIME NULL,
    total_weight_g DECIMAL(10,3) NULL,
    segments_total INT UNSIGNED NULL,
    segments_resolved INT UNSIGNED NULL,
    error_message TEXT NULL,
    PRIMARY KEY (calculation_id),
    INDEX idx_recipe_current (recipe_id, is_current),
    INDEX idx_status (calculation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] recipe_nutrient_calculations\n";

// ------------------------------------------------------------
// 2. recipe_nutrient_results
// ------------------------------------------------------------
$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_nutrient_results (
    calculation_id BIGINT UNSIGNED NOT NULL,
    nutrient_code SMALLINT UNSIGNED NOT NULL,
    value_amount DECIMAL(12,4) NULL,
    value_state VARCHAR(16) NOT NULL,
    added_at DATETIME NOT NULL,
    PRIMARY KEY (calculation_id, nutrient_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] recipe_nutrient_results\n";

// ------------------------------------------------------------
// 3. recipe_nutrient_requirements
// ------------------------------------------------------------
$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_nutrient_requirements (
    requirement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    calculation_id BIGINT UNSIGNED NOT NULL,
    segment_reference VARCHAR(255) NULL,
    requirement_type VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    details_json TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    PRIMARY KEY (requirement_id),
    INDEX idx_calculation_id (calculation_id),
    INDEX idx_requirement_type (requirement_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] recipe_nutrient_requirements\n";

// ------------------------------------------------------------
// 4. recipe_nutrient_profiles
// ------------------------------------------------------------
$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_nutrient_profiles (
    profile_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_name VARCHAR(255) NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    created_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id),
    INDEX idx_owner_user_id (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] recipe_nutrient_profiles\n";

// ------------------------------------------------------------
// 5. recipe_nutrient_profile_items
// ------------------------------------------------------------
$wpdb->query("
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}recipe_nutrient_profile_items (
    profile_id INT UNSIGNED NOT NULL,
    nutrient_code SMALLINT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (profile_id, nutrient_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] recipe_nutrient_profile_items\n\n";

// ------------------------------------------------------------
// Seed the one initial/global profile
// ------------------------------------------------------------
echo "Seeding initial profile...\n";

$wpdb->insert(
    "{$wpdb->prefix}recipe_nutrient_profiles",
    [
        'profile_name'   => 'Standard User Profile',
        'owner_user_id'  => null,
        'created_date'   => current_time('mysql'),
    ],
    ['%s', '%s', '%s']
);
$profile_id = $wpdb->insert_id;

if (!$profile_id) {
    die("ERROR: failed to insert the initial profile row.\n");
}

echo "  [OK] Profile created, profile_id = {$profile_id}\n";

foreach ($profile_items as $item) {
    list($display_order, $nutrient_code, $label) = $item;
    $wpdb->insert(
        "{$wpdb->prefix}recipe_nutrient_profile_items",
        [
            'profile_id'     => $profile_id,
            'nutrient_code'  => $nutrient_code,
            'display_order'  => $display_order,
        ],
        ['%d', '%d', '%d']
    );
}
echo "  [OK] 9 profile items inserted\n\n";

// ------------------------------------------------------------
// Verification
// ------------------------------------------------------------
echo "===========================================\n";
echo "VERIFICATION\n";
echo "===========================================\n\n";

echo "-- Tables present --\n";
foreach ($target_tables as $t) {
    $full = $wpdb->prefix . $t;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full}'");
    echo ($exists ? "  [OK] " : "  [MISSING] ") . $full . "\n";
}

echo "\n-- Row counts (calculations/results/requirements MUST be 0) --\n";
foreach (['recipe_nutrient_calculations', 'recipe_nutrient_results', 'recipe_nutrient_requirements'] as $t) {
    $full = $wpdb->prefix . $t;
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$full}");
    echo "  {$full}: {$count}\n";
}

echo "\n-- Seeded profile --\n";
$profile_row = $wpdb->get_row($wpdb->prepare(
    "SELECT profile_id, profile_name, owner_user_id, created_date FROM {$wpdb->prefix}recipe_nutrient_profiles WHERE profile_id = %d",
    $profile_id
));
echo "  profile_id={$profile_row->profile_id}  name=\"{$profile_row->profile_name}\"  owner_user_id=" .
     ($profile_row->owner_user_id === null ? 'NULL' : $profile_row->owner_user_id) .
     "  created_date={$profile_row->created_date}\n";

echo "\n-- Profile items (display_order / nutrient_code) --\n";
$items = $wpdb->get_results($wpdb->prepare(
    "SELECT display_order, nutrient_code FROM {$wpdb->prefix}recipe_nutrient_profile_items WHERE profile_id = %d ORDER BY display_order ASC",
    $profile_id
));
$labels = [1=>'Weight',2=>'Moisture',3=>'Carbohydrate',4=>'Protein',5=>'Fat',6=>'Fibre',7=>'Sodium',8=>'Calcium',9=>'Magnesium'];
foreach ($items as $row) {
    $label = $labels[(int)$row->display_order] ?? '?';
    echo "  {$row->display_order}\t{$row->nutrient_code}\t{$label}\n";
}

echo "\n===========================================\n";
echo "Nutrient engine infrastructure created.\n";
echo "No recipe was calculated. No existing table was altered.\n";
echo "===========================================\n";
