<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Throwaway test: confirm delete_profile() refuses a non-owner delete.
 * Delete this file after use.
 *
 * WARNING: if the ownership check does NOT hold, this will actually
 * delete the profile and its allergen selections — that is the point of
 * the test, but be aware there is no undo.
 *
 * Usage: php test-delete-profile-ownership.php /path/to/wordpress <profile_id> <acting_user_id>
 */

if ($argc < 4) {
    die("Usage: php test-delete-profile-ownership.php /path/to/wordpress <profile_id> <acting_user_id>\n");
}

$wp_path = $argv[1];
$profile_id = intval($argv[2]);
$acting_user_id = intval($argv[3]);

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('delete_profile')) {
    die("Error: delete_profile() not found. Check WordPress loaded and the theme's allergen-functions.php is in place.\n");
}

$profile = get_profile_by_id($profile_id);
if (!$profile) {
    die("Error: no profile found with ID {$profile_id}\n");
}

echo "Profile {$profile_id} owner_user_id: {$profile->owner_user_id}\n";
echo "Acting as user_id: {$acting_user_id}\n";
echo "Before: profile exists, name='{$profile->profile_name}'\n\n";

$result = delete_profile($profile_id, $acting_user_id);

echo "delete_profile() returned:\n";
print_r($result);
echo "\n";

$after = get_profile_by_id($profile_id);

if (isset($result['error']) && $after) {
    echo "PASS: delete_profile() refused the delete and the profile still exists.\n";
} elseif (!$after) {
    echo "FAIL: the profile was ACTUALLY DELETED. The ownership check did not hold.\n";
    echo "You will need to recreate the profile and its allergen selections.\n";
} else {
    echo "UNEXPECTED: no error returned but profile still exists — check output above manually.\n";
}
