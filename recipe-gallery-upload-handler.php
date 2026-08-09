<?php
/**
 * Recipe Gallery — AJAX upload/delete handlers.
 *
 * HTTP layer for the recipe_photos feature. Data-layer functions
 * (get_recipe_photos, add_recipe_photo, delete_recipe_photo,
 * delete_recipe_photos_for_recipe) live in recipe-gallery-functions.php —
 * mirrors the recipe-image-upload-handler.php / (featured image lives
 * inline) split and the allergen-functions.php / allergen-image-upload-
 * handler.php split already used in this codebase.
 *
 * Client uploads one file per request (see page-recipe-editor.php JS,
 * which loops sequentially), so the server side stays as simple as
 * handle_recipe_image_upload() in recipe-image-upload-handler.php —
 * no $_FILES re-indexing needed for a multi-file batch.
 *
 * @version 1.0.0
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Shared ownership check for the gallery handlers below.
 *
 * Same administrator / owner / collection-editor logic used inline in
 * page-recipe-editor.php for edit and delete permission checks. Not
 * centralized there because that file isn't require_once'd anywhere else
 * — duplicating the exact same three-branch check here would drift out
 * of sync with it over time, so it's pulled out once for both AJAX
 * handlers below.
 *
 * @param int $recipe_id
 * @return bool
 */
function user_can_manage_recipe_gallery($recipe_id) {
    $recipe = get_post($recipe_id);

    if (!$recipe || $recipe->post_type !== 'recipe') {
        return false;
    }

    require_once(get_stylesheet_directory() . '/collection-permissions.php');

    $current_user_id = get_current_user_id();
    $recipe_owner_id = $recipe->post_author;

    if (current_user_can('administrator')) {
        return true;
    }
    if ($recipe_owner_id == $current_user_id) {
        return true;
    }
    if (user_can_manage_collection($current_user_id, $recipe_owner_id)) {
        return true;
    }

    return false;
}

/**
 * AJAX Handler: Upload one gallery photo for a recipe.
 */
add_action('wp_ajax_upload_recipe_gallery_photo', 'handle_recipe_gallery_upload');

function handle_recipe_gallery_upload() {
    check_ajax_referer('recipe_gallery_upload', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $recipe_id = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;

    if (!$recipe_id || !user_can_manage_recipe_gallery($recipe_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    if (empty($_FILES['gallery_image'])) {
        wp_send_json_error(array('message' => 'No image file provided'));
    }

    $existing_count = count(get_recipe_photos($recipe_id));

    if ($existing_count >= 10) {
        wp_send_json_error(array(
            'message' => 'Gallery is full (10 photo maximum).',
            'count' => $existing_count,
            'max' => 10
        ));
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $attachment_id = media_handle_upload('gallery_image', $recipe_id);

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => 'Error uploading image: ' . $attachment_id->get_error_message()));
    }

    $photo_url = wp_get_attachment_url($attachment_id);

    $photo_id = add_recipe_photo($recipe_id, $photo_url, $existing_count);

    if ($photo_id === false) {
        // Uploaded to the media library but the DB row failed — clean up
        // the orphaned attachment rather than leaving it dangling.
        wp_delete_attachment($attachment_id, true);
        wp_send_json_error(array('message' => 'Error saving photo record'));
    }

    $new_count = $existing_count + 1;

    wp_send_json_success(array(
        'photo_id' => $photo_id,
        'photo_url' => $photo_url,
        'count' => $new_count,
        'max' => 10
    ));
}

/**
 * AJAX Handler: Delete one gallery photo.
 */
add_action('wp_ajax_delete_recipe_gallery_photo', 'handle_recipe_gallery_delete');

function handle_recipe_gallery_delete() {
    check_ajax_referer('recipe_gallery_delete', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $photo_id = isset($_POST['photo_id']) ? intval($_POST['photo_id']) : 0;

    if (!$photo_id) {
        wp_send_json_error(array('message' => 'No photo specified'));
    }

    global $wpdb;

    $recipe_id = $wpdb->get_var($wpdb->prepare(
        "SELECT recipe_id FROM {$wpdb->prefix}recipe_photos WHERE photo_id = %d",
        $photo_id
    ));

    if (!$recipe_id || !user_can_manage_recipe_gallery($recipe_id)) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $deleted = delete_recipe_photo($photo_id);

    if (!$deleted) {
        wp_send_json_error(array('message' => 'Error deleting photo'));
    }

    $new_count = count(get_recipe_photos($recipe_id));

    wp_send_json_success(array(
        'count' => $new_count,
        'max' => 10
    ));
}
