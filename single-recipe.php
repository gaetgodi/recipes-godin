<?php
/**
 * Template Name: Recipe Print Book
 * 
 * Compact print template with recipe IDs and page-break control
 */

// Get selected recipes
$recipe_ids = get_transient('recipe_print_' . get_current_user_id());

if (empty($recipe_ids)) {
    wp_redirect(home_url('/recipe-manager/'));
    exit;
}

get_header();
?>

<style>
    /* Hide Divi header/footer/navigation for print */
    #main-header,
    #et-top-navigation,
    #et-secondary-nav,
    #main-footer,
    .et_pb_section,
    #et_builder_outer_content {
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
        }
        
        .recipe-item {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .recipe-section {
            page-break-inside: avoid;
            break-inside: avoid;
        }
    }
    
    @page {
        margin: 15mm;
        size: letter;
    }
</style>

<div class="no-print">
    <button onclick="window.print()">🖨️ Print Collection</button>
    <button onclick="window.location.href='<?php echo home_url('/recipe-manager/'); ?>'" style="background: #666;">← Back to Manager</button>
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
</div>

<?php endwhile; wp_reset_postdata(); ?>

<div class="footer">
    <p>© <?php echo date('Y'); ?> <?php echo esc_html(wp_get_current_user()->display_name); ?> | Generated from recipes.godin.com</p>
</div>

<script>
    // Auto-print after brief delay (allows page to fully render)
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
</script>

<?php get_footer(); ?>