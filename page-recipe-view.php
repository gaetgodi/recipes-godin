<?php
/**
 * Template Name: Recipe View Page
 * 
 * Screen-optimized view for selected recipes
 */

// Get selected recipes - try URL first, then transient
$recipe_ids = array();

if (!empty($_GET['ids'])) {
    // Get from URL parameter
    $recipe_ids = array_map('intval', explode(',', $_GET['ids']));
} else {
    // Fallback to transient
    $recipe_ids = get_transient('recipe_view_' . get_current_user_id());
}

if (empty($recipe_ids)) {
    wp_redirect(home_url('/recipe-manager/'));
    exit;
}

get_header();

// Build back URL with category filter if present
$back_url = home_url('/recipe-manager/');
if (!empty($_GET['recipe_cat'])) {
    $back_url .= '?recipe_cat=' . intval($_GET['recipe_cat']);
}
?>

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
    // Get recipes
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
        
        $terms = get_the_terms($post_id, 'recipe_category');
        $category = ($terms && !is_wp_error($terms)) ? $terms[0]->name : 'Uncategorized';
    ?>
    
    <div style="background: white; border: 2px solid #c84a31; margin-bottom: 40px; border-radius: 8px; overflow: hidden; page-break-inside: avoid;">
        
        <div style="background: #c84a31; color: white; padding: 20px; display: flex; align-items: center; justify-content: space-between;">
            <span style="font-family: 'Courier New', monospace; font-weight: bold; font-size: 14px; background: white; color: #c84a31; padding: 4px 10px; border-radius: 4px;">
                <?php echo esc_html($recipe_id); ?>
            </span>
            <h2 style="flex: 1; margin: 0 20px; font-size: 24px; color: white;">
                <?php the_title(); ?>
            </h2>
            <span style="font-size: 14px; font-style: italic; margin-right: 15px;">
                <?php echo esc_html($category); ?>
            </span>
            <?php if (current_user_can('edit_posts')): 
                $edit_url = home_url('/recipe-editor/?id=' . $post_id);
            ?>
            <a href="<?php echo esc_url($edit_url); ?>" style="background: white; color: #c84a31; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 14px;">
                ✏️ Edit
            </a>
            <?php endif; ?>
        </div>
        
        <div style="padding: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            
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