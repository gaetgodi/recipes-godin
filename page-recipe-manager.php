<?php
/**
 * Template Name: Recipe Manager
 *
 * Complete recipe management interface with filtering, bulk actions, and printing
 *
 * @version 2.2.0
 * @changelog
 *   2.2.0 - Split category filtering into two independent groups: Food Categories
 *            and Author. OR within each group, AND between groups. Search term
 *            and both filter groups now persist across navigation to view/edit
 *            a recipe and back, via URL parameters (food_cat, author_cat, s).
 *   2.1.2 - Admins can now use "Copy to My Recipes" on any collection, not just
 *            view-only viewers. Added title tooltips to Copy and Copy to My Recipes buttons.
 *   2.1.1 - Share button now visible to all logged-in users viewing their own collection,
 *            not just users with edit_posts capability. Fixes subscriber sharing.
 *   2.1.0 - Share recipient list now includes authors who are in YOUR viewers list,
 *            not just authors whose viewers list you are on. Bidirectional sharing.
 *   2.0.0 - Added data-label attributes to all <td> elements for mobile card layout.
 *   1.0.0 - Initial release.
 */

get_header();

// Get current user
$current_user = wp_get_current_user();

// Include action handlers
require_once(get_stylesheet_directory() . '/recipe-manager-actions.php');
?>

<div class="recipe-manager">
<div class="page-header-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="margin: 0;"><?php echo esc_html($current_user->display_name); ?>'s Recipe Manager</h1>
    <a href="<?php echo wp_logout_url(home_url('/login/')); ?>" 
       style="background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px; font-weight: 600;">
        🚪 Log Out
    </a>
