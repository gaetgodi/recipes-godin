<?php
/**
 * Template Name: Category Manager
 * 
 * Manage recipe categories - add, edit, delete
 */

get_header();

$current_user = wp_get_current_user();

// Check permission - only editors can manage categories
if (!current_user_can('edit_posts')) {
    echo '<div style="max-width: 800px; margin: 40px auto; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;">';
    echo '<h2>Access Denied</h2>';
    echo '<p>You do not have permission to manage categories.</p>';
    echo '<p><a href="' . home_url('/recipe-manager/') . '">← Back to Recipe Manager</a></p>';
    echo '</div>';
    get_footer();
    exit;
}

// Determine which collection's categories to manage
$collection_id = isset($_GET['collection']) ? intval($_GET['collection']) : get_current_user_id();

// Verify administrator can manage any collection, others only their own
if ($collection_id != get_current_user_id() && !current_user_can('administrator')) {
    $collection_id = get_current_user_id();
}

$collection_user = get_user_by('id', $collection_id);
$is_own_collection = ($collection_id == get_current_user_id());

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new category
    if (isset($_POST['add_category'])) {
        check_admin_referer('add_category');
        
        $cat_name = sanitize_text_field($_POST['category_name']);
        
        if (!empty($cat_name)) {
            $result = create_user_category($collection_id, $cat_name);
            
            if (isset($result['error'])) {
                $message = 'Error: ' . $result['error'];
                $message_type = 'error';
            } else {
                $message = "Category '{$cat_name}' added successfully!";
                $message_type = 'success';
            }
        } else {
            $message = 'Category name cannot be empty.';
            $message_type = 'error';
        }
    }
    
    // Edit category
    if (isset($_POST['edit_category'])) {
        check_admin_referer('edit_category_' . $_POST['category_id']);
        
        $cat_id = intval($_POST['category_id']);
        $cat_name = sanitize_text_field($_POST['category_name']);
        
        if (!empty($cat_name)) {
            $result = update_category_name($cat_id, $cat_name);
            
            if (isset($result['error'])) {
                $message = 'Error: ' . $result['error'];
                $message_type = 'error';
            } else {
                $message = "Category updated successfully!";
                $message_type = 'success';
            }
        }
    }
    
    // Delete category
    if (isset($_POST['delete_category'])) {
        check_admin_referer('delete_category_' . $_POST['category_id']);
        
        $cat_id = intval($_POST['category_id']);
        $result = delete_category($cat_id);
        
        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = "Category deleted successfully!";
            $message_type = 'success';
        }
    }
}

// Get categories for the selected collection using custom tables
$categories = get_user_categories_with_counts($collection_id);

?>

<style>
.category-manager {
    max-width: 1200px;
    margin: 40px auto;
    padding: 0 20px;
}

.category-manager h1 {
    color: #c84a31;
    font-size: 36px;
    margin-bottom: 10px;
}

.category-manager-subtitle {
    color: #666;
    margin-bottom: 30px;
}

