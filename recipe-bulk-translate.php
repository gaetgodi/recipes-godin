<?php
/**
 * Recipe Manager: bulk "Translate selected"
 *
 * Translates several selected recipes into one target language, each saved as a
 * new recipe owned by the current user, the same way the editor's "Save as new
 * recipe" does for one. Source recipes are never modified.
 *
 * Two AJAX steps, both behind the recipe_bulk_translate nonce:
 *   recipe_bulk_translate_preview - decides CREATE / REUSE / SKIP per recipe, caps
 *       CREATEs at BULK_TRANSLATE_MAX_CREATES in list order, and saves that plan in
 *       a per-user transient.
 *   recipe_bulk_translate_one - translates one recipe from that plan per request
 *       (the browser shows progress between requests), re-running the decision first.
 *
 * @version 1.0.0
 * @changelog
 *   1.0.0 - Initial release.
 */

// Security check
if (!defined('ABSPATH')) exit;

require_once(get_stylesheet_directory() . '/collection-permissions.php');

define('BULK_TRANSLATE_MAX_CREATES', 25);
define('BULK_TRANSLATE_PLAN_TTL', 30 * MINUTE_IN_SECONDS);

/**
 * Whether $user_id may use $post as translation source text: a recipe, not
 * trashed, and the user is an admin, its owner, or a viewer/editor of the owner's
 * collection (published recipes only, as the Recipe Manager list shows them).
 */
function bulk_translate_can_read_source($user_id, $post) {
    if (!$post || $post->post_type !== 'recipe' || $post->post_status === 'trash') {
        return false;
    }
    if (user_can($user_id, 'administrator')) {
        return true;
    }
    if ((int) $post->post_author === (int) $user_id) {
        return true;
    }
    return $post->post_status === 'publish' && user_can_view_collection($user_id, $post->post_author);
}

/**
 * The recipe whose stored text a translation of $selected should start from. For a
 * translated copy, that is the recipe it was translated from, when it still exists
 * and is readable; otherwise the copy itself. Followed one level only.
 */
function bulk_translate_effective_source($user_id, $selected) {
    $root_id = intval(get_post_meta($selected->ID, '_recipe_translated_from', true));
    if ($root_id > 0 && $root_id !== (int) $selected->ID) {
        $root = get_post($root_id);
        if (bulk_translate_can_read_source($user_id, $root)) {
            return $root;
        }
    }
    return $selected;
}

/**
 * ID of an existing, non-trashed recipe owned by $user_id that was translated from
 * $source_id into $target_lang, or 0.
 */
function bulk_translate_find_copy($user_id, $source_id, $target_lang) {
    $ids = get_posts(array(
        'post_type' => 'recipe',
        'post_status' => 'any',
        'author' => $user_id,
        'posts_per_page' => 1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
        'meta_query' => array(
            array('key' => '_recipe_translated_from', 'value' => (string) intval($source_id)),
            array('key' => '_recipe_original_lang', 'value' => $target_lang),
        ),
    ));
    return empty($ids) ? 0 : (int) $ids[0];
}

/**
 * Decide what translating recipe $selected_id into $target_lang would do for
 * $user_id, without calling the API or writing anything.
 *
 * @return array decision ('create', 'reuse', 'skip' or 'error'), id, title,
 *               source_id/source_title (where the text comes from), lang_unknown,
 *               copy_id (reuse only) and message.
 */
function bulk_translate_decide($user_id, $selected_id, $target_lang) {
    $row = array(
        'id' => (int) $selected_id,
        'title' => '',
        'source_id' => (int) $selected_id,
        'source_title' => '',
        'decision' => 'error',
        'lang_unknown' => false,
        'copy_id' => 0,
        'message' => '',
    );

    $selected = get_post($selected_id);
    if (!bulk_translate_can_read_source($user_id, $selected)) {
        $row['message'] = 'Recipe not found, or you do not have access to it.';
        return $row;
    }
    $row['title'] = $selected->post_title;

    $source = bulk_translate_effective_source($user_id, $selected);
    $row['source_id'] = (int) $source->ID;
    $row['source_title'] = $source->post_title;

    $source_lang = get_post_meta($source->ID, '_recipe_original_lang', true);
    if ($source_lang !== '' && strcasecmp($source_lang, $target_lang) === 0) {
        $row['decision'] = 'skip';
        $row['message'] = 'Already in ' . $target_lang . '.';
        return $row;
    }

    $copy_id = bulk_translate_find_copy($user_id, $source->ID, $target_lang);
    if ($copy_id) {
        $row['decision'] = 'reuse';
        $row['copy_id'] = $copy_id;
        $row['message'] = 'You already have a ' . $target_lang . ' translation (#' . $copy_id . ').';
        return $row;
    }

    $row['decision'] = 'create';
    $row['lang_unknown'] = ($source_lang === '');
    return $row;
}