</div>
    
    <?php
    // Include permission functions
    require_once(get_stylesheet_directory() . '/collection-permissions.php');
    
    // Get selected collection (default to own collection if Author)
    $current_user_id = get_current_user_id();
    $selected_collection = isset($_GET['collection']) ? intval($_GET['collection']) : $current_user_id;
    
    // Get accessible collections
    $accessible_collections = get_accessible_collections($current_user_id);
    
    // Verify user can access selected collection
    $can_access_selected = false;
    foreach ($accessible_collections as $coll) {
        if ($coll['owner_id'] == $selected_collection) {
            $can_access_selected = true;
            $current_collection = $coll;
            break;
        }
    }
    
    // If can't access, default to first available or own
    if (!$can_access_selected && !empty($accessible_collections)) {
        $current_collection = $accessible_collections[0];
        $selected_collection = $current_collection['owner_id'];
    }
    
    // Determine user's role in this collection
    $is_owner = ($selected_collection == $current_user_id && user_can($current_user_id, 'publish_posts'));
    $is_admin = current_user_can('administrator');
    $can_manage = $is_admin || (isset($current_collection) ? $current_collection['can_manage'] : $is_owner);
    $can_view_only = isset($current_collection) ? (!$current_collection['can_manage'] && $current_collection['can_view']) : false;
    
    // --- Persisted filter/search state, used to build every outbound link below ---
    $food_cat_ids = !empty($_GET['food_cat']) ? array_map('intval', explode(',', $_GET['food_cat'])) : array();
    $author_cat_ids = !empty($_GET['author_cat']) ? array_map('intval', explode(',', $_GET['author_cat'])) : array();
    $search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    
    /**
     * Build a query-string fragment carrying the current filter/search state,
     * for appending to any link that should preserve it (view, edit, etc.)
     * Returns a string starting with '&' or '' if there's nothing to carry.
     */
    function recipe_manager_state_query_args($food_cat_ids, $author_cat_ids, $search_term, $collection_id, $current_user_id) {
        $parts = array();
        if (!empty($food_cat_ids)) {
            $parts[] = 'food_cat=' . implode(',', $food_cat_ids);
        }
        if (!empty($author_cat_ids)) {
            $parts[] = 'author_cat=' . implode(',', $author_cat_ids);
        }
        if (!empty($search_term)) {
            $parts[] = 's=' . rawurlencode($search_term);
        }
        if ($collection_id != $current_user_id) {
            $parts[] = 'collection=' . intval($collection_id);
        }
        return empty($parts) ? '' : '&' . implode('&', $parts);
    }
    
    $state_query = recipe_manager_state_query_args($food_cat_ids, $author_cat_ids, $search_term, $selected_collection, $current_user_id);
    // Version without leading '&', for building a fresh '?...' URL from scratch
    $state_query_no_amp = ltrim($state_query, '&');
    ?>
    
    <!-- Copy Error Message -->
    <?php if (isset($copy_error)): ?>
    <div style="background: #fadbd8; padding: 15px; margin-bottom: 20px; border-radius: 6px; border: 2px solid #e74c3c;">
        <strong>⚠️ Copy Failed:</strong> <?php echo esc_html($copy_error); ?>
    </div>
    <?php endif; ?>
    
    <!-- Collection Selector -->
    <?php if (count($accessible_collections) > 1): ?>
    <div style="background: #f0f8ff; padding: 15px; margin-bottom: 20px; border-radius: 6px; border: 2px solid #2271b1;">
        <label for="collection-selector" style="font-weight: 600; margin-right: 10px;">
            📚 Viewing Collection:
        </label>
        <select id="collection-selector" onchange="switchCollection()" style="padding: 8px 15px; font-size: 15px; border: 2px solid #2271b1; border-radius: 4px; min-width: 250px;">
            <?php foreach ($accessible_collections as $collection): ?>
                <option value="<?php echo $collection['owner_id']; ?>" 
                        <?php selected($selected_collection, $collection['owner_id']); ?>>
                    <?php 
                    echo esc_html($collection['owner_name']) . "'s Recipes";
                    if ($collection['owner_id'] == $current_user_id) {
                        echo " (Yours)";
                    } else {
                        $owner_user = get_userdata($collection['owner_id']);
                        if ($owner_user && !empty($owner_user->roles)) {
                            $role = ucfirst($owner_user->roles[0]);
                            echo " [{$role}]";
                        }
                    }
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <script>
        function switchCollection() {
            var collectionId = document.getElementById('collection-selector').value;
            window.location.href = '<?php echo home_url('/recipe-manager/'); ?>?collection=' + collectionId;
        }
        </script>
    </div>
    <?php endif; ?>
    
    <?php
    if (isset($_GET['saved'])) {
        echo '<div style="background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb; color: #155724; border-radius: 4px;">✅ Recipe saved successfully!</div>';
    }
    if (isset($_GET['deleted'])) {
        echo '<div style="background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb; color: #155724; border-radius: 4px;">✅ Recipe(s) deleted successfully!</div>';
    }
    if (isset($_GET['shared'])) {
        $count = intval($_GET['shared']);
        $recipeWord = $count === 1 ? 'recipe' : 'recipes';
        echo '<div style="background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb; color: #155724; border-radius: 4px;">✅ Shared ' . $count . ' ' . $recipeWord . ' successfully!</div>';
    }
    if (isset($_GET['bulk_copied'])) {
        $count = intval($_GET['bulk_copied']);
        $skipped = isset($_GET['bulk_skipped']) ? intval($_GET['bulk_skipped']) : 0;
        $recipeWord = $count === 1 ? 'recipe' : 'recipes';
        $message = '✅ Copied ' . $count . ' ' . $recipeWord . ' to your collection!';
        if ($skipped > 0) {
            $skipWord = $skipped === 1 ? 'recipe was' : 'recipes were';
            $message .= ' (' . $skipped . ' ' . $skipWord . ' skipped - already in your collection)';
        }
        echo '<div style="background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb; color: #155724; border-radius: 4px;">' . $message . '</div>';
    }
    ?>
    
    <!-- Filter Section -->
    <div class="filter-section" style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
        <div class="filter-inner-row" style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px; flex-wrap: wrap;">
            <label style="font-weight: 600;">Filter by Food Category:</label>
            
            <div style="position: relative;">
                <button type="button" id="foodCategoryFilterBtn" onclick="toggleDropdown('foodCategoryDropdown')" class="action-btn" style="background: #6c757d; color: white; min-width: 200px; text-align: left; position: relative;">
                    <span id="foodFilterBtnText"><?php echo !empty($food_cat_ids) ? count($food_cat_ids) . ' selected' : 'Select Categories'; ?></span>
                    <span style="float: right;">▼</span>
                </button>
                
                <div id="foodCategoryDropdown" style="display: none; position: absolute; top: 100%; left: 0; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); min-width: 250px; max-height: 400px; overflow-y: auto; z-index: 1000; margin-top: 5px;">
                    <div style="padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <strong>Select one or more:</strong>
                        <button type="button" onclick="clearFilterGroup('food')" class="action-btn" style="background: #dc3545; color: white; padding: 4px 12px; font-size: 12px;">
                            Clear
                        </button>
                    </div>
                    <?php
                    $food_categories = get_user_categories_with_counts($selected_collection, 'food');
                    foreach ($food_categories as $cat) {
                        if ($cat->recipe_count == 0) continue;
                        $checked = in_array($cat->cat_id, $food_cat_ids) ? 'checked' : '';
                        echo '<label style="display: block; padding: 8px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0;" onmouseover="this.style.background=\'#f8f9fa\'" onmouseout="this.style.background=\'white\'">';
                        echo '<input type="checkbox" name="food_cat_filters[]" value="' . $cat->cat_id . '" ' . $checked . ' onchange="applyFiltersInstantly()" style="margin-right: 8px;">';
                        echo esc_html($cat->cat_name) . ' <span style="color: #6c757d;">(' . $cat->recipe_count . ')</span>';
                        echo '</label>';
                    }
                    ?>
                </div>
            </div>
            
            <label style="font-weight: 600;">Filter by Author:</label>
            
            <div style="position: relative;">
                <button type="button" id="authorCategoryFilterBtn" onclick="toggleDropdown('authorCategoryDropdown')" class="action-btn" style="background: #6c757d; color: white; min-width: 200px; text-align: left; position: relative;">
                    <span id="authorFilterBtnText"><?php echo !empty($author_cat_ids) ? count($author_cat_ids) . ' selected' : 'Select Authors'; ?></span>
                    <span style="float: right;">▼</span>
                </button>
                
                <div id="authorCategoryDropdown" style="display: none; position: absolute; top: 100%; left: 0; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); min-width: 250px; max-height: 400px; overflow-y: auto; z-index: 1000; margin-top: 5px;">
                    <div style="padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <strong>Select one or more:</strong>
                        <button type="button" onclick="clearFilterGroup('author')" class="action-btn" style="background: #dc3545; color: white; padding: 4px 12px; font-size: 12px;">
                            Clear
                        </button>
                    </div>
                    <?php
                    $author_categories = get_user_categories_with_counts($selected_collection, 'author');
                    foreach ($author_categories as $cat) {
                        if ($cat->recipe_count == 0) continue;
                        $checked = in_array($cat->cat_id, $author_cat_ids) ? 'checked' : '';
                        echo '<label style="display: block; padding: 8px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0;" onmouseover="this.style.background=\'#f8f9fa\'" onmouseout="this.style.background=\'white\'">';
                        echo '<input type="checkbox" name="author_cat_filters[]" value="' . $cat->cat_id . '" ' . $checked . ' onchange="applyFiltersInstantly()" style="margin-right: 8px;">';
                        echo esc_html($cat->cat_name) . ' <span style="color: #6c757d;">(' . $cat->recipe_count . ')</span>';
                        echo '</label>';
                    }
                    ?>
                </div>
            </div>
            
            <?php if (current_user_can('edit_posts') && $can_manage): ?>
            <?php 
            $category_manager_url = home_url('/category-manager/');
            if ($selected_collection != get_current_user_id()) {
                $category_manager_url .= '?collection=' . $selected_collection;
            }
            ?>
            <button onclick="window.location.href='<?php echo esc_url($category_manager_url); ?>'" class="action-btn" style="background: #2271b1; color: white; margin-left: auto;">
                📁 Manage Categories
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Active Filter Pills -->
        <?php if (!empty($food_cat_ids) || !empty($author_cat_ids)): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">
            <span style="font-size: 14px; color: #666; padding: 4px 0;">Active filters:</span>
            <?php 
            foreach ($food_categories as $cat) {
                if (in_array($cat->cat_id, $food_cat_ids)) {
                    $remaining = array_diff($food_cat_ids, array($cat->cat_id));
                    $remove_url = home_url('/recipe-manager/?' . ltrim(recipe_manager_state_query_args($remaining, $author_cat_ids, $search_term, $selected_collection, $current_user_id), '&'));
                    echo '<span style="display: inline-flex; align-items: center; background: #007bff; color: white; padding: 4px 10px; border-radius: 12px; font-size: 13px; gap: 6px;">';
                    echo esc_html($cat->cat_name);
                    echo '<a href="' . esc_url($remove_url) . '" style="color: white; text-decoration: none; font-weight: bold; font-size: 16px;" title="Remove filter">×</a>';
                    echo '</span>';
                }
            }
            foreach ($author_categories as $cat) {
                if (in_array($cat->cat_id, $author_cat_ids)) {
                    $remaining = array_diff($author_cat_ids, array($cat->cat_id));
                    $remove_url = home_url('/recipe-manager/?' . ltrim(recipe_manager_state_query_args($food_cat_ids, $remaining, $search_term, $selected_collection, $current_user_id), '&'));
                    echo '<span style="display: inline-flex; align-items: center; background: #28a745; color: white; padding: 4px 10px; border-radius: 12px; font-size: 13px; gap: 6px;">';
                    echo esc_html($cat->cat_name);
                    echo '<a href="' . esc_url($remove_url) . '" style="color: white; text-decoration: none; font-weight: bold; font-size: 16px;" title="Remove filter">×</a>';
                    echo '</span>';
                }
            }
            ?>
        </div>
        <?php endif; ?>
        
        <?php if ($is_owner): ?>
        <button onclick="window.location.href='<?php echo home_url('/permissions-manager/'); ?>'" class="action-btn" style="background: #7c3aed; color: white;">
            🔐 Manage Permissions
        </button>
        <?php endif; ?>
        
        <?php if (current_user_can('edit_posts') && $can_manage): ?>
        <button onclick="window.location.href='<?php echo home_url('/recipe-editor/'); ?>'" class="action-btn" style="background: #00a32a; color: white;">
            + Add New Recipe
        </button>
        <?php endif; ?>
    </div>
    
    <form method="post" id="recipeManagerForm">
        <?php
        $args = array(
            'post_type' => 'recipe',
            'posts_per_page' => -1,
            'author' => $selected_collection,
            'orderby' => 'title',
            'order' => 'ASC',
        );
        
        global $wpdb;
        
        $food_recipe_ids = null;
        if (!empty($food_cat_ids)) {
            $placeholders = implode(',', array_fill(0, count($food_cat_ids), '%d'));
            $food_recipe_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT recipe_id
                FROM {$wpdb->prefix}recipe_category_relationships
                WHERE cat_id IN ({$placeholders})
            ", $food_cat_ids));
        }
        
        $author_recipe_ids = null;
        if (!empty($author_cat_ids)) {
            $placeholders = implode(',', array_fill(0, count($author_cat_ids), '%d'));
            $author_recipe_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT recipe_id
                FROM {$wpdb->prefix}recipe_category_relationships
                WHERE cat_id IN ({$placeholders})
            ", $author_cat_ids));
        }
        
        if ($food_recipe_ids !== null && $author_recipe_ids !== null) {
            $matching_recipe_ids = array_intersect($food_recipe_ids, $author_recipe_ids);
        } elseif ($food_recipe_ids !== null) {
            $matching_recipe_ids = $food_recipe_ids;
        } elseif ($author_recipe_ids !== null) {
            $matching_recipe_ids = $author_recipe_ids;
        } else {
            $matching_recipe_ids = null;
        }
        
        if ($matching_recipe_ids !== null) {
            if (!empty($matching_recipe_ids)) {
                $args['post__in'] = array_values($matching_recipe_ids);
                $args['orderby'] = 'post__in';
            } else {
                $args['post__in'] = array(0);
            }
        }
        
        $recipes = new WP_Query($args);
        ?>
        
        <div class="recipe-count">
            Showing <strong><?php echo $recipes->post_count; ?></strong> recipes | 
            Selected: <span class="selected-count" id="selectedCount">0</span>
        </div>
        
        <!-- Search Box -->
        <div class="recipe-search-box" style="margin: 15px 0; padding: 15px; background: #fff; border: 2px solid #c84a31; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
    <label style="font-weight: 600; font-size: 15px;">🔍 Search Recipes:</label>
            <input 
                type="text" 
                id="recipeSearch" 
                placeholder="Type to search recipe titles..." 
                value="<?php echo esc_attr($search_term); ?>"
                oninput="searchRecipes()"
                style="flex: 1; padding: 10px 15px; font-size: 15px; border: 2px solid #ddd; border-radius: 4px;"
            >
            <button 
                type="button" 
                onclick="clearSearch()" 
                class="action-btn" 
                style="background: #6c757d; color: white;">
                Clear
            </button>
        </div>
        
        <!-- Bulk Actions - Top -->
        <div class="bulk-actions" style="margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <label><strong>With Selected:</strong></label>
            
            <button type="submit" name="bulk_action" value="view" class="action-btn" style="background: #0073aa; color: white;" id="viewBtnTop" disabled>
                👁️ View Selected
            </button>
            
            <button type="submit" name="bulk_action" value="print" class="action-btn btn-print" id="printBtnTop" disabled>
                🖨️ Print Book
            </button>
            
            <?php if (($can_view_only || $is_admin) && $selected_collection !== get_current_user_id()): ?>
            <button type="submit" name="bulk_action" value="copy_to_my_recipes" class="action-btn" style="background: #3498db; color: white;" id="copyToMyBtnTop" disabled
                    title="This makes a copy in my collection">
                📋 Copy to My Recipes
            </button>
            <?php endif; ?>
            
            <?php if (current_user_can('edit_posts') && $can_manage): ?>
            <button type="submit" name="bulk_action" value="copy" class="action-btn btn-copy" id="copyBtnTop" disabled 
                    onclick="return confirm('Copy the first selected recipe?')"
                    title="This makes another copy here">
                📋 Copy
            </button>
            
            <button type="submit" name="bulk_action" value="delete" class="action-btn btn-delete" id="deleteBtnTop" disabled
                    onclick="return confirm('Are you sure you want to delete the selected recipes? This cannot be undone.')">
                🗑️ Delete
            </button>
            <?php endif; ?>

            <?php if (is_user_logged_in() && $selected_collection == $current_user_id): ?>
            <button type="button" class="action-btn" style="background: #7c3aed; color: white;" id="shareBtnTop" disabled
                    onclick="openShareDialog()">
                🔗 Share
            </button>
            <?php endif; ?>
        </div>
        
        <table class="recipe-manager-table">
            <thead>
                <tr>
                <th>
    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
