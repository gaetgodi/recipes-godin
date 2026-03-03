<?php
/**
 * Template Name: Recipe Manager
 * 
 * Complete recipe management interface with filtering, bulk actions, and printing
 */

get_header();

// Get current user
$current_user = wp_get_current_user();

// Include action handlers
require_once(get_stylesheet_directory() . '/recipe-manager-actions.php');
?>

<style>
.recipe-manager {
    max-width: 1400px;
    margin: 40px auto;
    padding: 0 20px;
}

.recipe-manager h1 {
    color: #c84a31;
    font-size: 36px;
    margin-bottom: 30px;
}

.filter-section {
    background: #f9f9f9;
    padding: 20px;
    margin-bottom: 30px;
    border: 2px solid #c84a31;
    display: flex;
    gap: 20px;
    align-items: center;
}

.recipe-manager-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.recipe-manager-table thead {
    background: #c84a31;
    color: white;
}

.recipe-manager-table th {
    padding: 15px;
    text-align: left;
    font-weight: bold;
}

.recipe-manager-table td {
    padding: 5px 15px;
    border-bottom: 1px solid #eee;
}

.recipe-manager-table tbody tr:hover {
    background: #f9f9f9;
}

.recipe-manager-table input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.recipe-id {
    font-family: 'Courier New', monospace;
    color: #666;
    font-weight: bold;
}

.recipe-title-link {
    color: #0066cc;
    text-decoration: none;
    font-weight: 500;
}

.recipe-title-link:hover {
    text-decoration: underline;
}

.bulk-actions {
    background: #fff;
    padding: 20px;
    margin-top: 20px;
    border: 2px solid #c84a31;
    display: flex;
    gap: 15px;
    align-items: center;
}

.action-btn {
    padding: 10px 20px;
    font-size: 14px;
    cursor: pointer;
    border: none;
    border-radius: 3px;
    font-weight: bold;
}

.action-btn:hover {
    opacity: 0.8;
}

.action-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.recipe-count {
    font-size: 14px;
    color: #666;
    margin-bottom: 15px;
}

.selected-count {
    font-weight: bold;
    color: #c84a31;
}
</style>

