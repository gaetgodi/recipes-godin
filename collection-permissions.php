<?php
/**
 * Recipe Collection Permissions System
 *
 * @version 2.1.1
 * @changelog
 *   2.1.1 - Reverted get_accessible_collections() to single-direction viewer check.
 *            Bidirectional logic belongs only in share recipient list and action check.
 *   2.1.0 - get_accessible_collections() now uses bidirectional viewer check,
 *            so collections are visible when either party has granted viewer access.
 *   1.0.0 - Initial release.
 */

/**
 * Helper function to copy featured image from one recipe to another
 */
function copy_recipe_featured_image($source_recipe_id, $target_recipe_id) {
    $original_thumbnail_id = get_post_thumbnail_id($source_recipe_id);
    
    if (!$original_thumbnail_id) {
        return false;
    }
    
    $original_file = get_attached_file($original_thumbnail_id);
    if (!$original_file || !file_exists($original_file)) {
        return false;
    }
    
    $upload_dir = wp_upload_dir();
    $filename = basename($original_file);
    $new_file = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);
    
    if (!copy($original_file, $new_file)) {
        return false;
    }
    
    $wp_filetype = wp_check_filetype($filename, null);
    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit'
    );
    
    $new_thumbnail_id = wp_insert_attachment($attachment, $new_file, $target_recipe_id);
    
    if (is_wp_error($new_thumbnail_id)) {
        @unlink($new_file);
        return false;
    }
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($new_thumbnail_id, $new_file);
    wp_update_attachment_metadata($new_thumbnail_id, $attach_data);
    
    set_post_thumbnail($target_recipe_id, $new_thumbnail_id);
    
    return true;
}

/**
 * Get collection owner (Author) for current user or specified user
 */
function get_collection_owner($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $user = get_userdata($user_id);
    
    if (in_array('author', $user->roles)) {
        return $user_id;
    }
    
    return null;
}

/**
 * Check if user can manage recipes in a collection
 */
function user_can_manage_collection($user_id, $collection_owner_id) {
    if ($user_id == $collection_owner_id) {
        return true;
    }
    
    $editors = get_user_meta($collection_owner_id, '_collection_editors', true);
    if (!is_array($editors)) {
        $editors = array();
    }
    
    return in_array($user_id, $editors);
}

/**
 * Check if user can view recipes in a collection
 */
function user_can_view_collection($user_id, $collection_owner_id) {
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    
    if (user_can_manage_collection($user_id, $collection_owner_id)) {
        return true;
    }
    
    $viewers = get_user_meta($collection_owner_id, '_collection_viewers', true);
    if (!is_array($viewers)) {
        $viewers = array();
    }
    
    return in_array($user_id, $viewers);
}

/**
 * Grant editor permission to user
 */
function grant_editor_permission($collection_owner_id, $user_id) {
    $editors = get_user_meta($collection_owner_id, '_collection_editors', true);
    if (!is_array($editors)) {
        $editors = array();
    }
    
    if (!in_array($user_id, $editors)) {
        $editors[] = $user_id;
        update_user_meta($collection_owner_id, '_collection_editors', $editors);
        
        $viewers = get_user_meta($collection_owner_id, '_collection_viewers', true);
        if (is_array($viewers)) {
            $viewers = array_diff($viewers, array($user_id));
            update_user_meta($collection_owner_id, '_collection_viewers', $viewers);
        }
        
        return true;
    }
    
    return false;
}

/**
 * Revoke editor permission from user
 */
function revoke_editor_permission($collection_owner_id, $user_id) {
    $editors = get_user_meta($collection_owner_id, '_collection_editors', true);
    if (is_array($editors)) {
        $editors = array_diff($editors, array($user_id));
        update_user_meta($collection_owner_id, '_collection_editors', $editors);
        return true;
    }
    return false;
}

/**
 * Grant viewer permission to user
 */
function grant_viewer_permission($collection_owner_id, $user_id) {
    if (user_can_manage_collection($user_id, $collection_owner_id)) {
        return false;
    }
    
    $viewers = get_user_meta($collection_owner_id, '_collection_viewers', true);
    if (!is_array($viewers)) {
        $viewers = array();
    }
    
    if (!in_array($user_id, $viewers)) {
        $viewers[] = $user_id;
        update_user_meta($collection_owner_id, '_collection_viewers', $viewers);
        return true;
    }
    
    return false;
}

/**
 * Revoke viewer permission from user
 */
