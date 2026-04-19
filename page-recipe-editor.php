<?php
/**
 * Template Name: Recipe Editor
 * 
 * Frontend recipe add/edit interface with featured image OCR and text import
 */

// Include the image upload handler
require_once(get_stylesheet_directory() . '/recipe-image-upload-handler.php');

// Handle AJAX delete request
add_action('wp_ajax_delete_recipe', function() {
    check_ajax_referer('delete_recipe_' . $_POST['recipe_id']);
    
    require_once(get_stylesheet_directory() . '/collection-permissions.php');
    
    $recipe_id = intval($_POST['recipe_id']);
    $recipe = get_post($recipe_id);
    
    if (!$recipe || $recipe->post_type !== 'recipe') {
        wp_send_json_error();
    }
    
    $current_user_id = get_current_user_id();
    $recipe_owner_id = $recipe->post_author;
    
    // Can delete if: administrator, owner, or has editor permission
    $can_delete = false;
    if (current_user_can('administrator')) {
        $can_delete = true;
    } elseif ($recipe_owner_id == $current_user_id) {
        $can_delete = true;
    } elseif (user_can_manage_collection($current_user_id, $recipe_owner_id)) {
        $can_delete = true;
    }
    
    if ($can_delete && current_user_can('edit_posts')) {
        wp_delete_post($recipe_id, true);
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
});

get_header();

$current_user = wp_get_current_user();

// Check permission
if (!current_user_can('edit_posts')) {
    echo '<div style="max-width: 800px; margin: 40px auto; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;">';
    echo '<h2>Access Denied</h2>';
    echo '<p>You do not have permission to add or edit recipes.</p>';
    echo '<p><a href="' . home_url('/recipe-manager/') . '">← Back to Recipe Manager</a></p>';
    echo '</div>';
    get_footer();
    exit;
}

// Get recipe ID if editing
$recipe_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_editing = ($recipe_id > 0);

// Build back URL with category filter if applicable
$back_url = home_url('/recipe-manager/');

if (!empty($_GET['from_cat'])) {
    $back_url .= '?recipe_cat=' . intval($_GET['from_cat']);
} elseif ($is_editing) {
    $recipe_cats = get_recipe_categories($recipe_id);
    if (!empty($recipe_cats) && count($recipe_cats) === 1) {
        $back_url .= '?recipe_cat=' . $recipe_cats[0]->cat_id;
    }
}

// Load recipe data if editing
if ($is_editing) {
    $recipe = get_post($recipe_id);
    
    require_once(get_stylesheet_directory() . '/collection-permissions.php');
    
    $recipe_owner_id = $recipe ? $recipe->post_author : 0;
    $can_edit = false;
    
    if ($recipe && $recipe->post_type === 'recipe') {
        if (current_user_can('administrator')) {
            $can_edit = true;
        } elseif ($recipe_owner_id == get_current_user_id()) {
            $can_edit = true;
        } elseif (user_can_manage_collection(get_current_user_id(), $recipe_owner_id)) {
            $can_edit = true;
        }
    }
    
    if (!$can_edit) {
        echo '<div style="max-width: 800px; margin: 40px auto; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;">';
        echo '<h2>Recipe Not Found</h2>';
        echo '<p>This recipe does not exist or you do not have permission to edit it.</p>';
        echo '<p><a href="' . home_url('/recipe-manager/') . '">← Back to Recipe Manager</a></p>';
        echo '</div>';
        get_footer();
        exit;
    }
    
    $recipe_title = $recipe->post_title;
    $recipe_ingredients = get_post_meta($recipe_id, '_recipe_ingredients', true);
    $recipe_method = get_post_meta($recipe_id, '_recipe_method', true);
    $recipe_notes = get_post_meta($recipe_id, '_recipe_notes', true);
    
    function html_to_plain_text($html, $is_method = false) {
        if (empty($html)) return '';
        
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        
        $html = preg_replace('/<\/(p|div|h[1-6])>/i', "\n\n", $html);
        $html = preg_replace('/<\/(li)>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        
        $html = strip_tags($html);
        
        if ($is_method) {
            $html = str_replace('.', ".\n", $html);
            $html = preg_replace('/(\d)\.\n(\d)/', '$1.$2', $html);
        }
        
        $lines = explode("\n", $html);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, function($line) { return $line !== ''; });
        
        return implode("\n", $lines);
    }
    
    $recipe_ingredients = html_to_plain_text($recipe_ingredients, false);
    $recipe_method = html_to_plain_text($recipe_method, true);
    $recipe_notes = html_to_plain_text($recipe_notes, false);
    
    $recipe_categories_objs = get_recipe_categories($recipe_id);
    $recipe_category = array_map(function($cat) { return $cat->cat_id; }, $recipe_categories_objs);
    
    // Get featured image if exists
    $featured_image_id = get_post_thumbnail_id($recipe_id);
    $featured_image_url = $featured_image_id ? wp_get_attachment_url($featured_image_id) : '';
} else {
    $recipe_title = '';
    $recipe_ingredients = '';
    $recipe_method = '';
    $recipe_notes = '';
    $recipe_category = array();
    $featured_image_id = 0;
    $featured_image_url = '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recipe'])) {
    check_admin_referer('save_recipe_' . $recipe_id);
    
    $title = sanitize_text_field($_POST['recipe_title']);
    $ingredients = sanitize_textarea_field($_POST['recipe_ingredients']);
    $method = sanitize_textarea_field($_POST['recipe_method']);
    $notes = sanitize_textarea_field($_POST['recipe_notes']);
    $categories = isset($_POST['recipe_categories']) ? array_map('intval', $_POST['recipe_categories']) : array();
    $new_featured_image_id = isset($_POST['featured_image_id']) ? intval($_POST['featured_image_id']) : 0;
    
    function auto_format_content($content, $is_method = false) {
        if (empty($content)) return '';
        if (strpos($content, '<ul>') !== false || strpos($content, '<ol>') !== false) return $content;
        
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content)));
        if (empty($lines)) return '';
        if (count($lines) === 1) return '<p>' . esc_html($lines[0]) . '</p>';
        
        $list_items = [];
        foreach ($lines as $line) {
            $line = preg_replace('/^[\-•*]\s+/', '', $line);
            
            if ($is_method) {
                $line = preg_replace('/^\d+[\.)]\s+/', '', $line);
            }
            
            if (!empty($line)) $list_items[] = '<li>' . esc_html($line) . '</li>';
        }
        
        $tag = $is_method ? 'ol' : 'ul';
        return empty($list_items) ? '' : '<' . $tag . '>' . implode('', $list_items) . '</' . $tag . '>';
    }
    
    $ingredients = auto_format_content($ingredients, false);
    $method = auto_format_content($method, true);
    if (!empty($notes) && strpos($notes, '<p>') === false) {
        $notes = '<p>' . esc_html($notes) . '</p>';
    }
    
    $errors = array();
    if (empty($title)) {
        $errors[] = 'Recipe title is required.';
    }
    if (empty($ingredients)) {
        $errors[] = 'Ingredients are required.';
    }
    if (empty($method)) {
        $errors[] = 'Method is required.';
    }
    if (empty($categories)) {
        $errors[] = 'Please select at least one category.';
    }
    
    if (empty($errors)) {
        $post_data = array(
            'post_title' => $title,
            'post_type' => 'recipe',
            'post_status' => 'publish',
        );
        
        if ($is_editing) {
            $post_data['ID'] = $recipe_id;
            $saved_id = wp_update_post($post_data);
        } else {
            $post_data['post_author'] = get_current_user_id();
            $saved_id = wp_insert_post($post_data);
        }
        
        if ($saved_id && !is_wp_error($saved_id)) {
            update_post_meta($saved_id, '_recipe_ingredients', $ingredients);
            update_post_meta($saved_id, '_recipe_method', $method);
            update_post_meta($saved_id, '_recipe_notes', $notes);
            
            // Set featured image if one was uploaded
            if ($new_featured_image_id > 0) {
                set_post_thumbnail($saved_id, $new_featured_image_id);
                
                // Also update the attachment's post parent to link it to this recipe
                wp_update_post(array(
                    'ID' => $new_featured_image_id,
                    'post_parent' => $saved_id
                ));
            }
            
            if (!empty($categories)) {
                set_recipe_categories($saved_id, $categories);
            } else {
                set_recipe_categories($saved_id, array());
            }
            
            $redirect_url = home_url('/recipe-manager/?saved=1');
            
            if (!empty($_GET['collection'])) {
                $redirect_url = add_query_arg('collection', intval($_GET['collection']), $redirect_url);
            }
            
            if (!empty($_GET['from_cat'])) {
                $redirect_url = add_query_arg('recipe_cat', intval($_GET['from_cat']), $redirect_url);
            } elseif (!empty($categories) && count($categories) === 1) {
                $redirect_url = add_query_arg('recipe_cat', $categories[0], $redirect_url);
            }
            
            wp_redirect($redirect_url);
            exit;
        } else {
            $errors[] = 'Error saving recipe. Please try again.';
        }
    } else {
        $recipe_title = $title;
        $recipe_ingredients = $ingredients;
        $recipe_method = $method;
        $recipe_notes = $notes;
        $recipe_category = $categories;
    }
}
?>

