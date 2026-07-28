<?php
/**
 * Template Name: Allergen Checker
 *
 * Batch-check recipes and products against the user's active allergen profile.
 */

get_header();

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/'));
    exit;
}

require_once(get_stylesheet_directory() . '/collection-permissions.php');
require_once(get_stylesheet_directory() . '/allergen-functions.php');
require_once(get_stylesheet_directory() . '/allergen-permissions.php');
require_once(get_stylesheet_directory() . '/allergen-matching-engine.php');

$current_user_id = get_current_user_id();
$active_profile = get_active_allergen_profile($current_user_id);

$results = array();
$ran_check = false;
$selected_recipe_ids = array();
$selected_product_ids = array();

if ($active_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_allergen_check'])) {
    // Manual picker on this page: user checked boxes and clicked "Run Check".
    check_admin_referer('run_allergen_check');
    $selected_recipe_ids = isset($_POST['selected_recipe_ids']) ? array_map('intval', (array) $_POST['selected_recipe_ids']) : array();
    $selected_product_ids = isset($_POST['selected_product_ids']) ? array_map('intval', (array) $_POST['selected_product_ids']) : array();
} elseif ($active_profile && !empty($_GET['ids'])) {
    // Arrived via the "Check Allergens" bulk action on Recipe Manager —
    // the selection was already made there, so run immediately. That
    // action only ever selects recipes (products have no bulk-action
    // entry point yet), so there's no product-side equivalent to read here.
    $selected_recipe_ids = array_map('intval', explode(',', $_GET['ids']));
} elseif ($active_profile) {
    $transient_ids = get_transient('recipe_check_allergens_' . $current_user_id);
    if (!empty($transient_ids)) {
        $selected_recipe_ids = array_map('intval', $transient_ids);
    }
}

if (!empty($selected_recipe_ids) || !empty($selected_product_ids)) {
    foreach ($selected_recipe_ids as $recipe_id) {
        $recipe_post = get_post($recipe_id);
        if (!$recipe_post || $recipe_post->post_type !== 'recipe') {
            continue;
        }

        $report = run_allergen_check('recipe', $recipe_id, $active_profile->profile_id);
        $report['item_title'] = get_the_title($recipe_id);
        $results[] = $report;
    }

    foreach ($selected_product_ids as $product_id) {
        $product = get_product_by_id($product_id);
        // Phase 3: own products only, no sharing yet — mirrors Phase 3
        // scope for the product library generally.
        if (!$product || $product->owner_user_id != $current_user_id) {
            continue;
        }

        $report = run_allergen_check('product', $product_id, $active_profile->profile_id);
        $report['item_title'] = $product->product_name;
        $results[] = $report;
    }

    $ran_check = true;
}

$flagged_count = 0;
foreach ($results as $result) {
    if ($result['flagged']) {
        $flagged_count++;
    }
}

// Build the recipe picker: exactly one collection at a time, same
// resolution logic as page-recipe-manager.php — NOT a flattened merge of
// every accessible collection, which for an admin (who can access every
// author's collection) would silently become "every recipe in the DB."
$accessible_collections = $active_profile ? get_accessible_collections($current_user_id) : array();

$selected_collection = isset($_GET['collection']) ? intval($_GET['collection']) : $current_user_id;
$current_collection = null;
$can_access_selected = false;

foreach ($accessible_collections as $collection) {
    if ($collection['owner_id'] == $selected_collection) {
        $can_access_selected = true;
        $current_collection = $collection;
        break;
    }
}

if (!$can_access_selected && !empty($accessible_collections)) {
    $current_collection = $accessible_collections[0];
    $selected_collection = $current_collection['owner_id'];
}

$pickable_recipes = array();