</th>
<th>ID</th>
<th>Recipe Title</th>
<th>Category</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ($recipes->have_posts()):
                    while ($recipes->have_posts()): 
                        $recipes->the_post();
                        $post_id = get_the_ID();
                        
                        $recipe_id = get_post_meta($post_id, '_recipe_id', true);
                        if (empty($recipe_id)) {
                            $recipe_id = 'R' . str_pad($post_id, 4, '0', STR_PAD_LEFT);
                            update_post_meta($post_id, '_recipe_id', $recipe_id);
                        }
                        
                        $recipe_cats = get_recipe_categories($post_id);
                        if (!empty($recipe_cats)) {
                            $category_names = array_map(function($cat) { return $cat->cat_name; }, $recipe_cats);
                            $category_display = implode(', ', $category_names);
                        } else {
                            $category_display = 'Uncategorized';
                        }
                ?>
                <tr>
                    <td data-label="">
                        <input type="checkbox" name="selected_recipes[]" value="<?php echo $post_id; ?>" class="recipe-checkbox" onchange="updateSelectedCount()">
                    </td>
                    <td data-label="ID">
                        <span class="recipe-id"><?php echo $recipe_id; ?></span>
                    </td>
                    <td data-label="Title">
                        <?php 
                        $view_url = home_url('/recipe-view-page/?ids=' . $post_id . $state_query);
                        ?>
                        <a href="<?php echo esc_url($view_url); ?>" class="recipe-title-link">
                            <?php the_title(); ?>
                        </a>
                    </td>
                    <td data-label="Category"><?php echo esc_html($category_display); ?></td>
                </tr>
                <?php 
                    endwhile; 
                else:
                ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 40px;">
                        No recipes found. <a href="<?php echo home_url('/recipe-editor/'); ?>">Add your first recipe!</a>
                    </td>
                </tr>
                <?php endif; wp_reset_postdata(); ?>
            </tbody>
        </table>
        
        <!-- Bulk Actions - Bottom -->
        <div class="bulk-actions">
            <label><strong>With Selected:</strong></label>
            
            <button type="submit" name="bulk_action" value="view" class="action-btn" style="background: #0073aa; color: white;" id="viewBtn" disabled>
                👁️ View Selected
            </button>
            
            <button type="submit" name="bulk_action" value="print" class="action-btn btn-print" id="printBtn" disabled>
                🖨️ Print Book
            </button>
            
            <?php if (($can_view_only || $is_admin) && $selected_collection !== get_current_user_id()): ?>
            <button type="submit" name="bulk_action" value="copy_to_my_recipes" class="action-btn" style="background: #3498db; color: white;" id="copyToMyBtn" disabled
                    title="This makes a copy in my collection">
                📋 Copy to My Recipes
            </button>
            <?php endif; ?>
            
            <?php if (current_user_can('edit_posts') && $can_manage): ?>
            <button type="submit" name="bulk_action" value="copy" class="action-btn btn-copy" id="copyBtn" disabled 
                    onclick="return confirm('Copy the first selected recipe?')"
                    title="This makes another copy here">
                📋 Copy
            </button>
            
            <button type="submit" name="bulk_action" value="delete" class="action-btn btn-delete" id="deleteBtn" disabled
                    onclick="return confirm('Are you sure you want to delete the selected recipes? This cannot be undone.')">
                🗑️ Delete
            </button>
            <?php endif; ?>

            <?php if (is_user_logged_in() && $selected_collection == $current_user_id): ?>
            <button type="button" class="action-btn" style="background: #7c3aed; color: white;" id="shareBtn" disabled
                    onclick="openShareDialog()">
                🔗 Share
            </button>
            <?php endif; ?>
            
            <span style="margin-left: auto; font-size: 13px; color: #666;">
                <?php if (current_user_can('edit_posts')): ?>
                Tip: Select recipes by checking boxes, then use buttons above
                <?php else: ?>
                Tip: Select recipes and click Print Book to create a collection
                <?php endif; ?>
            </span>
        </div>
        
        <!-- Hidden fields so bulk actions (view, print, delete, copy) preserve filter/search state on redirect -->
        <input type="hidden" name="state_food_cat" value="<?php echo esc_attr(implode(',', $food_cat_ids)); ?>">
        <input type="hidden" name="state_author_cat" value="<?php echo esc_attr(implode(',', $author_cat_ids)); ?>">
        <input type="hidden" name="state_search" value="<?php echo esc_attr($search_term); ?>">
    </form>