<div class="recipe-editor">
    <h1><?php echo $is_editing ? 'Edit Recipe' : 'Add New Recipe'; ?></h1>
    <p class="recipe-editor-subtitle">
        <?php echo $is_editing ? 'Make changes to your recipe below' : 'Fill in the details for your new recipe'; ?>
    </p>
    
    <?php if (isset($_GET['copied'])): ?>
    <div style="background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb; color: #155724; border-radius: 4px;">
        ✅ Recipe copied successfully! This is now your recipe - make your changes and save.
    </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['promoted'])): ?>
    <div style="background: #d1ecf1; padding: 15px; margin: 20px 0; border: 1px solid #bee5eb; color: #0c5460; border-radius: 4px;">
        🎉 <strong>Congratulations!</strong> You are now an <strong>author</strong> and can create and manage your own recipe collection!
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
    <div class="error-messages">
        <strong>⚠️ Please fix the following errors:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
            <li><?php echo esc_html($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Import Options Tabs -->
    <div style="margin-bottom: 30px;">
        <div style="border-bottom: 2px solid #c84a31; margin-bottom: 20px;">
            <button type="button" class="tab-btn active" onclick="switchTab('image')" id="imageTab">
                📸 Import from Image
            </button>
            <button type="button" class="tab-btn" onclick="switchTab('text')" id="textTab">
                📝 Import from Text
            </button>
        </div>
        
        <!-- Image Import Section -->
        <div id="imageImport" class="import-section">
            <div class="image-upload-section">
                <h3>📸 Upload Featured Image</h3>
                <p>Upload a photo of your recipe (handwritten recipe cards will be automatically extracted!)</p>
                <p style="font-size: 13px; color: #666; margin-top: 5px;">
                    <strong>Accepted formats:</strong> JPG, PNG, GIF, WebP<br>
                    <strong>Maximum file size:</strong> 5 MB
                </p>
                
                <input type="file" id="recipeImageFile" accept="image/*" style="display: none;" />
                <button type="button" class="upload-btn" id="uploadImageBtn" onclick="document.getElementById('recipeImageFile').click();">
                    Choose Image
                </button>
                <button type="button" class="upload-btn" id="extractBtn" style="display: none; margin-left: 10px;">
                    Upload & Extract Recipe
                </button>
                
                <div id="imagePreview" class="image-preview" style="display: none;">
                    <img id="previewImg" src="" alt="Recipe image preview" />
                </div>
                
                <div id="uploadStatus" class="upload-status" style="display: none;"></div>
                
                <?php if ($featured_image_url): ?>
                <div class="image-preview" style="display: block;">
                    <img src="<?php echo esc_url($featured_image_url); ?>" alt="Current featured image" />
                    <p style="font-size: 13px; color: #666; margin-top: 10px;">Current featured image</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Text Import Section -->
        <div id="textImport" class="import-section" style="display: none;">
            <div class="text-import-section">
                <h3>📝 Import Recipe from Text</h3>
                <p>Import a recipe from a text file or paste recipe text directly</p>
                
                <div style="margin: 20px 0;">
                    <h4 style="margin-bottom: 10px;">Option 1: Upload Text File</h4>
                    <input type="file" id="recipeTextFile" accept=".txt" style="display: none;" />
                    <button type="button" class="upload-btn" onclick="document.getElementById('recipeTextFile').click();">
                        📄 Choose Text File (.txt)
                    </button>
                    <span id="textFileName" style="margin-left: 10px; color: #666;"></span>
                </div>
                
                <div style="margin: 20px 0;">
                    <h4 style="margin-bottom: 10px;">Option 2: Paste Recipe Text</h4>
                    <textarea 
                        id="pastedRecipeText" 
                        placeholder="Paste your recipe here in any format...

Example:
Chocolate Chip Cookies

Ingredients:
2 cups flour
1 cup sugar
2 eggs
1 cup chocolate chips

Method:
Preheat oven to 350°F. Mix dry ingredients. Add wet ingredients. Fold in chocolate chips. Bake for 12 minutes."
                        style="width: 100%; min-height: 250px; padding: 15px; font-family: monospace; font-size: 14px; border: 2px solid #ddd; border-radius: 4px;"
                    ></textarea>
                </div>
                
                <button type="button" class="upload-btn" id="extractTextBtn" onclick="extractFromText()">
                    🔍 Extract Recipe from Text
                </button>
                
                <div id="textStatus" class="upload-status" style="display: none; margin-top: 15px;"></div>
            </div>
        </div>
    </div>
    
    <form method="post" class="editor-form" id="recipeForm">
        <?php wp_nonce_field('save_recipe_' . $recipe_id); ?>
        <input type="hidden" name="featured_image_id" id="featuredImageId" value="<?php echo $featured_image_id; ?>" />
        
        <div class="form-group">
            <label for="recipe_title">
                Recipe Title <span class="required">*</span>
            </label>
            <input 
                type="text" 
                id="recipe_title" 
                name="recipe_title" 
                value="<?php echo esc_attr($recipe_title); ?>"
                placeholder="e.g., Chocolate Chip Cookies"
                required
            />
        </div>
        
        <div class="form-group">
            <label for="recipe_ingredients">
                Ingredients <span class="required">*</span>
            </label>
            <span class="help-text">Enter one ingredient per line</span>
            <textarea 
                id="recipe_ingredients" 
                name="recipe_ingredients" 
                placeholder="1 cup flour&#10;2 eggs&#10;1 tsp vanilla"
                required
            ><?php echo esc_textarea($recipe_ingredients); ?></textarea>
            <div class="tip-box">
                <strong>💡 Tip:</strong> Just type one ingredient per line. They'll be formatted automatically as a nice list!
            </div>
        </div>
        
        <div class="form-group">
            <label for="recipe_method">
                Method/Instructions <span class="required">*</span>
            </label>
            <span class="help-text">Enter one step per line</span>
            <textarea 
                id="recipe_method" 
                name="recipe_method" 
                placeholder="Mix dry ingredients&#10;Add wet ingredients&#10;Bake at 350°F for 20 minutes"
                required
            ><?php echo esc_textarea($recipe_method); ?></textarea>
            <div class="tip-box">
                <strong>💡 Tip:</strong> Each line will become a numbered step automatically!
            </div>
        </div>
        
        <div class="form-group">
            <label for="recipe_notes">
                Notes (Optional)
            </label>
            <span class="help-text">Any special tips, variations, or serving suggestions</span>
            <textarea 
                id="recipe_notes" 
                name="recipe_notes" 
                style="min-height: 100px;"
                placeholder="This is Grandma's recipe. Best served warm with ice cream!"
            ><?php echo esc_textarea($recipe_notes); ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="recipe_category">
                Categories <span class="required">*</span>
            </label>
            <span class="help-text">Select one or more categories</span>
            
            <div style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; background: #fafafa; max-height: 300px; overflow-y: auto;">
                <?php
                $recipe_owner_id = $is_editing ? get_post_field('post_author', $recipe_id) : get_current_user_id();
                
                $categories = get_user_categories($recipe_owner_id);
                
                $selected_cats = is_array($recipe_category) ? $recipe_category : array();
                if (!empty($recipe_category) && !is_array($recipe_category)) {
                    $selected_cats = array($recipe_category);
                }
                
                if (empty($categories)) {
                    echo '<div style="padding: 20px; text-align: center; color: #666;">';
                    echo '<p style="margin: 0 0 10px 0;"><strong>No categories yet!</strong></p>';
                    echo '<p style="margin: 0; font-size: 14px;">You need to create a category first.</p>';
                    $cat_mgr_url = home_url('/category-manager/');
                    if ($recipe_owner_id != get_current_user_id()) {
                        $cat_mgr_url .= '?collection=' . $recipe_owner_id;
                    }
                    echo '<p style="margin: 10px 0 0 0;"><a href="' . $cat_mgr_url . '" style="color: #2271b1; text-decoration: underline;">→ Go to Category Manager</a></p>';
                    echo '</div>';
                } else {
                    foreach ($categories as $cat) {
                        $checked = in_array($cat->cat_id, $selected_cats) ? 'checked' : '';
                        echo '<div style="margin-bottom: 8px;">';
                        echo '<label style="display: flex; align-items: center; cursor: pointer; font-weight: normal;">';
                        echo '<input type="checkbox" name="recipe_categories[]" value="' . $cat->cat_id . '" ' . $checked . ' style="margin-right: 8px; width: 18px; height: 18px;">';
                        echo '<span>' . esc_html($cat->cat_name) . '</span>';
                        echo '</label>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
            <div class="tip-box" style="margin-top: 10px;">
                <strong>💡 Tip:</strong> You can select multiple categories if your recipe fits in more than one!
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="save_recipe" class="btn btn-primary">
                💾 <?php echo $is_editing ? 'Update Recipe' : 'Save Recipe'; ?>
            </button>
            
            <a href="<?php echo esc_url($back_url); ?>" class="btn btn-secondary">
                Cancel
            </a>
            
            <?php if ($is_editing): ?>
            <button type="button" 
                    class="btn btn-danger" 
                    onclick="if(confirm('Are you sure you want to delete this recipe? This cannot be undone.')) { 
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=delete_recipe&recipe_id=<?php echo $recipe_id; ?>&_wpnonce=<?php echo wp_create_nonce('delete_recipe_' . $recipe_id); ?>'
                        }).then(() => window.location.href = '<?php echo esc_url($back_url); ?>');
                    }">
                🗑️ Delete Recipe
            </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<style>
