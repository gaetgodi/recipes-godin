<?php
/**
 * Recipe Gallery — data-layer helpers for recipe_photos.
 *
 * Up to 10 additional photos per recipe, separate from the featured image
 * (post thumbnail). No captions, no drag-reorder — photos are stored and
 * displayed in upload order (display_order, then photo_id as a tiebreaker).
 *
 * These are plain data-layer functions (insert/select/delete), matching the
 * split this codebase already uses elsewhere (e.g. allergen-functions.php
 * vs allergen-permissions.php) — permission checks and the 10-photo cap
 * belong in the upload handler, not here.
 *
 * @version 1.0.0
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Get all photos for a recipe, in display order.
 *
 * @param int $recipe_id
 * @return array List of row objects (photo_id, recipe_id, photo_url,
 *               display_order, uploaded_at), ordered by display_order then
 *               photo_id.
 */
function get_recipe_photos($recipe_id) {
    global $wpdb;

    $recipe_id = intval($recipe_id);

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}recipe_photos
         WHERE recipe_id = %d
         ORDER BY display_order ASC, photo_id ASC",
        $recipe_id
    ));
}

/**
 * Add a photo row for a recipe.
 *
 * @param int    $recipe_id
 * @param string $photo_url      URL of the already-uploaded image (from
 *                                wp_get_attachment_url() after
 *                                media_handle_upload()).
 * @param int    $display_order  Position in upload order (caller is
 *                                responsible for passing the next index,
 *                                e.g. count(get_recipe_photos($recipe_id))).
 * @return int|false New photo_id on success, false on failure.
 */
function add_recipe_photo($recipe_id, $photo_url, $display_order) {
    global $wpdb;

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}recipe_photos",
        array(
            'recipe_id'     => intval($recipe_id),
            'photo_url'     => $photo_url,
            'display_order' => intval($display_order),
            'uploaded_at'   => current_time('mysql'),
        ),
        array('%d', '%s', '%d', '%s')
    );

    if ($inserted === false) {
        return false;
    }

    return $wpdb->insert_id;
}

/**
 * Delete a single photo: removes the underlying attachment/file (if one can
 * be resolved from the stored URL) and the recipe_photos row.
 *
 * @param int $photo_id
 * @return bool True if the row was deleted, false if not found or deletion
 *              failed.
 */
function delete_recipe_photo($photo_id) {
    global $wpdb;

    $photo_id = intval($photo_id);

    $photo_url = $wpdb->get_var($wpdb->prepare(
        "SELECT photo_url FROM {$wpdb->prefix}recipe_photos WHERE photo_id = %d",
        $photo_id
    ));

    if ($photo_url === null) {
        return false;
    }

    // Schema stores only the URL (no attachment_id column), so resolve the
    // attachment back from it the standard WP way before deleting the file.
    $attachment_id = attachment_url_to_postid($photo_url);
    if ($attachment_id) {
        wp_delete_attachment($attachment_id, true);
    }

    $deleted = $wpdb->delete(
        "{$wpdb->prefix}recipe_photos",
        array('photo_id' => $photo_id),
        array('%d')
    );

    return $deleted !== false;
}

/**
 * Delete all photos for a recipe (DB rows + underlying files/attachments).
 *
 * Reuses delete_recipe_photo() per row rather than duplicating the
 * attachment-cleanup logic.
 *
 * @param int $recipe_id
 * @return int Number of photos successfully deleted.
 */
function delete_recipe_photos_for_recipe($recipe_id) {
    $photos = get_recipe_photos($recipe_id);

    $deleted_count = 0;
    foreach ($photos as $photo) {
        if (delete_recipe_photo($photo->photo_id)) {
            $deleted_count++;
        }
    }

    return $deleted_count;
}