<div class="recipe-manager">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
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
//----
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
                        // Show actual WordPress role of the collection owner
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
    // Show success messages
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
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
            <label style="font-weight: 600;">Filter by Categories:</label>
            
            <!-- Category Filter Dropdown with Checkboxes -->
            <div style="position: relative;">
                <button type="button" id="categoryFilterBtn" onclick="toggleCategoryDropdown()" class="action-btn" style="background: #6c757d; color: white; min-width: 200px; text-align: left; position: relative;">
                    <span id="filterBtnText">Select Categories</span>
                    <span style="float: right;">▼</span>
                </button>
                
                <div id="categoryDropdown" style="display: none; position: absolute; top: 100%; left: 0; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); min-width: 250px; max-height: 400px; overflow-y: auto; z-index: 1000; margin-top: 5px;">
                    <div style="padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <strong>Select one or more:</strong>
                        <button type="button" onclick="clearFilters()" class="action-btn" style="background: #dc3545; color: white; padding: 4px 12px; font-size: 12px;">
                            Clear All
                        </button>
                    </div>
                    <?php
                    // Get categories that belong to the selected collection owner
                    $categories = get_user_categories_with_counts($selected_collection);
                    
                    // Parse currently selected categories
                    $current_cats = array();
                    if (!empty($_GET['recipe_cat'])) {
                        $cat_string = $_GET['recipe_cat'];
                        $current_cats = array_map('intval', explode(',', $cat_string));
                    }
                    
                    foreach ($categories as $cat) {
                        // recipe_count already included from get_user_categories_with_counts
                        if ($cat->recipe_count == 0) continue; // Skip empty categories
                        
                        $checked = in_array($cat->cat_id, $current_cats) ? 'checked' : '';
                        echo '<label style="display: block; padding: 8px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0;" onmouseover="this.style.background=\'#f8f9fa\'" onmouseout="this.style.background=\'white\'">';
                        echo '<input type="checkbox" name="category_filters[]" value="' . $cat->cat_id . '" ' . $checked . ' onchange="applyFiltersInstantly()" style="margin-right: 8px;">';
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
        <?php if (!empty($current_cats)): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">
            <span style="font-size: 14px; color: #666; padding: 4px 0;">Active filters:</span>
            <?php 
            foreach ($categories as $cat) {
                if (in_array($cat->cat_id, $current_cats)) {
                    $remove_url = home_url('/recipe-manager/');
                    $remaining_cats = array_diff($current_cats, array($cat->cat_id));
                    if (!empty($remaining_cats)) {
                        $remove_url .= '?recipe_cat=' . implode(',', $remaining_cats);
                    }
                    if ($selected_collection != get_current_user_id()) {
                        $remove_url .= (strpos($remove_url, '?') !== false ? '&' : '?') . 'collection=' . $selected_collection;
                    }
                    
                    echo '<span style="display: inline-flex; align-items: center; background: #007bff; color: white; padding: 4px 10px; border-radius: 12px; font-size: 13px; gap: 6px;">';
                    echo esc_html($cat->cat_name);
                    echo '<a href="' . esc_url($remove_url) . '" style="color: white; text-decoration: none; font-weight: bold; font-size: 16px;" title="Remove filter">×</a>';
                    echo '</span>';
                }
            }
            ?>
        </div>
        <?php endif; ?>
        
        <?php if ($is_owner): // Only collection owners can manage permissions ?>
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
    
    <form method="post" id="recipeManagerForm" onsubmit="cleanupHiddenCheckboxes(event)">
        <?php
        // Get recipes from selected collection
        $args = array(
            'post_type' => 'recipe',
            'posts_per_page' => -1,
            'author' => $selected_collection, // Show selected collection's recipes
            'orderby' => 'title',
            'order' => 'ASC',
        );
        
        // Filter by multiple categories (AND logic)
        if (!empty($_GET['recipe_cat'])) {
            global $wpdb;
            
            $cat_string = $_GET['recipe_cat'];
            $cat_ids = array_map('intval', explode(',', $cat_string));
            
            if (count($cat_ids) === 1) {
                // Single category - get all recipe IDs in this category
                $recipe_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT recipe_id FROM {$wpdb->prefix}recipe_category_relationships WHERE cat_id = %d",
                    $cat_ids[0]
                ));
                
                if (!empty($recipe_ids)) {
                    $args['post__in'] = $recipe_ids;
                    $args['orderby'] = 'post__in'; // Preserve order from query
                } else {
                    $args['post__in'] = array(0); // No recipes
                }
            } else {
                // Multiple categories - find recipes that have ALL selected categories (AND logic)
                $placeholders = implode(',', array_fill(0, count($cat_ids), '%d'));
                
                // Find recipe IDs that appear in relationships for ALL selected categories
                $sql = $wpdb->prepare("
                    SELECT recipe_id
                    FROM {$wpdb->prefix}recipe_category_relationships
                    WHERE cat_id IN ({$placeholders})
                    GROUP BY recipe_id
                    HAVING COUNT(DISTINCT cat_id) = %d
                ", array_merge($cat_ids, array(count($cat_ids))));
                
                $matching_recipe_ids = $wpdb->get_col($sql);
                
                if (!empty($matching_recipe_ids)) {
                    $args['post__in'] = $matching_recipe_ids;
                    $args['orderby'] = 'post__in'; // Preserve order from query
                } else {
                    $args['post__in'] = array(0); // No recipes match all categories
                }
            }
        }
        
        $recipes = new WP_Query($args);
        
        // DEBUG - Check what's happening with Mulligatawny
        if (!empty($_GET['recipe_cat'])) {
            echo "<!-- DEBUG: Category filter active -->";
            echo "<!-- DEBUG: Selected cat IDs: " . implode(',', $cat_ids) . " -->";
            echo "<!-- DEBUG: Found recipe IDs: " . (!empty($args['post__in']) ? implode(',', $args['post__in']) : 'NONE') . " -->";
            echo "<!-- DEBUG: WP_Query found: " . $recipes->found_posts . " posts -->";
            
            // Check Mulligatawny specifically
            global $wpdb;
            $mull_cats = $wpdb->get_results("
                SELECT p.ID, p.post_title, GROUP_CONCAT(r.cat_id) as cat_ids
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->prefix}recipe_category_relationships r ON p.ID = r.recipe_id
                WHERE p.post_title LIKE '%Mulligatawny%'
                GROUP BY p.ID
            ");
            foreach ($mull_cats as $row) {
                echo "<!-- DEBUG: Mulligatawny ID=" . $row->ID . " categories=" . $row->cat_ids . " -->";
            }
        }
        ?>
        
        <div class="recipe-count">
            Showing <strong><?php echo $recipes->post_count; ?></strong> recipes | 
            Selected: <span class="selected-count" id="selectedCount">0</span>
        </div>
        
        <!-- Search Box -->
        <div style="margin: 15px 0; padding: 15px; background: #fff; border: 2px solid #c84a31; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
            <label style="font-weight: 600; font-size: 15px;">🔍 Search Recipes:</label>
            <input 
                type="text" 
                id="recipeSearch" 
                placeholder="Type to search recipe titles..." 
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
        
        <!-- Bulk Actions - Top -->
        <div class="bulk-actions" style="margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <label><strong>With Selected:</strong></label>
            
            <button type="submit" name="bulk_action" value="view" class="action-btn" style="background: #0073aa; color: white;" id="viewBtnTop" disabled>
                👁️ View Selected
            </button>
            
            <button type="submit" name="bulk_action" value="print" class="action-btn btn-print" id="printBtnTop" disabled>
                🖨️ Print Book
            </button>
            
            <?php if ($can_view_only && $selected_collection !== get_current_user_id()): ?>
            <button type="submit" name="bulk_action" value="copy_to_my_recipes" class="action-btn" style="background: #3498db; color: white;" id="copyToMyBtnTop" disabled>
                📋 Copy to My Recipes
            </button>
            <?php endif; ?>
            
            <?php if (current_user_can('edit_posts') && $can_manage): ?>
            <button type="submit" name="bulk_action" value="copy" class="action-btn btn-copy" id="copyBtnTop" disabled 
                    onclick="return confirm('Copy the first selected recipe?')">
                📋 Copy
            </button>
            
            <button type="button" class="action-btn" style="background: #7c3aed; color: white;" id="shareBtnTop" disabled
                    onclick="openShareDialog()">
                🔗 Share
            </button>
            
            <button type="submit" name="bulk_action" value="delete" class="action-btn btn-delete" id="deleteBtnTop" disabled
                    onclick="return confirm('Are you sure you want to delete the selected recipes? This cannot be undone.')">
                🗑️ Delete
            </button>
            <?php endif; ?>
        </div>
        
        <table class="recipe-manager-table">
            <thead>
                <tr>
                    <th style="width: 50px;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                    </th>
                    <th style="width: 80px;">ID</th>
                    <th>Recipe Title</th>
                    <th style="width: 200px;">Category</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ($recipes->have_posts()):
                    while ($recipes->have_posts()): 
                        $recipes->the_post();
                        $post_id = get_the_ID();
                        
                        // Get stored permanent recipe ID (or generate if missing)
                        $recipe_id = get_post_meta($post_id, '_recipe_id', true);
                        if (empty($recipe_id)) {
                            // If no ID exists, create one based on post ID
                            $recipe_id = 'R' . str_pad($post_id, 4, '0', STR_PAD_LEFT);
                            update_post_meta($post_id, '_recipe_id', $recipe_id);
                        }
                        
                        // Get all category names from custom tables
                        $recipe_cats = get_recipe_categories($post_id);
                        if (!empty($recipe_cats)) {
                            $category_names = array_map(function($cat) { return $cat->cat_name; }, $recipe_cats);
                            $category_display = implode(', ', $category_names);
                        } else {
                            $category_display = 'Uncategorized';
                        }
                ?>
                <tr>
                    <td>
                        <input type="checkbox" name="selected_recipes[]" value="<?php echo $post_id; ?>" class="recipe-checkbox" onchange="updateSelectedCount()">
                    </td>
                    <td>
                        <span class="recipe-id"><?php echo $recipe_id; ?></span>
                    </td>
                    <td>
                        <?php 
                        // Everyone can view recipes by clicking the title
                        $view_url = home_url('/recipe-view-page/?ids=' . $post_id);
                        ?>
                        <a href="<?php echo esc_url($view_url); ?>" class="recipe-title-link">
                            <?php the_title(); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html($category_display); ?></td>
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
        
        <!-- Bulk Actions -->
        <div class="bulk-actions">
            <label><strong>With Selected:</strong></label>
            
            <button type="submit" name="bulk_action" value="view" class="action-btn" style="background: #0073aa; color: white;" id="viewBtn" disabled>
                👁️ View Selected
            </button>
            
            <button type="submit" name="bulk_action" value="print" class="action-btn btn-print" id="printBtn" disabled>
                🖨️ Print Book
            </button>
            
            <?php if ($can_view_only && $selected_collection !== get_current_user_id()): ?>
            <button type="submit" name="bulk_action" value="copy_to_my_recipes" class="action-btn" style="background: #3498db; color: white;" id="copyToMyBtn" disabled>
                📋 Copy to My Recipes
            </button>
            <?php endif; ?>
            
            <?php if (current_user_can('edit_posts') && $can_manage): ?>
            <button type="submit" name="bulk_action" value="copy" class="action-btn btn-copy" id="copyBtn" disabled 
                    onclick="return confirm('Copy the first selected recipe?')">
                📋 Copy
            </button>
            
            <button type="button" class="action-btn" style="background: #7c3aed; color: white;" id="shareBtn" disabled
                    onclick="openShareDialog()">
                🔗 Share
            </button>
            
            <button type="submit" name="bulk_action" value="delete" class="action-btn btn-delete" id="deleteBtn" disabled
                    onclick="return confirm('Are you sure you want to delete the selected recipes? This cannot be undone.')">
                🗑️ Delete
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
    </form>