.tab-btn {
    background: #f0f0f0;
    border: none;
    padding: 12px 24px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    border-radius: 6px 6px 0 0;
    margin-right: 5px;
    transition: all 0.3s;
}

.tab-btn:hover {
    background: #e0e0e0;
}

.tab-btn.active {
    background: #c84a31;
    color: white;
}

.import-section {
    animation: fadeIn 0.3s;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<script>
// Tab switching
function switchTab(tab) {
    // Update tab buttons
    document.getElementById('imageTab').classList.remove('active');
    document.getElementById('textTab').classList.remove('active');
    
    if (tab === 'image') {
        document.getElementById('imageTab').classList.add('active');
        document.getElementById('imageImport').style.display = 'block';
        document.getElementById('textImport').style.display = 'none';
    } else {
        document.getElementById('textTab').classList.add('active');
        document.getElementById('imageImport').style.display = 'none';
        document.getElementById('textImport').style.display = 'block';
    }
}

// Image upload and OCR handling
let selectedFile = null;

document.getElementById('recipeImageFile').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!validTypes.includes(file.type)) {
        alert('Please upload a valid image file (JPG, PNG, GIF, or WebP)');
        this.value = '';
        return;
    }
    
    // Validate file size (5MB = 5 * 1024 * 1024 bytes)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        alert('Image is too large! Please use an image smaller than 5 MB.\n\nCurrent size: ' + (file.size / 1024 / 1024).toFixed(2) + ' MB');
        this.value = '';
        return;
    }
    
    selectedFile = file;
    
    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('imagePreview').style.display = 'block';
        document.getElementById('extractBtn').style.display = 'inline-block';
        document.getElementById('uploadStatus').style.display = 'none';
    };
    reader.readAsDataURL(file);
});