</div>

<!-- Load external JavaScript -->
<script src="<?php echo get_stylesheet_directory_uri(); ?>/recipe-manager-scripts.js"></script>

<script>
function openShareDialog() {
    const checked = document.querySelectorAll('.recipe-checkbox:checked');
    const recipeIds = Array.from(checked).map(cb => cb.value);
    
    if (recipeIds.length === 0) {
        alert('Please select recipes to share');
        return;
    }
    
    <?php
    require_once(get_stylesheet_directory() . '/collection-permissions.php');
    $current_user_id = get_current_user_id();
    $is_admin = current_user_can('administrator');
    
    $all_users = get_users();
    $recipient_list = array();
    
    foreach ($all_users as $user) {
        if ($user->ID == $current_user_id) continue;
        
        $user_roles = (array) $user->roles;
        $is_subscriber = in_array('subscriber', $user_roles);
        $is_author = in_array('author', $user_roles) || in_array('editor', $user_roles);
        
        $can_share_with = false;
        $role_display = '';
        
        if ($is_admin) {
            $can_share_with = true;
        } elseif ($is_subscriber) {
            $can_share_with = true;
            $role_display = ' [Subscriber]';
        } elseif ($is_author) {
            if (user_can_view_collection($current_user_id, $user->ID) ||
                user_can_view_collection($user->ID, $current_user_id)) {
                $can_share_with = true;
                $role_display = ' [Author]';
            }
        }
        
        if ($can_share_with) {
            $recipient_list[] = array(
                'id' => $user->ID,
                'name' => $user->display_name . $role_display
            );
        }
    }
    ?>
    
    const recipients = <?php echo json_encode($recipient_list); ?>;
    
    if (recipients.length === 0) {
        alert('No other recipe collections available to share with.');
        return;
    }
    
    let options = '';
    recipients.forEach(recipient => {
        options += `<option value="${recipient.id}">${recipient.name}</option>`;
    });
    
    const recipeWord = recipeIds.length === 1 ? 'recipe' : 'recipes';
    const html = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;" id="shareDialog">
            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%;">
                <h2 style="margin-top: 0;">Share ${recipeIds.length} ${recipeWord}</h2>
                <p>Select a collection to share with:</p>
                <select id="shareRecipient" style="width: 100%; padding: 10px; font-size: 15px; margin-bottom: 20px; border: 2px solid #ddd; border-radius: 4px;">
                    ${options}
                </select>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button onclick="closeShareDialog()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button onclick="executeShare()" style="padding: 10px 20px; background: #7c3aed; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Share Recipes</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', html);
}