function revoke_viewer_permission($collection_owner_id, $user_id) {
    $viewers = get_user_meta($collection_owner_id, '_collection_viewers', true);
    if (is_array($viewers)) {
        $viewers = array_diff($viewers, array($user_id));
        update_user_meta($collection_owner_id, '_collection_viewers', $viewers);
        return true;
    }
    return false;
}

/**
 * Request access to a collection
 */
function request_collection_access($collection_owner_id, $user_id) {
    $requests = get_user_meta($collection_owner_id, '_access_requests', true);
    if (!is_array($requests)) {
        $requests = array();
    }
    
    if (!in_array($user_id, $requests)) {
        $requests[] = $user_id;
        update_user_meta($collection_owner_id, '_access_requests', $requests);
        
        $owner = get_userdata($collection_owner_id);
        $requester = get_userdata($user_id);
        
        $subject = "Access Request for Your Recipe Collection";
        $message = "Hi {$owner->display_name},\n\n";
        $message .= "{$requester->display_name} has requested access to view your recipe collection.\n\n";
        $message .= "To approve or deny this request, log in to your account and go to Recipe Manager → Manage Permissions.\n\n";
        $message .= "Best regards,\nThe Recipe Team";
        
        wp_mail($owner->user_email, $subject, $message);
        
        return true;
    }
    
    return false;
}

/**
 * Approve access request
 */
function approve_access_request($collection_owner_id, $user_id, $as_editor = false) {
    $requests = get_user_meta($collection_owner_id, '_access_requests', true);
    if (is_array($requests)) {
        $requests = array_diff($requests, array($user_id));
        update_user_meta($collection_owner_id, '_access_requests', $requests);
    }
    
    if ($as_editor) {
        grant_editor_permission($collection_owner_id, $user_id);
    } else {
        grant_viewer_permission($collection_owner_id, $user_id);
    }
    
    $owner = get_userdata($collection_owner_id);
    $user = get_userdata($user_id);
    
    $permission_type = $as_editor ? 'edit and manage' : 'view';
    
    $subject = "Access Granted to {$owner->display_name}'s Recipe Collection";
    $message = "Hi {$user->display_name},\n\n";
    $message .= "Good news! {$owner->display_name} has granted you permission to {$permission_type} their recipe collection.\n\n";
    $message .= "You can now access their recipes from your Recipe Manager.\n\n";
    $message .= "Best regards,\nThe Recipe Team";
    
    wp_mail($user->user_email, $subject, $message);
    
    return true;
}

/**
 * Deny access request
 */
function deny_access_request($collection_owner_id, $user_id) {
    $requests = get_user_meta($collection_owner_id, '_access_requests', true);
    if (is_array($requests)) {
        $requests = array_diff($requests, array($user_id));
        update_user_meta($collection_owner_id, '_access_requests', $requests);
        return true;
    }
    return false;
}

/**
 * Get all collections current user can access
 * Uses bidirectional viewer check — collection is accessible if either party
 * has granted viewer access to the other.
 */
function get_accessible_collections($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $collections = array();
    $seen_ids = array();
    
    $is_admin = user_can($user_id, 'manage_options');
    
    $authors = get_users(array(
        'role__in' => array('author', 'administrator', 'editor')
    ));
    
    foreach ($authors as $author) {
        if (in_array($author->ID, $seen_ids)) {
            continue;
        }
        
        // Single direction: accessible only if user was explicitly granted access
        if ($is_admin ||
            user_can_view_collection($user_id, $author->ID)) {
            $collections[] = array(
                'owner_id' => $author->ID,
                'owner_name' => $author->display_name,
                'can_manage' => $is_admin || user_can_manage_collection($user_id, $author->ID),
                'can_view' => true,
            );
            $seen_ids[] = $author->ID;
        }
    }
    
    return $collections;
}

/**
 * Get collection stats
 */
function get_collection_stats($collection_owner_id) {
    $recipe_count = count_user_posts($collection_owner_id, 'recipe');
    
    $editors = get_user_meta($collection_owner_id, '_collection_editors', true);
    $editor_count = is_array($editors) ? count($editors) : 0;
    
    $viewers = get_user_meta($collection_owner_id, '_collection_viewers', true);
    $viewer_count = is_array($viewers) ? count($viewers) : 0;
    
    $requests = get_user_meta($collection_owner_id, '_access_requests', true);
    $request_count = is_array($requests) ? count($requests) : 0;
    
    return array(
        'recipes' => $recipe_count,
        'editors' => $editor_count,
        'viewers' => $viewer_count,
        'pending_requests' => $request_count,
    );
}

/**
 * Copy a recipe to user's own collection
 */
