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
 * Phase 4: share_profile()/unshare_profile()/get_profile_shares() below
 * manage the allergen_profile_shares rows. Sharing is a creator-only
 * action, gated the same way as editing (user_can_edit_profile) — only
 * the person who built the profile decides who else can see it.
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
 * Share a profile with another user (view/apply only — never edit rights).
 * Only the creator may share. Refuses self-sharing and duplicate rows.
 */
function share_profile($profile_id, $acting_user_id, $target_user_id) {
    global $wpdb;

    $profile = get_profile_by_id($profile_id);
    if (!$profile || !user_can_edit_profile($acting_user_id, $profile)) {
        return array('error' => 'You do not have permission to share this profile');
    }

    $target_user_id = intval($target_user_id);

    if ($target_user_id == $acting_user_id) {
        return array('error' => 'You cannot share a profile with yourself');
    }

    $target_user = get_userdata($target_user_id);
    if (!$target_user) {
        return array('error' => 'That user could not be found');
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}allergen_profile_shares
         WHERE profile_id = %d AND shared_with_user_id = %d",
        $profile_id,
        $target_user_id
    ));

    if ($existing > 0) {
        return array('error' => 'Already shared with that user');
    }

    $result = $wpdb->insert(
        $wpdb->prefix . 'allergen_profile_shares',
        array(
            'profile_id' => $profile_id,
            'shared_with_user_id' => $target_user_id,
        ),
        array('%d', '%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Revoke a profile share. Only the creator may revoke.
 */
function unshare_profile($profile_id, $acting_user_id, $target_user_id) {
    global $wpdb;

    $profile = get_profile_by_id($profile_id);
    if (!$profile || !user_can_edit_profile($acting_user_id, $profile)) {
        return array('error' => 'You do not have permission to modify sharing for this profile');
    }

    $result = $wpdb->delete(
        $wpdb->prefix . 'allergen_profile_shares',
        array(
            'profile_id' => $profile_id,
            'shared_with_user_id' => intval($target_user_id),
        ),
        array('%d', '%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Get the users a profile is currently shared with, as WP_User objects.
 */
function get_profile_shares($profile_id) {
    global $wpdb;

    $shared_user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT shared_with_user_id FROM {$wpdb->prefix}allergen_profile_shares
         WHERE profile_id = %d",
        $profile_id
    ));

    $users = array();
    foreach ($shared_user_ids as $user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            $users[] = $user;
        }
    }

    return $users;
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
