<?php
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
/**
 * Throwaway test: confirm update_profile() refuses a non-owner edit.
 * Delete this file after use.
 *
 * Usage: php test-update-profile-ownership.php /path/to/wordpress <profile_id> <acting_user_id>
 */

if ($argc < 4) {
    die("Usage: php test-update-profile-ownership.php /path/to/wordpress <profile_id> <acting_user_id>\n");
}

$wp_path = $argv[1];
$profile_id = intval($argv[2]);
$acting_user_id = intval($argv[3]);

define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('update_profile')) {
    die("Error: update_profile() not found. Check WordPress loaded and the theme's allergen-functions.php is in place.\n");
}

$profile = get_profile_by_id($profile_id);
if (!$profile) {
    die("Error: no profile found with ID {$profile_id}\n");
}

echo "Profile {$profile_id} owner_user_id: {$profile->owner_user_id}\n";
echo "Acting as user_id: {$acting_user_id}\n";
echo "Before: name='{$profile->profile_name}', age=" . var_export($profile->profile_age, true) . "\n\n";

$result = update_profile($profile_id, $acting_user_id, 'HACKED NAME', 999);

echo "update_profile() returned:\n";
print_r($result);
echo "\n";

$after = get_profile_by_id($profile_id);
echo "After: name='{$after->profile_name}', age=" . var_export($after->profile_age, true) . "\n\n";

if (isset($result['error']) && $after->profile_name === $profile->profile_name && $after->profile_age == $profile->profile_age) {
    echo "PASS: update_profile() refused the edit and nothing changed.\n";
} else {
    echo "FAIL: the edit was NOT refused, or data changed unexpectedly. Investigate immediately.\n";
}
