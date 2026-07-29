<?php
/**
 * Template Name: Recipe View Page
 *
 * Screen-optimized view for selected recipes
 *
 * @version 2.2.0
 * @changelog
 *   2.2.0 - Back link and Edit link now preserve food_cat/author_cat/search state
 *            from the new two-group filter system, so navigating to edit a recipe
 *            and back no longer resets the active filters.
 *   2.1.3 - Show featured image (Original Image) after notes section.
 *   2.1.2 - Show all categories comma-separated, not just first one.
 *   2.1.1 - Fixed admin losing edit access. Fixed Uncategorized bug (use get_recipe_categories).
 *   2.1.0 - Edit button now checks user_can_manage_collection() in addition to edit_posts,
 *            so viewers of other collections no longer see an Edit button they can't use.
 *   1.0.0 - Initial release.
 */

// Get selected recipes - try URL first, then transient
$recipe_ids = array();

if (!empty($_GET['ids'])) {
    $recipe_ids = array_map('intval', explode(',', $_GET['ids']));
} else {
    $recipe_ids = get_transient('recipe_view_' . get_current_user_id());
}

if (empty($recipe_ids)) {
    wp_redirect(home_url('/recipe-manager/'));
    exit;
}

get_header();

require_once(get_stylesheet_directory() . '/collection-permissions.php');

// Allergen Checker v2 Phase E: live per-viewer check, not a stored snapshot —
// a stored "scanned at" flag would reflect whoever's profile was active at
// scan time, not the current viewer's, and a missing flag could be misread
// as "checked and clear" for a viewer it was never actually checked against.
// Fetched once here (not per-recipe) since it's the same viewer/profile for
// every recipe on this page.
$viewer_active_allergen_profile = get_active_allergen_profile(get_current_user_id());

// Carry filter/search state from the incoming URL, for the Back link and Edit links
$state_parts = array();
if (!empty($_GET['food_cat'])) {
    $state_parts[] = 'food_cat=' . rawurlencode($_GET['food_cat']);
}
if (!empty($_GET['author_cat'])) {
    $state_parts[] = 'author_cat=' . rawurlencode($_GET['author_cat']);
}
if (!empty($_GET['rs'])) {
    $state_parts[] = 'rs=' . rawurlencode($_GET['rs']);
}

if (!empty($_GET['collection'])) {
    $state_parts[] = 'collection=' . intval($_GET['collection']);
}
$state_query = empty($state_parts) ? '' : '&' . implode('&', $state_parts);

// Build back URL with filter/search state preserved
$back_url = home_url('/recipe-manager/');
if (!empty($state_parts)) {
    $back_url .= '?' . implode('&', $state_parts);
}
?>

<style>
/* Card header: ID badge, title, category, warning icon, Edit button all
   sit on one flex row with no wrap — fine at desktop widths, but on a
   phone the title gets squeezed into a narrow multi-line stack of single
   words. flex-wrap lets each piece drop to its own line instead. */
.recipe-view-card-header {
    flex-wrap: wrap;
}

/* Ingredients/Method grid: 1fr 1fr leaves only ~122px per column at
   375px width, wrapping every line into 3-4 lines. Collapse to a single
   column below the same 430px breakpoint used elsewhere in this codebase
   (recipe-manager.css, recipe-editor.css). !important is needed here
   because the base layout is set via inline style="grid-template-columns:
   1fr 1fr", which otherwise outranks an external stylesheet rule. */