document.getElementById('extractBtn').addEventListener('click', function() {
    if (!selectedFile) {
        alert('Please select an image first');
        return;
    }
    
    // Show processing status
    const statusDiv = document.getElementById('uploadStatus');
    statusDiv.className = 'upload-status processing';
    statusDiv.textContent = '🔄 Uploading image and extracting recipe data...';
    statusDiv.style.display = 'block';
    
    // Disable button during upload
    this.disabled = true;
    document.getElementById('uploadImageBtn').disabled = true;
    
    // Create FormData
    const formData = new FormData();
    formData.append('action', 'upload_recipe_image');
    formData.append('recipe_id', '<?php echo $recipe_id; ?>');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_image_upload'); ?>');
    formData.append('recipe_image', selectedFile);
    
    // Upload and extract
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Full API Response:', data);
        
        if (data.success) {
            // Update status
            statusDiv.className = 'upload-status success';
            
            if (data.data.extracted_data.found === false) {
                statusDiv.textContent = '✅ Image uploaded! No recipe data found in image.';
                document.getElementById('recipe_notes').value = 'No recipe interpreted in featured image';
            } else {
                statusDiv.textContent = '✅ Image uploaded and recipe extracted! Check the fields below.';
                
                // Fill in the form fields
                if (data.data.extracted_data.title) {
                    document.getElementById('recipe_title').value = data.data.extracted_data.title;
                }
                if (data.data.extracted_data.ingredients) {
                    document.getElementById('recipe_ingredients').value = data.data.extracted_data.ingredients;
                }
                if (data.data.extracted_data.method) {
                    document.getElementById('recipe_method').value = data.data.extracted_data.method;
                }
                
                // Put raw extraction in notes
                document.getElementById('recipe_notes').value = 'RAW EXTRACTION:\n\n' + data.data.raw_response;
                
                // Show translate button
                showTranslateButton();
            }
            
            // Store featured image ID
            document.getElementById('featuredImageId').value = data.data.attachment_id;
            
        } else {
            statusDiv.className = 'upload-status error';
            const errorMsg = data.data && data.data.message ? data.data.message : 'Unknown error';
            statusDiv.textContent = '❌ Error: ' + errorMsg;
            console.error('Error details:', data);
        }
        
        document.getElementById('extractBtn').disabled = false;
        document.getElementById('uploadImageBtn').disabled = false;
    })
    .catch(error => {
        console.error('Fetch error:', error);
        statusDiv.className = 'upload-status error';
        statusDiv.textContent = '❌ Upload failed: ' + error.message;
        document.getElementById('extractBtn').disabled = false;
        document.getElementById('uploadImageBtn').disabled = false;
    });
});

