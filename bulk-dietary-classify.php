<?php
/**
 * Bulk Dietary Classification Backfill
 *
 * WP-CLI script. Run with WordPress already bootstrapped by WP-CLI, e.g.:
 *
 *   wp eval-file bulk-dietary-classify.php
 *
 * Loops every published recipe, classifies it for vegan/vegetarian/
 * gluten-free via classify_recipe_dietary(), and adds/removes the matching
 * category (created per-author on first use, category_type = 'food') on
 * each recipe. All other categories already on a recipe are preserved.
 *
 * Unlike the theme's other CLI utilities (migrate-to-custom-categories.php,
 * etc.) this one relies on wp-cli's own bootstrap instead of loading
 * wp-load.php itself, since wp eval-file already runs inside a fully
 * loaded WordPress environment.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    echo "This script must be run via WP-CLI: wp eval-file bulk-dietary-classify.php\n";
    return;
}

require_once(get_stylesheet_directory() . '/recipe-dietary-classifier.php');
require_once(get_stylesheet_directory() . '/custom-category-functions.php');

WP_CLI::log('Starting bulk dietary classification...');
WP_CLI::log('');

$dietary_labels = array(
    'vegan' => 'Vegan',
    'vegetarian' => 'Vegetarian',
    'gluten_free' => 'Gluten-Free',
);

$recipe_ids = get_posts(array(
    'post_type' => 'recipe',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'orderby' => 'ID',
    'order' => 'ASC',
));

$total = count($recipe_ids);
$processed = 0;
$categories_added = 0;
$errors = 0;
$skipped_no_ingredients = 0;
$error_log = array();

WP_CLI::log("Found {$total} published recipes.");
WP_CLI::log('');

foreach ($recipe_ids as $recipe_id) {
    $processed++;

    $recipe = get_post($recipe_id);
    if (!$recipe) {
        $errors++;
        $error_log[] = "Recipe {$recipe_id}: post not found";
        continue;
    }

    $ingredients_text = get_post_meta($recipe_id, '_recipe_ingredients', true);

    if (empty(trim(wp_strip_all_tags($ingredients_text)))) {
        $skipped_no_ingredients++;
        continue;
    }

    $classification = classify_recipe_dietary($ingredients_text);

    if ($classification === null) {
        $errors++;
        $error_log[] = "Recipe {$recipe_id} ({$recipe->post_title}): classification failed (API error or unparseable response)";
    } else {
        $author_id = $recipe->post_author;

        $current_cat_ids = wp_list_pluck(get_recipe_categories($recipe_id), 'cat_id');
        $new_cat_ids = $current_cat_ids;

        foreach ($dietary_labels as $key => $cat_name) {
            $existing_cat = get_user_category_by_name($author_id, $cat_name);
            $cat_id = null;

            if ($classification[$key]) {
                if ($existing_cat) {
                    $cat_id = $existing_cat->cat_id;
                } else {
                    $result = create_user_category($author_id, $cat_name, 'food');
                    if (isset($result['cat_id'])) {
                        $cat_id = $result['cat_id'];
                    } else {
                        $errors++;
                        $error_log[] = "Recipe {$recipe_id} ({$recipe->post_title}): failed to create category '{$cat_name}' for user {$author_id}";
                    }
                }

                if ($cat_id && !in_array($cat_id, $new_cat_ids)) {
                    $new_cat_ids[] = $cat_id;
                    $categories_added++;
                }
            }
            // If false, leave the recipe's categories alone - don't remove an
            // already-assigned dietary category on a false classification, since
            // that would silently undo a manual assignment (or a prior correct
            // classification) whenever the model's read of the ingredients shifts.
        }

        if ($new_cat_ids != $current_cat_ids) {
            set_recipe_categories($recipe_id, array_values($new_cat_ids));
        }
    }

    if ($processed % 10 === 0) {
        WP_CLI::log("Progress: {$processed} / {$total} recipes processed...");
    }

    // Rate limit API calls.
    usleep(500000);
}

WP_CLI::log('');
WP_CLI::log('=== Bulk Dietary Classification Summary ===');
WP_CLI::log("Total recipes found: {$total}");
WP_CLI::log("Total processed: {$processed}");
WP_CLI::log("Skipped (no ingredients): {$skipped_no_ingredients}");
WP_CLI::log("Categories added: {$categories_added}");
WP_CLI::log("Errors: {$errors}");

if (!empty($error_log)) {
    WP_CLI::log('');
    WP_CLI::log('--- Error details ---');
    foreach ($error_log as $line) {
        WP_CLI::log($line);
    }
}

WP_CLI::success('Bulk dietary classification complete.');