/**
 * Translate $decision's source recipe and save the result as a new recipe owned by
 * $user_id. Text comes from the effective source; categories, products and the
 * featured image come from the selected recipe.
 *
 * @return array status ('created', 'skipped' or 'failed'), new_id, title, message.
 */
function bulk_translate_create_copy($user_id, $decision, $target_lang) {
    $source_id = $decision['source_id'];
    $selected_id = $decision['id'];

    // The same plain text the editor would show and send for translation
    $original = array(
        'title' => get_post_field('post_title', $source_id),
        'ingredients' => html_to_plain_text(get_post_meta($source_id, '_recipe_ingredients', true), false),
        'method' => html_to_plain_text(get_post_meta($source_id, '_recipe_method', true), true),
        'notes' => html_to_plain_text(get_post_meta($source_id, '_recipe_notes', true), false),
        'servings' => get_post_meta($source_id, '_recipe_servings', true),
    );
    $source_lang = get_post_meta($source_id, '_recipe_original_lang', true);

    if (trim($original['title']) === '' && trim($original['ingredients']) === '' && trim($original['method']) === '') {
        return array('status' => 'failed', 'new_id' => 0, 'title' => '', 'message' => 'No text to translate.');
    }

    $result = translate_recipe_to_language($original['title'], $original['ingredients'], $original['method'], $target_lang, $original['notes'], $original['servings'], $source_lang);
    if (!$result['success']) {
        return array('status' => 'failed', 'new_id' => 0, 'title' => '', 'message' => $result['error']);
    }
    $translated = $result['data'];

    // Language wasn't stored: the model reported it. Nothing is written to the source.
    if ($source_lang === '' && !empty($translated['source_language']) && strcasecmp($translated['source_language'], $target_lang) === 0) {
        return array('status' => 'skipped', 'new_id' => 0, 'title' => '', 'message' => 'Already in ' . $target_lang . ' (detected).');
    }

    // Never save a mix of languages: every field the original has must come back
    $missing = array();
    foreach ($original as $key => $value) {
        if (trim((string) $value) !== '' && trim(isset($translated[$key]) ? (string) $translated[$key] : '') === '') {
            $missing[] = $key;
        }
    }
    if (!empty($missing)) {
        return array('status' => 'failed', 'new_id' => 0, 'title' => '', 'message' => 'Translation came back incomplete (missing: ' . implode(', ', $missing) . '). Nothing was created.');
    }

    // Same sanitizing and formatting as the editor's save handler
    $title = sanitize_text_field($translated['title']);
    $ingredients = format_recipe_content_html(sanitize_textarea_field($translated['ingredients']), false);
    $method = trim(preg_replace('/\.\s+/u', ".\n", (string) $translated['method']));
    $method = format_recipe_content_html(sanitize_textarea_field($method), true);
    $notes = format_recipe_notes_html(sanitize_textarea_field($translated['notes']));
    $servings = sanitize_text_field($translated['servings']);

    if ($title === '') {
        return array('status' => 'failed', 'new_id' => 0, 'title' => '', 'message' => 'Translation came back without a title. Nothing was created.');
    }

    $new_id = wp_insert_post(array(
        'post_title' => $title,
        'post_type' => 'recipe',
        'post_status' => 'publish',
        'post_author' => $user_id,
    ), true);
    if (is_wp_error($new_id) || !$new_id) {
        return array('status' => 'failed', 'new_id' => 0, 'title' => $title, 'message' => 'Error saving recipe: ' . (is_wp_error($new_id) ? $new_id->get_error_message() : 'unknown error'));
    }

    update_post_meta($new_id, '_recipe_ingredients', $ingredients);
    update_post_meta($new_id, '_recipe_method', $method);
    update_post_meta($new_id, '_recipe_notes', $notes);
    update_post_meta($new_id, '_recipe_servings', $servings);
    update_post_meta($new_id, '_recipe_original_lang', $target_lang);
    update_post_meta($new_id, '_recipe_translated_from', $source_id);

    // Shared with the selected recipe, which keeps it as its own (post_parent untouched)
    $thumbnail_id = get_post_thumbnail_id($selected_id);
    if ($thumbnail_id) {
        set_post_thumbnail($new_id, $thumbnail_id);
    }

    // The selected recipe's categories, mapped by name onto the current user's own,
    // plus a food category named after the target language
    $categories = array();
    foreach (get_recipe_categories($selected_id) as $cat) {
        $categories[] = ($cat->user_id == $user_id)
            ? (int) $cat->cat_id
            : get_or_create_user_category($user_id, $cat->cat_name, $cat->category_type);
    }
    $categories[] = get_or_create_user_category($user_id, $target_lang, 'food');
    set_recipe_categories($new_id, array_values(array_unique(array_filter($categories))));

    $product_ids = array_map(function($product) { return (int) $product->product_id; }, get_recipe_products($selected_id));
    set_recipe_products($new_id, $product_ids);

    return array('status' => 'created', 'new_id' => (int) $new_id, 'title' => $title, 'message' => '');
}