if ($current_collection) {
    $recipes_query = new WP_Query(array(
        'post_type' => 'recipe',
        'posts_per_page' => -1,
        'author' => $selected_collection,
        'orderby' => 'title',
        'order' => 'ASC',
    ));

    while ($recipes_query->have_posts()) {
        $recipes_query->the_post();
        $pickable_recipes[] = array(
            'id' => get_the_ID(),
            'title' => get_the_title(),
            'owner_name' => $current_collection['owner_name'],
            'is_own' => ($selected_collection == $current_user_id),
        );
    }
    wp_reset_postdata();
}

// Own products only in Phase 3 — product sharing is Phase 5.
$pickable_products = $active_profile ? get_user_products($current_user_id) : array();

?>

<style>
.allergen-checker {
    max-width: 1100px;
    margin: 40px auto;
    padding: 0 20px;
}

.allergen-checker h1 {
    color: #c84a31;
    font-size: 36px;
    margin-bottom: 10px;
}

.allergen-checker-subtitle {
    color: #666;
    margin-bottom: 20px;
}

.allergen-disclaimer {
    background: #fff3cd;
    border: 2px solid #ffc107;
    color: #664d03;
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 25px;
    font-size: 14px;
}

.active-profile-banner {
    background: #ede9fe;
    border: 2px solid #7c3aed;
    color: #4c1d95;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 25px;
    font-size: 14px;
}

.no-active-profile {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 20px;
    border-radius: 6px;
    margin-bottom: 25px;
}

.recipe-picker-box {
    background: #f9f9f9;
    border: 2px solid #dee2e6;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 25px;
}

.recipe-picker-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 400px;
    overflow-y: auto;
    margin-bottom: 15px;
}

.recipe-picker-list label {
    font-size: 14px;
}

.run-check-btn {
    padding: 12px 30px;
    background: #00a32a;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
}

.run-check-btn:hover {
    background: #008a20;
}

.batch-summary {
    background: #e2e3e5;
    border: 2px solid #adb5bd;
    color: #383d41;
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 25px;
    font-size: 16px;
    font-weight: bold;
}

.item-report {
    background: white;
    border: 2px solid #dee2e6;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
}

.item-report.flagged {
    border-color: #dc3545;
}

.item-report h3 {
    margin: 0 0 5px 0;
    font-size: 20px;
}

.item-report-profile-header {
    font-size: 13px;
    color: #495057;
    background: #f8f9fa;
    padding: 8px 12px;
    border-radius: 4px;
    margin-bottom: 10px;
}

.item-report-disclaimer {
    font-size: 12px;
    color: #664d03;
    margin-bottom: 15px;
}

.indeterminate-section {
    background: #cfe2ff;
    border: 2px solid #0d6efd;
    color: #084298;
    padding: 12px 15px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.indeterminate-section-title {
    font-weight: bold;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.03em;
    margin-bottom: 6px;
}

.allergen-result {
    padding: 10px 12px;
    border-radius: 4px;
    margin-bottom: 8px;
}

.allergen-result.tier-contains {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
}

.allergen-result.tier-may_contain {
    background: #fff3cd;
    border: 1px solid #ffc107;
}

.allergen-result-name {
    font-weight: bold;
}

.allergen-result-tier-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-left: 8px;
}

.allergen-match-line {
    font-family: monospace;
    font-size: 13px;
    background: rgba(255,255,255,0.6);
    padding: 4px 8px;
    border-radius: 3px;
    margin-top: 4px;
}

.allergen-match-source {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    margin-right: 6px;
}

.not-detected-section {
    margin-top: 15px;
    padding-top: 10px;
    border-top: 1px dashed #ccc;
    font-size: 13px;
    color: #666;
}

.general-caution {
    background: #fff3cd;
    border: 1px solid #ffc107;
    padding: 8px 12px;
    border-radius: 4px;
    margin-bottom: 8px;
    font-size: 13px;
}

.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #0073aa;
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
}
</style>