// Text file upload handling
document.getElementById('recipeTextFile').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    // Show filename
    document.getElementById('textFileName').textContent = file.name;
    
    // Read file content
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('pastedRecipeText').value = e.target.result;
    };
    reader.readAsText(file);
});

// Extract recipe from text (file or pasted) - does NOT translate
function extractFromText() {
    const textContent = document.getElementById('pastedRecipeText').value.trim();
    
    if (!textContent) {
        alert('Please paste recipe text or upload a text file first');
        return;
    }
    
    const statusDiv = document.getElementById('textStatus');
    const extractBtn = document.getElementById('extractTextBtn');
    
    statusDiv.className = 'upload-status processing';
    statusDiv.textContent = '🔄 Extracting recipe from text...';
    statusDiv.style.display = 'block';
    extractBtn.disabled = true;
    
    // Create FormData
    const formData = new FormData();
    formData.append('action', 'extract_recipe_from_text');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_text_extract'); ?>');
    formData.append('text_content', textContent);
    
    // Use text extraction API (does NOT translate)
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Fill in the form fields
            if (data.data.extracted_data.title) {
                document.getElementById('recipe_title').value = data.data.extracted_data.title;
            }
            if (data.data.extracted_data.ingredients) {
                document.getElementById('recipe_ingredients').value = data.data.extracted_data.ingredients;
            }
            if (data.data.extracted_data.method) {
                document.getElementById('recipe_method').value = data.data.extracted_data.method;
            }
            
            statusDiv.className = 'upload-status success';
            statusDiv.textContent = '✅ Recipe extracted from text! Check the fields below.';
            
            // Put original text in notes
            document.getElementById('recipe_notes').value = 'ORIGINAL TEXT:\n\n' + textContent;
            
            // Show translate button
            showTranslateButton();
        } else {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Extraction failed: ' + (data.data ? data.data.message : 'Unknown error');
        }
        
        extractBtn.disabled = false;
    })
    .catch(error => {
        statusDiv.className = 'upload-status error';
        statusDiv.textContent = '❌ Extraction failed: ' + error.message;
        extractBtn.disabled = false;
    });
}