function copy_recipe_to_my_collection($recipe_id, $target_user_id) {
    $original = get_post($recipe_id);
    
    if (!$original || $original->post_type !== 'recipe') {
        return array('error' => 'Recipe not found');
    }
    
    global $wpdb;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_title = %s 
         AND post_type = 'recipe' 
         AND post_author = %d",
        $original->post_title,
        $target_user_id
    ));
    
    if ($existing) {
        return array('error' => 'You already have a recipe with this title');
    }
    
    $user = new WP_User($target_user_id);
    $current_role = $user->roles[0] ?? 'subscriber';
    
    if ($current_role === 'subscriber') {
        $user->set_role('author');
        $promoted = true;
    } else {
        $promoted = false;
    }
    
    $new_recipe_id = wp_insert_post(array(
        'post_title' => $original->post_title,
        'post_type' => 'recipe',
        'post_status' => 'publish',
        'post_author' => $target_user_id
    ));
    
    if (is_wp_error($new_recipe_id)) {
        return array('error' => 'Failed to copy recipe');
    }
    
    $meta_fields = array('_recipe_ingredients', '_recipe_method', '_recipe_notes');
    foreach ($meta_fields as $meta_key) {
        $meta_value = get_post_meta($recipe_id, $meta_key, true);
        if (!empty($meta_value)) {
            update_post_meta($new_recipe_id, $meta_key, $meta_value);
        }
    }
    
    copy_recipe_featured_image($recipe_id, $new_recipe_id);
    
    $source_categories = get_recipe_categories($recipe_id);
    $new_category_ids = array();
    
    if (!empty($source_categories)) {
        foreach ($source_categories as $cat) {
            $existing = get_user_category_by_name($target_user_id, $cat->cat_name);
            
            if ($existing) {
                $new_category_ids[] = $existing->cat_id;
            } else {
                $result = create_user_category($target_user_id, $cat->cat_name);
                if (isset($result['cat_id'])) {
                    $new_category_ids[] = $result['cat_id'];
                }
            }
        }
    }
    
    $source_author_id = $original->post_author;
    if ($source_author_id != $target_user_id) {
        $source_author = get_userdata($source_author_id);
        if ($source_author) {
            $author_category_name = $source_author->user_login;
            
            $existing_author_cat = get_user_category_by_name($target_user_id, $author_category_name);
            
            if ($existing_author_cat) {
                $new_category_ids[] = $existing_author_cat->cat_id;
            } else {
                $result = create_user_category($target_user_id, $author_category_name);
                if (isset($result['cat_id'])) {
                    $new_category_ids[] = $result['cat_id'];
                }
            }
        }
    }
    
    if (!empty($new_category_ids)) {
        set_recipe_categories($new_recipe_id, $new_category_ids);
    }
    
    $recipe_permanent_id = get_post_meta($new_recipe_id, '_recipe_id', true);
    if (empty($recipe_permanent_id)) {
        $recipe_permanent_id = 'R' . str_pad($new_recipe_id, 4, '0', STR_PAD_LEFT);
        update_post_meta($new_recipe_id, '_recipe_id', $recipe_permanent_id);
    }
    
    return array(
        'success' => true,
        'recipe_id' => $new_recipe_id,
        'promoted' => $promoted,
        'message' => $promoted ? 
            'Recipe copied successfully! You are now an author and can create and manage your own recipes.' :
            'Recipe copied successfully to your collection'
    );
}

/**
 * Clean up deleted users from permission arrays
 */
function cleanup_deleted_users_from_permissions($collection_owner_id) {
    $editors = get_user_meta($collection_owner_id, '_collection_editors', true);
    if (is_array($editors)) {
        $valid_editors = array();
        foreach ($editors as $user_id) {
            if (get_userdata($user_id)) {
                $valid_editors[] = $user_id;
            }
        }
        update_user_meta($collection_owner_id, '_collection_editors', $valid_editors);
    }
    
    $viewers = get_user_meta($collection_owner_id, '_collection_viewers', true);
    if (is_array($viewers)) {
        $valid_viewers = array();
        foreach ($viewers as $user_id) {
            if (get_userdata($user_id)) {
                $valid_viewers[] = $user_id;
            }
        }
        update_user_meta($collection_owner_id, '_collection_viewers', $valid_viewers);
    }
    
    $requests = get_user_meta($collection_owner_id, '_access_requests', true);
    if (is_array($requests)) {
        $valid_requests = array();
        foreach ($requests as $user_id) {
            if (get_userdata($user_id)) {
                $valid_requests[] = $user_id;
            }
        }
        update_user_meta($collection_owner_id, '_access_requests', $valid_requests);
    }
}

?>