function closeShareDialog() {
    const dialog = document.getElementById('shareDialog');
    if (dialog) dialog.remove();
}

let shareInProgress = false;

function executeShare() {
    if (shareInProgress) {
        return;
    }
    shareInProgress = true;
    
    const recipientId = document.getElementById('shareRecipient').value;
    
    const dialog = document.getElementById('shareDialog');
    if (dialog) {
        dialog.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; text-align: center;">
                <h2 style="margin-top: 0;">⏳ Sharing in progress...</h2>
                <p style="color: #666;">This may take a moment for large collections. Please don't close this window or click again.</p>
            </div>
        `;
    }
    
    const form = document.getElementById('recipeManagerForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'share_to_user';
    input.value = recipientId;
    form.appendChild(input);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'bulk_action';
    actionInput.value = 'share';
    form.appendChild(actionInput);
    
    form.submit();
}

function toggleDropdown(id) {
    const dropdown = document.getElementById(id);
    const isOpen = dropdown.style.display === 'block';
    document.getElementById('foodCategoryDropdown').style.display = 'none';
    document.getElementById('authorCategoryDropdown').style.display = 'none';
    dropdown.style.display = isOpen ? 'none' : 'block';
}

document.addEventListener('click', function(e) {
    const foodBtn = document.getElementById('foodCategoryFilterBtn');
    const authorBtn = document.getElementById('authorCategoryFilterBtn');
    const foodDropdown = document.getElementById('foodCategoryDropdown');
    const authorDropdown = document.getElementById('authorCategoryDropdown');
    
    if (!foodBtn.contains(e.target) && !foodDropdown.contains(e.target)) {
        foodDropdown.style.display = 'none';
    }
    if (!authorBtn.contains(e.target) && !authorDropdown.contains(e.target)) {
        authorDropdown.style.display = 'none';
    }
});

function getCurrentSelections() {
    const foodIds = Array.from(document.querySelectorAll('input[name="food_cat_filters[]"]:checked')).map(cb => cb.value);
    const authorIds = Array.from(document.querySelectorAll('input[name="author_cat_filters[]"]:checked')).map(cb => cb.value);
    return { foodIds, authorIds };
}

function applyFiltersInstantly() {
    const { foodIds, authorIds } = getCurrentSelections();
    const params = new URLSearchParams();
    if (foodIds.length > 0) params.set('food_cat', foodIds.join(','));
    if (authorIds.length > 0) params.set('author_cat', authorIds.join(','));
    
    const currentSearch = document.getElementById('recipeSearch').value;
    if (currentSearch) params.set('s', currentSearch);
    
    <?php if ($selected_collection != $current_user_id): ?>
    params.set('collection', '<?php echo intval($selected_collection); ?>');
    <?php endif; ?>
    
    window.location.href = '<?php echo home_url('/recipe-manager/'); ?>?' + params.toString();
}

function clearFilterGroup(group) {
    const selector = group === 'food' ? 'input[name="food_cat_filters[]"]' : 'input[name="author_cat_filters[]"]';
    document.querySelectorAll(selector).forEach(cb => cb.checked = false);
    applyFiltersInstantly();
}

function searchRecipes() {
    const searchTerm = document.getElementById('recipeSearch').value.toLowerCase();
    const rows = document.querySelectorAll('.recipe-manager-table tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const titleCell = row.querySelector('.recipe-title-link');
        if (!titleCell) return;
        
        const title = titleCell.textContent.toLowerCase();
        const checkbox = row.querySelector('.recipe-checkbox');
        
        if (title.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
            if (checkbox) checkbox.checked = false;
        }
    });
    
    document.querySelector('.recipe-count strong').textContent = visibleCount;
    updateSelectedCount();
    
    // Update the recipe title links to carry the current search term too
    document.querySelectorAll('.recipe-title-link').forEach(link => {
        const url = new URL(link.href);
        if (searchTerm) {
            url.searchParams.set('s', searchTerm);
        } else {
            url.searchParams.delete('s');
        }
        link.href = url.toString();
    });
}

function clearSearch() {
    document.getElementById('recipeSearch').value = '';
    searchRecipes();
}

updateSelectedCount();
</script>

<?php get_footer(); ?>