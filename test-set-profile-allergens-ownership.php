<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Throwaway test: confirm set_profile_allergens() refuses a non-owner change.
 * Delete this file after use.
 *
 * Attempts to wipe the profile's allergen list entirely (the clearest
 * possible sign of a hostile write if the ownership check does not hold).
 *
 * Usage: php test-set-profile-allergens-ownership.php /path/to/wordpress <profile_id> <acting_user_id>
 */

if ($argc < 4) {
    die("Usage: php test-set-profile-allergens-ownership.php /path/to/wordpress <profile_id> <acting_user_id>\n");
}

$wp_path = $argv[1];
$profile_id = intval($argv[2]);
$acting_user_id = intval($argv[3]);

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('set_profile_allergens')) {
    die("Error: set_profile_allergens() not found. Check WordPress loaded and the theme's allergen-functions.php is in place.\n");
}

$profile = get_profile_by_id($profile_id);
if (!$profile) {
    die("Error: no profile found with ID {$profile_id}\n");
}

$before_allergens = get_profile_allergens($profile_id);
$before_names = wp_list_pluck($before_allergens, 'allergen_name');

echo "Profile {$profile_id} owner_user_id: {$profile->owner_user_id}\n";
echo "Acting as user_id: {$acting_user_id}\n";
echo "Before allergens: " . (empty($before_names) ? '(none)' : implode(', ', $before_names)) . "\n\n";

$result = set_profile_allergens($profile_id, $acting_user_id, array());

echo "set_profile_allergens() returned:\n";
print_r($result);
echo "\n";

$after_allergens = get_profile_allergens($profile_id);
$after_names = wp_list_pluck($after_allergens, 'allergen_name');

echo "After allergens: " . (empty($after_names) ? '(none)' : implode(', ', $after_names)) . "\n\n";

if (isset($result['error']) && $before_names === $after_names) {
    echo "PASS: set_profile_allergens() refused the change and the allergen list is unchanged.\n";
} else {
    echo "FAIL: the allergen list changed, or no error was returned. Investigate immediately.\n";
}