.message {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.message.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.message.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.add-category-section {
    background: #f9f9f9;
    padding: 20px;
    margin-bottom: 30px;
    border: 2px solid #c84a31;
    border-radius: 4px;
}

.add-category-section h2 {
    color: #c84a31;
    font-size: 20px;
    margin-bottom: 15px;
}

.add-category-form {
    display: flex;
    gap: 10px;
    align-items: center;
}

.add-category-form input[type="text"] {
    flex: 1;
    padding: 10px;
    font-size: 14px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.add-category-form button {
    padding: 10px 20px;
    background: #00a32a;
    color: white;
    border: none;
    border-radius: 3px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

.add-category-form button:hover {
    background: #008a20;
}

.categories-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.categories-table thead {
    background: #c84a31;
    color: white;
}

.categories-table th {
    padding: 12px;
    text-align: left;
    font-weight: bold;
}

.categories-table td {
    padding: 5px 12px;
    border-bottom: 1px solid #eee;
}

.categories-table tbody tr:hover {
    background: #f9f9f9;
}

.edit-row {
    display: none;
}

.edit-row.active {
    display: table-row;
}

.edit-form {
    display: flex;
    gap: 10px;
    align-items: center;
}

.edit-form input[type="text"] {
    flex: 1;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.btn {
    padding: 6px 12px;
    font-size: 13px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-edit {
    background: #0073aa;
    color: white;
}

.btn-delete {
    background: #d63638;
    color: white;
}

.btn-save {
    background: #00a32a;
    color: white;
}

.btn-cancel {
    background: #666;
    color: white;
}

.btn:hover {
    opacity: 0.8;
}

.category-count {
    color: #666;
    font-size: 12px;
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

<div class="category-manager">
    <?php 
    $back_url = home_url('/recipe-manager/');
    if ($collection_id != get_current_user_id()) {
        $back_url .= '?collection=' . $collection_id;
    }
    ?>
    <a href="<?php echo $back_url; ?>" class="back-link">← Back to Recipe Manager</a>
    
    <h1>Category Manager<?php if (!$is_own_collection && $collection_user): ?> - <?php echo esc_html($collection_user->display_name); ?>'s Categories<?php endif; ?></h1>
    <p class="category-manager-subtitle">Manage <?php echo $is_own_collection ? 'your' : esc_html($collection_user->display_name) . "'s"; ?> recipe categories</p>
    
    <?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>">
        <?php echo esc_html($message); ?>
    </div>
    <?php endif; ?>
    
    <!-- Add New Category -->
    <div class="add-category-section">
        <h2>Add New Category</h2>
        <form method="post" class="add-category-form">
            <?php wp_nonce_field('add_category'); ?>
            <input 
                type="text" 
                name="category_name" 
                placeholder="Enter category name (e.g., Soups, Salads, Desserts)" 
                required
            />
            <button type="submit" name="add_category">+ Add Category</button>
        </form>
    </div>
    
    <!-- Categories List -->
    <table class="categories-table">
        <thead>
            <tr>
                <th style="width: 300px; max-width: 300px;">Category Name</th>
                <th style="width: 100px;">Recipes</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $cat): ?>
                <!-- View Row -->
                <tr id="view-<?php echo $cat->cat_id; ?>">
                    <td style="word-wrap: break-word; word-break: break-word;">
                        <strong><?php echo esc_html($cat->cat_name); ?></strong>
                    </td>
                    <td>
                        <span class="category-count"><?php echo $cat->recipe_count; ?></span>
                    </td>
                    <td>
                        <button onclick="editCategory(<?php echo $cat->cat_id; ?>)" class="btn btn-edit">
                            ✏️ Edit
                        </button>
                        
                        <?php if ($cat->recipe_count == 0 || current_user_can('administrator')): ?>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('delete_category_' . $cat->cat_id); ?>
                            <input type="hidden" name="category_id" value="<?php echo $cat->cat_id; ?>">
                            <button 
                                type="submit" 
                                name="delete_category" 
                                class="btn btn-delete"
                                onclick="return confirm('Delete category \'<?php echo esc_js($cat->cat_name); ?>\'<?php echo $cat->recipe_count > 0 ? ' and remove it from ' . $cat->recipe_count . ' recipes' : ''; ?>?')"
                            >
                                🗑️ Delete
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-delete" disabled title="Cannot delete category with recipes">
                            🗑️ Delete
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <!-- Edit Row -->
                <tr id="edit-<?php echo $cat->cat_id; ?>" class="edit-row">
                    <td colspan="3">
                        <form method="post" class="edit-form">
                            <?php wp_nonce_field('edit_category_' . $cat->cat_id); ?>
                            <input type="hidden" name="category_id" value="<?php echo $cat->cat_id; ?>">
                            <input 
                                type="text" 
                                name="category_name" 
                                value="<?php echo esc_attr($cat->cat_name); ?>"
                                required
                            />
                            <button type="submit" name="edit_category" class="btn btn-save">
                                ✓ Save
                            </button>
                            <button type="button" onclick="cancelEdit(<?php echo $cat->cat_id; ?>)" class="btn btn-cancel">
                                Cancel
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="3" style="text-align: center; padding: 40px;">
                    No categories yet. Add your first category above!
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function editCategory(catId) {
    document.getElementById('view-' + catId).style.display = 'none';
    document.getElementById('edit-' + catId).classList.add('active');
}

function cancelEdit(catId) {
    document.getElementById('view-' + catId).style.display = 'table-row';
    document.getElementById('edit-' + catId).classList.remove('active');
}
</script>

<?php get_footer(); ?>