<div class="allergen-checker">
    <a href="<?php echo home_url('/recipe-manager/'); ?>" class="back-link">← Back to Recipe Manager</a>

    <h1>Allergen Checker</h1>
    <p class="allergen-checker-subtitle">Check recipes against your active allergen profile.</p>

    <div class="allergen-disclaimer">
        This tool assists in reading ingredient labels and recipes. It does not replace reading the label yourself. Always verify against the physical product before consuming.
    </div>

    <?php if (!$active_profile): ?>
    <div class="no-active-profile">
        <strong>No active allergen profile selected.</strong>
        <p>You need an active profile before you can run a check. <a href="<?php echo home_url('/allergen-profiles/'); ?>">Go to Allergen Profiles</a> to create one and set it active.</p>
    </div>
    <?php else: ?>

    <div class="active-profile-banner">
        Checking against: <strong><?php echo esc_html($active_profile->profile_name); ?></strong><?php if ($active_profile->profile_age !== null): ?>, age <?php echo esc_html($active_profile->profile_age); ?><?php endif; ?>
        (profile last updated <?php echo esc_html(mysql2date('F j, Y', $active_profile->updated_date)); ?>)
        — <a href="<?php echo home_url('/allergen-profiles/'); ?>">change profile</a>
    </div>

    <?php if ($ran_check): ?>
        <div class="batch-summary">
            <?php echo esc_html($flagged_count); ?> of <?php echo count($results); ?> item<?php echo count($results) === 1 ? '' : 's'; ?> flagged
        </div>

        <?php foreach ($results as $result): ?>
        <div class="item-report <?php echo $result['flagged'] ? 'flagged' : ''; ?>">
            <h3><?php echo esc_html($result['item_title']); ?></h3>

            <div class="item-report-profile-header">
                Active profile: <strong><?php echo esc_html($active_profile->profile_name); ?></strong><?php if ($active_profile->profile_age !== null): ?>, age <?php echo esc_html($active_profile->profile_age); ?><?php endif; ?>
                &middot; profile last updated <?php echo esc_html(mysql2date('F j, Y', $active_profile->updated_date)); ?>
            </div>
            <div class="item-report-disclaimer">
                This tool assists in reading ingredient labels and recipes. It does not replace reading the label yourself. Always verify against the physical product before consuming.
            </div>

            <?php if (!empty($result['indeterminate_ingredients'])): ?>
            <div class="indeterminate-section">
                <div class="indeterminate-section-title">Cannot determine — verify package label</div>
                <div>This recipe includes packaged/compound ingredients whose own allergen content this tool cannot read from the recipe text alone. Check the physical product label for these:</div>
                <?php foreach ($result['indeterminate_ingredients'] as $indeterminate): ?>
                <div class="allergen-match-line">
                    <span class="allergen-match-source"><?php echo esc_html($indeterminate['source']); ?></span><?php echo esc_html($indeterminate['line']); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            $flagged_allergens = array();
            $not_detected_allergens = array();
            foreach ($result['allergens'] as $allergen) {
                if ($allergen['tier'] === 'not_detected') {
                    $not_detected_allergens[] = $allergen;
                } else {
                    $flagged_allergens[] = $allergen;
                }
            }
            ?>

            <?php if (!empty($flagged_allergens)): ?>
                <?php foreach ($flagged_allergens as $allergen): ?>
                <div class="allergen-result tier-<?php echo esc_attr($allergen['tier']); ?>">
                    <span class="allergen-result-name"><?php echo esc_html($allergen['allergen_name']); ?></span>
                    <span class="allergen-result-tier-label">
                        <?php echo $allergen['tier'] === 'contains' ? 'Contains' : 'May contain / shared facility'; ?>
                    </span>
                    <?php foreach ($allergen['matches'] as $match): ?>
                    <div class="allergen-match-line">
                        <span class="allergen-match-source"><?php echo esc_html($match['source']); ?></span><?php echo esc_html($match['line']); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #155724;">No allergens from this profile were flagged as Contains or May Contain.</p>
            <?php endif; ?>

            <?php if (!empty($result['general_cautions'])): ?>
                <?php foreach ($result['general_cautions'] as $caution): ?>
                <div class="general-caution">
                    <strong>General caution</strong> (no specific allergen from this profile named) —
                    <span class="allergen-match-source"><?php echo esc_html($caution['source']); ?></span><?php echo esc_html($caution['line']); ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($not_detected_allergens)): ?>
            <div class="not-detected-section">
                <strong>Not detected</strong> (no match found by this tool — not the same as confirmed safe):
                <?php
                $not_detected_labels = array();
                foreach ($not_detected_allergens as $allergen) {
                    // Show what was actually searched for, for transparency.
                    // Excludes the canonical name itself (already shown) and
                    // truncates long alias lists rather than dumping all of them.
                    $display_aliases = array_values(array_diff($allergen['aliases'], array(strtolower($allergen['allergen_name']))));
                    if (!empty($display_aliases)) {
                        $shown = array_slice($display_aliases, 0, 4);
                        $suffix = count($display_aliases) > 4 ? ', ...' : '';
                        $not_detected_labels[] = esc_html($allergen['allergen_name']) . ' <span style="color:#999; font-weight: normal;">(' . esc_html(implode(', ', $shown)) . $suffix . ')</span>';
                    } else {
                        $not_detected_labels[] = esc_html($allergen['allergen_name']);
                    }
                }
                echo implode(', ', $not_detected_labels);
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('run_allergen_check'); ?>

        <div class="recipe-picker-box">
            <h2 style="margin-top: 0;">Select Recipes to Check</h2>

            <?php if (count($accessible_collections) > 1): ?>
            <div style="margin-bottom: 15px;">
                <label for="allergen-collection-selector" style="font-weight: 600; margin-right: 8px;">📚 Collection:</label>
                <select id="allergen-collection-selector" onchange="window.location.href='<?php echo home_url('/allergen-checker/'); ?>?collection=' + this.value" style="padding: 6px 12px; font-size: 14px; border: 2px solid #2271b1; border-radius: 4px;">
                    <?php foreach ($accessible_collections as $collection): ?>
                    <option value="<?php echo $collection['owner_id']; ?>" <?php selected($selected_collection, $collection['owner_id']); ?>>
                        <?php echo esc_html($collection['owner_name']); ?>'s Recipes<?php echo ($collection['owner_id'] == $current_user_id) ? ' (Yours)' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="recipe-picker-list">
                <?php if (!empty($pickable_recipes)): ?>
                    <?php foreach ($pickable_recipes as $recipe): ?>
                    <label>
                        <input type="checkbox" name="selected_recipe_ids[]" value="<?php echo $recipe['id']; ?>" <?php checked(in_array($recipe['id'], $selected_recipe_ids)); ?>>
                        <?php echo esc_html($recipe['title']); ?>
                        <?php if (!$recipe['is_own']): ?><span style="color:#666;">(<?php echo esc_html($recipe['owner_name']); ?>'s)</span><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No recipes available to check.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="recipe-picker-box">
            <h2 style="margin-top: 0;">Select Products to Check</h2>
            <p style="font-size: 13px; color: #666; margin-top: 0;">From your <a href="<?php echo home_url('/allergen-products/'); ?>">Product Library</a>.</p>

            <div class="recipe-picker-list">
                <?php if (!empty($pickable_products)): ?>
                    <?php foreach ($pickable_products as $product): ?>
                    <label>
                        <input type="checkbox" name="selected_product_ids[]" value="<?php echo $product->product_id; ?>" <?php checked(in_array($product->product_id, $selected_product_ids)); ?>>
                        <?php echo esc_html($product->product_name); ?>
                    </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No products in your library yet. <a href="<?php echo home_url('/allergen-products/'); ?>">Scan one</a> to check it here.</p>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" name="run_allergen_check" class="run-check-btn">Run Check</button>
    </form>

    <?php endif; ?>
</div>

<?php get_footer(); ?>