// Show translate button after successful extraction
function showTranslateButton() {
    // Check if button already exists
    if (document.getElementById('translateBtn')) return;
    
    // Determine which status div to use
    const imageStatus = document.getElementById('uploadStatus');
    const textStatus = document.getElementById('textStatus');
    const statusDiv = imageStatus.style.display !== 'none' ? imageStatus : textStatus;
    
    const translateBtn = document.createElement('button');
    translateBtn.type = 'button';
    translateBtn.id = 'translateBtn';
    translateBtn.className = 'upload-btn';
    translateBtn.textContent = '🌐 Translate to English';
    translateBtn.style.marginTop = '10px';
    translateBtn.onclick = translateRecipe;
    
    statusDiv.parentNode.insertBefore(translateBtn, statusDiv.nextSibling);
}

// Translate the extracted recipe to English
function translateRecipe() {
    const translateBtn = document.getElementById('translateBtn');
    
    // Determine which status div to use
    const imageStatus = document.getElementById('uploadStatus');
    const textStatus = document.getElementById('textStatus');
    const statusDiv = imageStatus.style.display !== 'none' ? imageStatus : textStatus;
    
    // Disable button
    translateBtn.disabled = true;
    translateBtn.textContent = '🔄 Translating...';
    
    // Get current field values
    const title = document.getElementById('recipe_title').value;
    const ingredients = document.getElementById('recipe_ingredients').value;
    const method = document.getElementById('recipe_method').value;
    
    // Create FormData
    const formData = new FormData();
    formData.append('action', 'translate_recipe');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_translation'); ?>');
    formData.append('title', title);
    formData.append('ingredients', ingredients);
    formData.append('method', method);
    
    // Call translation AJAX
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update fields with translated text
            if (data.data.translated_data.title) {
                document.getElementById('recipe_title').value = data.data.translated_data.title;
            }
            if (data.data.translated_data.ingredients) {
                document.getElementById('recipe_ingredients').value = data.data.translated_data.ingredients;
            }
            if (data.data.translated_data.method) {
                // Auto-format method: split on periods to create line breaks
                let method = data.data.translated_data.method;
                method = method.replace(/\.\s+/g, '.\n');
                document.getElementById('recipe_method').value = method.trim();
            }
            
            statusDiv.className = 'upload-status success';
            statusDiv.textContent = '✅ Recipe translated to English!';
            
            // Remove translate button
            translateBtn.remove();
        } else {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Translation failed: ' + (data.data ? data.data.message : 'Unknown error');
            translateBtn.disabled = false;
            translateBtn.textContent = '🌐 Translate to English';
        }
    })
    .catch(error => {
        statusDiv.className = 'upload-status error';
        statusDiv.textContent = '❌ Translation failed: ' + error.message;
        translateBtn.disabled = false;
        translateBtn.textContent = '🌐 Translate to English';
    });
}