function bulk_translate_plan_key($user_id) {
    return 'recipe_bulk_translate_plan_' . intval($user_id);
}

/**
 * The posted target language, if it is one of the editor's dropdown languages
 */
function bulk_translate_posted_target() {
    $target = isset($_POST['target_language']) ? normalize_recipe_language(wp_unslash($_POST['target_language'])) : '';
    return in_array($target, get_recipe_language_names(), true) ? $target : '';
}

/**
 * AJAX Handler: decide what translating the selected recipes would do, and save the plan
 */
add_action('wp_ajax_recipe_bulk_translate_preview', 'handle_recipe_bulk_translate_preview');

function handle_recipe_bulk_translate_preview() {
    check_ajax_referer('recipe_bulk_translate', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $target = bulk_translate_posted_target();
    if ($target === '') {
        wp_send_json_error(array('message' => 'Please choose a language to translate into.'));
    }

    $ids = isset($_POST['recipe_ids']) ? array_map('intval', (array) $_POST['recipe_ids']) : array();
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) {
        wp_send_json_error(array('message' => 'No recipes selected.'));
    }

    $user_id = get_current_user_id();
    $rows = array();
    $plan_ids = array();
    $counts = array('create' => 0, 'reuse' => 0, 'skip' => 0, 'error' => 0, 'deferred' => 0);

    // In list order: the first BULK_TRANSLATE_MAX_CREATES CREATEs run now, the rest next time
    foreach ($ids as $id) {
        $row = bulk_translate_decide($user_id, $id, $target);
        $row['deferred'] = false;
        if ($row['decision'] === 'create') {
            if (count($plan_ids) < BULK_TRANSLATE_MAX_CREATES) {
                $plan_ids[] = $row['id'];
                $counts['create']++;
            } else {
                $row['deferred'] = true;
                $counts['deferred']++;
            }
        } else {
            $counts[$row['decision']]++;
        }
        $row['url'] = home_url('/recipe-view-page/?ids=' . $row['id']);
        $row['copy_url'] = $row['copy_id'] ? home_url('/recipe-view-page/?ids=' . $row['copy_id']) : '';
        $rows[] = $row;
    }

    set_transient(bulk_translate_plan_key($user_id), array('target' => $target, 'ids' => $plan_ids), BULK_TRANSLATE_PLAN_TTL);

    wp_send_json_success(array(
        'target' => $target,
        'rows' => $rows,
        'plan_ids' => $plan_ids,
        'counts' => $counts,
        'max_creates' => BULK_TRANSLATE_MAX_CREATES,
    ));
}

/**
 * AJAX Handler: translate one recipe from the saved plan into a new recipe
 */
add_action('wp_ajax_recipe_bulk_translate_one', 'handle_recipe_bulk_translate_one');

function handle_recipe_bulk_translate_one() {
    check_ajax_referer('recipe_bulk_translate', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $user_id = get_current_user_id();
    $recipe_id = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;

    $plan = get_transient(bulk_translate_plan_key($user_id));
    if (!is_array($plan) || empty($plan['target']) || !isset($plan['ids']) || !is_array($plan['ids'])) {
        wp_send_json_error(array('message' => 'This translation run has expired. Close this window and preview again.'));
    }

    // Each planned ID runs once, so a run never creates more than the plan's capped list
    $position = array_search($recipe_id, $plan['ids'], true);
    if ($position === false) {
        wp_send_json_error(array('message' => 'This recipe is not part of the previewed translation run.'));
    }
    array_splice($plan['ids'], $position, 1);
    set_transient(bulk_translate_plan_key($user_id), $plan, BULK_TRANSLATE_PLAN_TTL);

    $target = $plan['target'];

    // Things may have changed since the preview
    $decision = bulk_translate_decide($user_id, $recipe_id, $target);
    $reply = array(
        'id' => $recipe_id,
        'title' => $decision['title'],
        'source_id' => $decision['source_id'],
        'new_id' => 0,
        'url' => '',
        'message' => $decision['message'],
    );

    if ($decision['decision'] === 'error') {
        $reply['status'] = 'failed';
    } elseif ($decision['decision'] === 'skip') {
        $reply['status'] = 'skipped';
    } elseif ($decision['decision'] === 'reuse') {
        $reply['status'] = 'reused';
        $reply['new_id'] = $decision['copy_id'];
        $reply['url'] = home_url('/recipe-view-page/?ids=' . $decision['copy_id']);
    } else {
        $created = bulk_translate_create_copy($user_id, $decision, $target);
        $reply['status'] = $created['status'];
        $reply['message'] = $created['message'];
        if ($created['new_id']) {
            $reply['new_id'] = $created['new_id'];
            $reply['new_title'] = $created['title'];
            $reply['url'] = home_url('/recipe-view-page/?ids=' . $created['new_id']);
        }
    }

    wp_send_json_success($reply);
}