@media (max-width: 430px) {
    .recipe-view-content-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<div style="max-width: 1200px; margin: 40px auto; padding: 0 20px;">
    
    <div style="text-align: center; margin-bottom: 30px;">
        <a href="<?php echo esc_url($back_url); ?>" style="display: inline-block; padding: 10px 20px; background: #666; color: white; text-decoration: none; border-radius: 4px;">
            ← Back to Recipe Manager
        </a>
    </div>
    
    <div style="text-align: center; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 3px solid #c84a31;">
        <h1 style="color: #c84a31; font-size: 36px; margin: 0;">Selected Recipes</h1>
        <p style="color: #666; margin: 10px 0 0 0;"><?php echo count($recipe_ids); ?> recipes</p>
    </div>
    
    <?php
    $args = array(
        'post_type' => 'recipe',
        'posts_per_page' => -1,
        'post__in' => $recipe_ids,
        'orderby' => 'post__in',
    );
    
    $recipes = new WP_Query($args);
    
    while ($recipes->have_posts()): 
        $recipes->the_post();
        $post_id = get_the_ID();
        
        $recipe_id = get_post_meta($post_id, '_recipe_id', true);
        $ingredients = get_post_meta($post_id, '_recipe_ingredients', true);
        $method = get_post_meta($post_id, '_recipe_method', true);
        $notes = get_post_meta($post_id, '_recipe_notes', true);
        
        $recipe_cats = get_recipe_categories($post_id);
        $category = !empty($recipe_cats) ? implode(', ', array_map(function($c) { return $c->cat_name; }, $recipe_cats)) : 'Uncategorized';

        $recipe_author_id = get_post_field('post_author', $post_id);
        $can_edit = current_user_can('administrator') ||
                    (current_user_can('edit_posts') && user_can_manage_collection(get_current_user_id(), $recipe_author_id));

        $live_allergen_check = $viewer_active_allergen_profile
            ? run_allergen_check('recipe', $post_id, $viewer_active_allergen_profile->profile_id)
            : null;
    ?>
    
    <div style="background: white; border: 2px solid #c84a31; margin-bottom: 40px; border-radius: 8px; overflow: hidden; page-break-inside: avoid;">
        
        <div class="recipe-view-card-header" style="background: #c84a31; color: white; padding: 20px; display: flex; align-items: center; justify-content: space-between;">
            <span style="font-family: 'Courier New', monospace; font-weight: bold; font-size: 14px; background: white; color: #c84a31; padding: 4px 10px; border-radius: 4px;">
                <?php echo esc_html($recipe_id); ?>
            </span>
            <h2 style="flex: 1; margin: 0 20px; font-size: 24px; color: white;">
                <?php the_title(); ?>
                <?php if ($live_allergen_check && $live_allergen_check['flagged']): ?>
                <span title="Flagged against your active allergen profile — see the warning below" style="font-size: 16px; margin-left: 8px;">⚠️</span>
                <?php endif; ?>
            </h2>
            <span style="font-size: 14px; font-style: italic; margin-right: 15px;">
                <?php echo esc_html($category); ?>
            </span>
            <?php if ($can_edit): 
                $edit_url = home_url('/recipe-editor/?id=' . $post_id . $state_query);
            ?>
            <a href="<?php echo esc_url($edit_url); ?>" style="background: white; color: #c84a31; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 14px;">
                ✏️ Edit
            </a>
            <?php endif; ?>
        </div>
        
        <div class="recipe-view-content-grid" style="padding: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">

            <?php if ($live_allergen_check && $live_allergen_check['flagged']): ?>
            <div style="grid-column: 1 / -1; background: #fff3cd; border: 2px solid #ffc107; border-radius: 6px; padding: 15px 20px;">
                <p style="margin: 0 0 10px 0; font-weight: bold; color: #856404;">
                    ⚠️ Flagged against your active allergen profile, "<?php echo esc_html($viewer_active_allergen_profile->profile_name); ?>"
                </p>
                <p style="margin: 0 0 10px 0; font-size: 13px; color: #856404;">
                    Checked live, just now, against this profile. This tool never claims a recipe is "safe" — use the Allergen Checker for the full report, including any attached products.
                </p>
                <ul style="margin: 0; padding-left: 20px; color: #664d03;">
                    <?php foreach ($live_allergen_check['allergens'] as $flagged_allergen): ?>
                    <?php if ($flagged_allergen['tier'] === 'not_detected') continue; ?>
                    <li>
                        <strong><?php echo esc_html($flagged_allergen['allergen_name']); ?></strong>
                        (<?php echo $flagged_allergen['tier'] === 'contains' ? 'Contains' : 'May contain'; ?>)
                        <?php if (!empty($flagged_allergen['matches'])): ?>
                        — matched: "<?php echo esc_html($flagged_allergen['matches'][0]['line']); ?>" (<?php echo esc_html($flagged_allergen['matches'][0]['source']); ?>)
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div>
                <h3 style="color: #c84a31; font-size: 20px; margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #c84a31;">
                    Ingredients
                </h3>
                <div class="recipe-content">
                    <?php echo wp_kses_post($ingredients); ?>
                </div>
            </div>
            
            <div>
                <h3 style="color: #c84a31; font-size: 20px; margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #c84a31;">
                    Method
                </h3>
                <div class="recipe-content">
                    <?php echo wp_kses_post($method); ?>
                </div>
            </div>
            
            <?php if (!empty($notes)): ?>
            <div style="grid-column: 1 / -1; margin-top: 20px; padding-top: 20px; border-top: 2px dashed #ddd;">
                <h3 style="color: #c84a31; font-size: 18px; margin: 0 0 10px 0;">Notes</h3>
                <div style="font-style: italic; color: #666;">
                    <?php echo wp_kses_post($notes); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $attached_products = get_recipe_products($post_id); ?>
            <?php if (!empty($attached_products)): ?>
            <div style="grid-column: 1 / -1; margin-top: 20px; padding-top: 20px; border-top: 2px dashed #ddd;">
                <h3 style="color: #c84a31; font-size: 18px; margin: 0 0 10px 0;">Products</h3>
                <p style="color: #666; font-size: 13px; margin: 0 0 10px 0;">Specific scanned products attached to this recipe, for accurate allergen checking:</p>
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($attached_products as $product): ?>
                    <li><?php echo esc_html($product->product_name); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php $featured_image_url = get_the_post_thumbnail_url($post_id, 'large'); ?>
            <?php if ($featured_image_url): ?>
            <div style="grid-column: 1 / -1; margin-top: 20px; padding-top: 20px; border-top: 2px dashed #ddd;">
                <h3 style="color: #c84a31; font-size: 18px; margin: 0 0 10px 0;">Original Image</h3>
                <img src="<?php echo esc_url($featured_image_url); ?>" alt="Original recipe image" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;" />
            </div>
            <?php endif; ?>
            
        </div>
        
    </div>
    
    <?php endwhile; wp_reset_postdata(); ?>
    
    <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd;">
        <a href="<?php echo esc_url($back_url); ?>" style="display: inline-block; padding: 12px 30px; background: #c84a31; color: white; text-decoration: none; border-radius: 4px; font-size: 16px;">
            ← Back to Recipe Manager
        </a>
    </div>
    
</div>

<?php get_footer(); ?>