<?php if ($is_editing): ?>
// When editing existing recipe, show translate button if fields are populated
document.addEventListener('DOMContentLoaded', function() {
    const title = document.getElementById('recipe_title').value;
    const ingredients = document.getElementById('recipe_ingredients').value;
    const method = document.getElementById('recipe_method').value;
    
    // Only show if there's actual content to translate
    if (title || ingredients || method) {
        // Create a status div for edit mode if it doesn't exist
        if (!document.getElementById('editTranslateStatus')) {
            const statusDiv = document.createElement('div');
            statusDiv.id = 'editTranslateStatus';
            statusDiv.className = 'upload-status';
            statusDiv.style.display = 'none';
            
            // Insert before the form
            const form = document.getElementById('recipeForm');
            form.parentNode.insertBefore(statusDiv, form);
        }
        
        // Show translate button for editing
        const formActions = document.querySelector('.form-actions');
        if (formActions && !document.getElementById('translateBtn')) {
            const translateBtn = document.createElement('button');
            translateBtn.type = 'button';
            translateBtn.id = 'translateBtn';
            translateBtn.className = 'btn btn-secondary';
            translateBtn.textContent = '🌐 Translate to English';
            translateBtn.onclick = function() {
                // Use the edit translate status div
                const statusDiv = document.getElementById('editTranslateStatus');
                statusDiv.style.display = 'block';
                translateRecipe();
            };
            
            // Insert before the first button
            formActions.insertBefore(translateBtn, formActions.firstChild);
        }
    }
});
<?php endif; ?>
</script>

<?php get_footer(); ?>