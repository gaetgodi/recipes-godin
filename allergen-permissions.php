<?php
/**
 * Allergen Profile Permissions
 *
 * Mirrors the shape of collection-permissions.php, but backed by the
 * allergen_profile_shares join table (keyed per profile_id) rather than
 * a per-owner usermeta array, since a single user can own multiple
 * profiles that each need an independent, auditable share list.
 *
 * Deliberately only two states exist for a profile: owner, or
 * shared-viewer. There is no "editor" tier and no admin bypass on edit
 * rights — this is the one place the spec requires "only the creator
 * can edit," on medical data, which is why user_can_edit_profile() is a
 * plain ownership check with nothing else layered on top of it.
 *
 * Phase 1 note: share_profile()/unshare_profile() are added in Phase 4.
 * The functions below already read allergen_profile_shares so nothing
 * here needs to change when sharing is introduced.
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Check if a user can view a profile: its owner, an administrator, or
 * someone it has been shared with.
 */
function user_can_view_profile($user_id, $profile) {
    if (!$profile) {
        return false;
    }

    if ($profile->owner_user_id == $user_id) {
        return true;
    }

    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    global $wpdb;
    $shared = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}allergen_profile_shares
         WHERE profile_id = %d AND shared_with_user_id = %d",
        $profile->profile_id,
        $user_id
    ));

    return $shared > 0;
}

/**
 * Check if a user can edit a profile's allergen list. Only the creator —
 * no editor tier, no admin bypass, by design (see file header).
 */
function user_can_edit_profile($user_id, $profile) {
    if (!$profile) {
        return false;
    }

    return $profile->owner_user_id == $user_id;
}

/**
 * Get all profiles accessible to a user: their own, plus any shared with them.
 * Returns array('own' => [...], 'shared' => [...]).
 */
function get_profiles_accessible_to_user($user_id) {
    global $wpdb;

    $own = get_user_profiles($user_id);

    $shared_profile_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT profile_id FROM {$wpdb->prefix}allergen_profile_shares
         WHERE shared_with_user_id = %d",
        $user_id
    ));

    $shared = array();
    foreach ($shared_profile_ids as $profile_id) {
        $profile = get_profile_by_id($profile_id);
        if ($profile) {
            $shared[] = $profile;
        }
    }

    return array('own' => $own, 'shared' => $shared);
}

/**
 * Get the user's currently active allergen profile, or null if unset or
 * no longer accessible (revoked/deleted access is treated as unset —
 * never silently falls back to "check against everything").
 */
function get_active_allergen_profile($user_id) {
    $profile_id = get_user_meta($user_id, '_active_allergen_profile_id', true);

    if (empty($profile_id)) {
        return null;
    }

    $profile = get_profile_by_id(intval($profile_id));

    if (!$profile || !user_can_view_profile($user_id, $profile)) {
        delete_user_meta($user_id, '_active_allergen_profile_id');
        return null;
    }

    return $profile;
}

/**
 * Set the user's active allergen profile. Fails if the profile isn't
 * accessible to them.
 */
function set_active_allergen_profile($user_id, $profile_id) {
    $profile = get_profile_by_id(intval($profile_id));

    if (!$profile || !user_can_view_profile($user_id, $profile)) {
        return false;
    }

    update_user_meta($user_id, '_active_allergen_profile_id', intval($profile_id));
    return true;
}
