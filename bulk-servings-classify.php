<?php
/**
 * Bulk Servings Classification Backfill
 *
 * WP-CLI script. Run with WordPress already bootstrapped by WP-CLI, e.g.:
 *
 *   wp eval-file bulk-servings-classify.php
 *
 * Loops every published recipe and, for any recipe whose _recipe_servings
 * is currently empty, estimates it via estimate_recipe_servings() and saves
 * the result. Add-only, same as the servings wiring in page-recipe-editor.php:
 * a recipe that already has a value (manually entered or from a prior
 * estimate) is left untouched.
 *
 * Unlike the theme's other CLI utilities (migrate-to-custom-categories.php,
 * etc.) this one relies on wp-cli's own bootstrap instead of loading
 * wp-load.php itself, since wp eval-file already runs inside a fully
 * loaded WordPress environment. Follows the same shape as
 * bulk-dietary-classify.php.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    echo "This script must be run via WP-CLI: wp eval-file bulk-servings-classify.php\n";
    return;
}

require_once(get_stylesheet_directory() . '/recipe-servings-classifier.php');

WP_CLI::log('Starting bulk servings classification...');
WP_CLI::log('');

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
$servings_added = 0;
$skipped_already_set = 0;
$errors = 0;
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

    $current_servings = get_post_meta($recipe_id, '_recipe_servings', true);

    if (!empty($current_servings)) {
        $skipped_already_set++;
        continue;
    }

    $ingredients_text = get_post_meta($recipe_id, '_recipe_ingredients', true);
    $method_text = get_post_meta($recipe_id, '_recipe_method', true);

    $estimated_servings = estimate_recipe_servings($ingredients_text, $method_text);

    if ($estimated_servings === null) {
        $errors++;
        $error_log[] = "Recipe {$recipe_id} ({$recipe->post_title}): estimation failed (API error or nothing to estimate from)";
    } else {
        update_post_meta($recipe_id, '_recipe_servings', $estimated_servings);
        $servings_added++;
    }

    if ($processed % 10 === 0) {
        WP_CLI::log("Progress: {$processed} / {$total} recipes processed...");
    }

    // Rate limit API calls.
    usleep(500000);
}

WP_CLI::log('');
WP_CLI::log('=== Bulk Servings Classification Summary ===');
WP_CLI::log("Total recipes found: {$total}");
WP_CLI::log("Total processed: {$processed}");
WP_CLI::log("Servings added: {$servings_added}");
WP_CLI::log("Skipped (already set): {$skipped_already_set}");
WP_CLI::log("Errors: {$errors}");

if (!empty($error_log)) {
    WP_CLI::log('');
    WP_CLI::log('--- Error details ---');
    foreach ($error_log as $line) {
        WP_CLI::log($line);
    }
}

WP_CLI::success('Bulk servings classification complete.');