</div>

<!-- Load external JavaScript -->
<script src="<?php echo get_stylesheet_directory_uri(); ?>/recipe-manager-scripts.js"></script>

<script>
// Share dialog functions that need PHP data
function openShareDialog() {
    const checked = document.querySelectorAll('.recipe-checkbox:checked');
    const recipeIds = Array.from(checked).map(cb => cb.value);
    
    if (recipeIds.length === 0) {
        alert('Please select recipes to share');
        return;
    }
    
    // Get list of users to share with
    <?php
    require_once(get_stylesheet_directory() . '/collection-permissions.php');
    $current_user_id = get_current_user_id();
    $potential_recipients = get_users(array('role__in' => array('author', 'editor', 'subscriber')));
    $recipient_list = array();
    foreach ($potential_recipients as $user) {
        if ($user->ID != $current_user_id) {
            // Show role indicator
            if (in_array('author', $user->roles) || in_array('editor', $user->roles)) {
                $role_label = '';
            } else {
                $role_label = ' (will become author)';
            }
            $recipient_list[] = array(
                'id' => $user->ID,
                'name' => $user->display_name . $role_label
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
        options += `<option value="${recipient.id}">${recipient.name}'s Collection</option>`;
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
    if (dialog) {
        dialog.remove();
    }
}

function executeShare() {
    const recipientId = document.getElementById('shareRecipient').value;
    const checked = document.querySelectorAll('.recipe-checkbox:checked');
    const recipeIds = Array.from(checked).map(cb => cb.value);
    
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

// Initialize count on page load
updateSelectedCount();
</script>

<?php get_footer(); ?>