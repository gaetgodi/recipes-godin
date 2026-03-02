<?php
/**
 * Template Name: Recipe Print Book
 * 
 * Compact print template with recipe IDs and page-break control
 */

// Get selected recipes - try URL first, then transient
$recipe_ids = array();

if (!empty($_GET['ids'])) {
    // Get from URL parameter
    $recipe_ids = array_map('intval', explode(',', $_GET['ids']));
} else {
    // Fallback to transient
    $recipe_ids = get_transient('recipe_print_' . get_current_user_id());
}

if (empty($recipe_ids)) {
    // Build redirect URL with category if present
    $redirect_url = home_url('/recipe-manager/');
    if (!empty($_GET['recipe_cat'])) {
        $redirect_url .= '?recipe_cat=' . intval($_GET['recipe_cat']);
    }
    wp_redirect($redirect_url);
    exit;
}

// Build back URL with category filter
$back_url = home_url('/recipe-manager/');
if (!empty($_GET['recipe_cat'])) {
    $back_url .= '?recipe_cat=' . intval($_GET['recipe_cat']);
}

get_header();
?>

<style>
    /* Hide Divi header/footer/navigation and ticker for print */
    #main-header,
    #et-top-navigation,
    #et-secondary-nav,
    #main-footer,
    .et_pb_section,
    #et_builder_outer_content,
    .recipe-ticker-wrapper {
        display: none !important;
    }
    
    #page-container {
        padding: 0 !important;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: Arial, sans-serif;
        font-size: 9pt;
        line-height: 1.3;
        padding: 20px;
    }
    
    .print-header {
        text-align: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #c84a31;
    }
    
    .print-header h1 {
        font-size: 20pt;
        color: #c84a31;
        margin-bottom: 5px;
    }
    
    .print-header p {
        font-size: 8pt;
        color: #666;
    }
    
    .recipe-item {
        page-break-inside: avoid;
        margin-bottom: 15px;
        border: 1px solid #ddd;
        padding: 10px;
        background: #fafafa;
    }
    
    .recipe-header {
        background: #c84a31;
        color: white;
        padding: 8px 10px;
        margin: -10px -10px 10px -10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .recipe-id {
        font-family: 'Courier New', monospace;
        font-weight: bold;
        font-size: 8pt;
        background: white;
        color: #c84a31;
        padding: 2px 6px;
        border-radius: 3px;
    }
    
    .recipe-title {
        font-size: 11pt;
        font-weight: bold;
        flex: 1;
        margin: 0 10px;
    }
    
    .recipe-category {
        font-size: 8pt;
        font-style: italic;
    }
    
    .recipe-content {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .recipe-section {
        break-inside: avoid;
    }
    
    .recipe-section h3 {
        font-size: 9pt;
        color: #c84a31;
        margin-bottom: 5px;
        padding-bottom: 3px;
        border-bottom: 1px solid #c84a31;
    }
    
    .recipe-section ul,
    .recipe-section ol {
        margin-left: 20px;
    }
    
    .recipe-section ul {
        list-style-type: disc;
    }
    
    .recipe-section ol {
        list-style-type: decimal;
    }
    
    .recipe-section li {
        margin-bottom: 2px;
        font-size: 8pt;
        display: list-item;
    }
    
    .recipe-notes {
        grid-column: 1 / -1;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #ddd;
        font-size: 8pt;
        font-style: italic;
    }
    
    .no-print {
        text-align: center;
        margin-bottom: 20px;
        padding: 15px;
        background: #f9f9f9;
        border: 1px solid #ddd;
    }
    
    .no-print button {
        background: #c84a31;
        color: white;
        border: none;
        padding: 10px 25px;
        font-size: 12pt;
        cursor: pointer;
        margin: 0 5px;
        border-radius: 4px;
    }
    
    .no-print button:hover {
        background: #a63820;
    }
    
    .footer {
        margin-top: 30px;
        padding-top: 15px;
        border-top: 1px solid #ddd;
        text-align: center;
        font-size: 7pt;
        color: #666;
    }
    
    /* Print styles */
    @media print {
        .no-print {
            display: none;
        }
        
        body {
            padding: 10mm;
            font-size: 9pt;
            counter-reset: page;
        }
        
        .recipe-item {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .recipe-section {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Page numbers in bottom right corner */
        @bottom-right {
            content: "Page " counter(page);
            font-size: 8pt;
            color: #666;
        }
    }
    
    @page {
        margin: 15mm;
        size: letter;
        
        /* Page number in footer */
        @bottom-right {
            content: "Page " counter(page);
            font-size: 8pt;
            color: #666;
            font-family: Arial, sans-serif;
        }
    }
</style>

<!-- Sticky Action Bar -->
<div class="no-print" style="position: sticky; top: 0; z-index: 1000; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 15px 20px; border-bottom: 3px solid #c84a31;">
    <div style="max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
        <div>
            <h2 style="margin: 0; font-size: 20px; color: #333;">
                <?php echo esc_html(wp_get_current_user()->display_name); ?>'s Recipe Collection
            </h2>
            <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">
                <?php echo count($recipe_ids); ?> Recipes | <?php echo date('F j, Y'); ?>
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button onclick="window.print()" style="background: #c84a31; color: white; border: none; padding: 10px 25px; font-size: 15px; border-radius: 6px; cursor: pointer; font-weight: bold;">
                🖨️ Print Book
            </button>
            <button onclick="window.location.href='<?php echo esc_url($back_url); ?>'" style="background: #666; color: white; border: none; padding: 10px 25px; font-size: 15px; border-radius: 6px; cursor: pointer;">
                ← Back
            </button>
        </div>
    </div>
</div>

<div class="print-header">
    <h1><?php echo esc_html(wp_get_current_user()->display_name); ?>'s Recipe Collection</h1>
    <p>Generated: <?php echo date('F j, Y'); ?> | <?php echo count($recipe_ids); ?> Recipes</p>
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
    
    // Get stored recipe ID
    $recipe_id = get_post_meta($post_id, '_recipe_id', true);
    if (empty($recipe_id)) {
        $recipe_id = 'R' . str_pad($post_id, 4, '0', STR_PAD_LEFT);
    }
    
    $ingredients = get_post_meta($post_id, '_recipe_ingredients', true);
    $method = get_post_meta($post_id, '_recipe_method', true);
    $notes = get_post_meta($post_id, '_recipe_notes', true);
    
    $terms = get_the_terms($post_id, 'recipe_category');
    $category = ($terms && !is_wp_error($terms)) ? $terms[0]->name : 'Uncategorized';
?>

<div class="recipe-item">
    <div class="recipe-header">
        <span class="recipe-id"><?php echo esc_html($recipe_id); ?></span>
        <span class="recipe-title"><?php the_title(); ?></span>
        <span class="recipe-category"><?php echo esc_html($category); ?></span>
    </div>
    
    <div class="recipe-content">
        <div class="recipe-section">
            <h3>Ingredients</h3>
            <?php echo wp_kses_post($ingredients); ?>
        </div>
        
        <div class="recipe-section">
            <h3>Method</h3>
            <?php echo wp_kses_post($method); ?>
        </div>
        
        <?php if (!empty($notes)): ?>
        <div class="recipe-notes">
            <strong>Notes:</strong> <?php echo wp_kses_post($notes); ?>
        </div>
        <?php endif; ?>
    </div>
    
    <?php 
    // Get recipe owner for copyright
    $recipe_author = get_userdata($post->post_author);
    ?>
    <div style="text-align: center; font-size: 7pt; color: #999; margin-top: 8px; padding-top: 5px; border-top: 1px solid #eee;">
        © <?php echo date('Y'); ?> <?php echo esc_html($recipe_author->display_name); ?> | recipes.godin.com | Last updated: <?php echo get_the_modified_date('F j, Y'); ?>
    </div>
</div>

<?php endwhile; wp_reset_postdata(); ?>

<div class="footer no-print">
    <p style="margin-bottom: 15px;">© <?php echo date('Y'); ?> | Generated from recipes.godin.com | Printed by: <?php echo esc_html(wp_get_current_user()->display_name); ?></p>
    <button onclick="window.print()" style="background: #c84a31; color: white; border: none; padding: 12px 30px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-right: 10px;">
        🖨️ Print Book
    </button>
    <button onclick="window.location.href='<?php echo esc_url($back_url); ?>'" style="background: #666; color: white; border: none; padding: 12px 30px; font-size: 16px; border-radius: 6px; cursor: pointer;">
        ← Back to Manager
    </button>
</div>

<?php get_footer(); ?>
