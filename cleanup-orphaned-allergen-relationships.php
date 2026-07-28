<?php
/**
 * Cleanup Orphaned Allergen Relationships
 *
 * Removes allergen_profile_items / allergen_profile_shares rows that point
 * to a profile, allergen, or user that no longer exists. Parity with
 * cleanup-orphaned-relationships.php for recipe categories.
 *
 * Deliberately does NOT touch allergen_profiles, allergen_definitions, or
 * allergen_products themselves — those are entity tables, not relationship
 * tables, and deleting a profile/product because its owner account was
 * removed is a separate, more destructive decision this script doesn't make.
 *
 * Usage: php cleanup-orphaned-allergen-relationships.php /path/to/wordpress
 */

// Only run from command line
if (!isset($argc) || php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

if ($argc < 2) {
    die("Usage: php cleanup-orphaned-allergen-relationships.php /path/to/wordpress\n");
}

$wp_path = $argv[1];

// Load WordPress
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

global $wpdb;

echo "Cleaning up orphaned allergen relationships...\n\n";

$total_deleted = 0;

// --- allergen_profile_items: profile_id no longer exists ---
$orphaned = $wpdb->get_results("
    SELECT i.profile_id, COUNT(*) as count
    FROM {$wpdb->prefix}allergen_profile_items i
    LEFT JOIN {$wpdb->prefix}allergen_profiles p ON i.profile_id = p.profile_id
    WHERE p.profile_id IS NULL
    GROUP BY i.profile_id
");

if (!empty($orphaned)) {
    echo "allergen_profile_items — orphaned by deleted profile:\n";
    foreach ($orphaned as $item) {
        echo "  - profile_id {$item->profile_id}: {$item->count} orphaned rows\n";
    }
    $deleted = $wpdb->query("
        DELETE i FROM {$wpdb->prefix}allergen_profile_items i
        LEFT JOIN {$wpdb->prefix}allergen_profiles p ON i.profile_id = p.profile_id
        WHERE p.profile_id IS NULL
    ");
    echo "  Deleted {$deleted} rows\n\n";
    $total_deleted += $deleted;
} else {
    echo "allergen_profile_items — no rows orphaned by deleted profile.\n\n";
}

// --- allergen_profile_items: allergen_id no longer exists ---
$orphaned = $wpdb->get_results("
    SELECT i.allergen_id, COUNT(*) as count
    FROM {$wpdb->prefix}allergen_profile_items i
    LEFT JOIN {$wpdb->prefix}allergen_definitions d ON i.allergen_id = d.allergen_id
    WHERE d.allergen_id IS NULL
    GROUP BY i.allergen_id
");

if (!empty($orphaned)) {
    echo "allergen_profile_items — orphaned by deleted allergen:\n";
    foreach ($orphaned as $item) {
        echo "  - allergen_id {$item->allergen_id}: {$item->count} orphaned rows\n";
    }
    $deleted = $wpdb->query("
        DELETE i FROM {$wpdb->prefix}allergen_profile_items i
        LEFT JOIN {$wpdb->prefix}allergen_definitions d ON i.allergen_id = d.allergen_id
        WHERE d.allergen_id IS NULL
    ");
    echo "  Deleted {$deleted} rows\n\n";
    $total_deleted += $deleted;
} else {
    echo "allergen_profile_items — no rows orphaned by deleted allergen.\n\n";
}

// --- allergen_profile_shares: profile_id no longer exists ---
$orphaned = $wpdb->get_results("
    SELECT s.profile_id, COUNT(*) as count
    FROM {$wpdb->prefix}allergen_profile_shares s
    LEFT JOIN {$wpdb->prefix}allergen_profiles p ON s.profile_id = p.profile_id
    WHERE p.profile_id IS NULL
    GROUP BY s.profile_id
");

if (!empty($orphaned)) {
    echo "allergen_profile_shares — orphaned by deleted profile:\n";
    foreach ($orphaned as $item) {
        echo "  - profile_id {$item->profile_id}: {$item->count} orphaned rows\n";
    }
    $deleted = $wpdb->query("
        DELETE s FROM {$wpdb->prefix}allergen_profile_shares s
        LEFT JOIN {$wpdb->prefix}allergen_profiles p ON s.profile_id = p.profile_id
        WHERE p.profile_id IS NULL
    ");
    echo "  Deleted {$deleted} rows\n\n";
    $total_deleted += $deleted;
} else {
    echo "allergen_profile_shares — no rows orphaned by deleted profile.\n\n";
}

// --- allergen_profile_shares: shared_with_user_id no longer exists ---
$orphaned = $wpdb->get_results("
    SELECT s.shared_with_user_id, COUNT(*) as count
    FROM {$wpdb->prefix}allergen_profile_shares s
    LEFT JOIN {$wpdb->users} u ON s.shared_with_user_id = u.ID
    WHERE u.ID IS NULL
    GROUP BY s.shared_with_user_id
");

if (!empty($orphaned)) {
    echo "allergen_profile_shares — orphaned by deleted user:\n";
    foreach ($orphaned as $item) {
        echo "  - shared_with_user_id {$item->shared_with_user_id}: {$item->count} orphaned rows\n";
    }
    $deleted = $wpdb->query("
        DELETE s FROM {$wpdb->prefix}allergen_profile_shares s
        LEFT JOIN {$wpdb->users} u ON s.shared_with_user_id = u.ID
        WHERE u.ID IS NULL
    ");
    echo "  Deleted {$deleted} rows\n\n";
    $total_deleted += $deleted;
} else {
    echo "allergen_profile_shares — no rows orphaned by deleted user.\n\n";
}

if ($total_deleted === 0) {
    echo "No orphaned allergen relationships found. Database is clean!\n";
} else {
    echo "Cleanup complete! Deleted {$total_deleted} orphaned rows total.\n";
}
