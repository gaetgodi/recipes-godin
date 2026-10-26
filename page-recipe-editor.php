<?php
/**
 * Template Name: Recipe Editor
 *
 * Frontend recipe add/edit interface with featured image OCR and text import
 *
 * @version 2.3.0
 * @changelog
 *   2.3.0 - Translation is form-only and always starts from the original. The original
 *            field values (title, ingredients, method, notes, servings) are kept in JS;
 *            translating back to original_lang restores them with no API call. Hidden
 *            original_lang/view_lang inputs; normal Save writes _recipe_original_lang.
 *            While a translation is shown, Save is replaced by "Save as new recipe",
 *            which inserts a new recipe owned by the current user with the translated
 *            fields as its original and _recipe_translated_from = source ID (shown as a
 *            "Translated from #ID" link). Imports fill a real source-language NOTES
 *            section; the raw extraction is shown only in a collapsible preview.
 *   2.2.1 - delete_recipe AJAX handler moved to recipe-image-upload-handler.php (this file
 *            only loads when the editor page renders, so admin-ajax.php never had the handler
 *            and returned "0"). Delete button now reads the JSON reply and alerts on failure
 *            instead of always redirecting.
 *   2.2.0 - Cancel/Back link, Delete redirect, and save-success redirect now preserve
 *            food_cat/author_cat/search state from the two-group filter system,
 *            instead of the old single recipe_cat/from_cat parameter.
 *   2.1.2 - Edit mode translate button and language dropdown now use upload-btn
 *            (red) class for visibility, matching creation mode.
 *   2.1.1 - Both translate JS functions skip machine-generated notes.
 *   2.1.0 - Show current categories above editor form when editing a recipe.
 *   2.0.0 - Added "Interpret recipe" checkbox to both image and text import sections.
 *            When checked, passes interpretation_mode=1 to the extraction AJAX handlers,
 *            enabling gap-filling with [inferred] tagging via Claude API.
 *   1.0.0 - Initial release.
 */

// Include the image upload handler (also registers the delete_recipe AJAX handler)
require_once(get_stylesheet_directory() . '/recipe-image-upload-handler.php');

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

// Gallery photos can only exist for an already-saved recipe
$gallery_photos = $is_editing ? get_recipe_photos($recipe_id) : array();

// Carry filter/search state from the incoming URL, for Cancel/Back, Delete, and save redirects
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
    $recipe_servings = get_post_meta($recipe_id, '_recipe_servings', true);
    
    $recipe_ingredients = html_to_plain_text($recipe_ingredients, false);
    $recipe_method = html_to_plain_text($recipe_method, true);
    $recipe_notes = html_to_plain_text($recipe_notes, false);

    // The stored recipe is always the original; translation only ever happens in the form
    $recipe_original_lang = get_post_meta($recipe_id, '_recipe_original_lang', true);
    $recipe_view_lang = $recipe_original_lang;
    $recipe_original_fields = array(
        'title' => $recipe_title,
        'ingredients' => $recipe_ingredients,
        'method' => $recipe_method,
        'notes' => $recipe_notes,
        'servings' => $recipe_servings,
    );

    // Recipe saved via "Save as new recipe" from a translated view of another one
    $translated_from_post = null;
    $translated_from_id = intval(get_post_meta($recipe_id, '_recipe_translated_from', true));
    if ($translated_from_id > 0) {
        $translated_from_post = get_post($translated_from_id);
        if (!$translated_from_post || $translated_from_post->post_type !== 'recipe' || $translated_from_post->post_status === 'trash') {
            $translated_from_post = null;
        }
    }

    $recipe_categories_objs = get_recipe_categories($recipe_id);
    $recipe_category = array_map(function($cat) { return $cat->cat_id; }, $recipe_categories_objs);

    // Get featured image if exists
    $featured_image_id = get_post_thumbnail_id($recipe_id);
    $featured_image_url = $featured_image_id ? wp_get_attachment_url($featured_image_id) : '';

    // Allergen Checker v2: products already attached to this recipe
    $attached_products_objs = get_recipe_products($recipe_id);
    $attached_product_ids = array_map(function($product) { return $product->product_id; }, $attached_products_objs);
} else {
    $recipe_title = '';
    $recipe_ingredients = '';
    $recipe_method = '';
    $recipe_notes = '';
    $recipe_servings = '';
    $recipe_category = array();
    $featured_image_id = 0;
    $featured_image_url = '';
    $attached_product_ids = array();
    $recipe_original_lang = '';
    $recipe_view_lang = '';
    $recipe_original_fields = null;
    $translated_from_post = null;
}

// Handle form submission. "Save as new recipe" is the only save offered while the
// form shows a translation, so the stored original is never overwritten by one.
$is_save_as_new = isset($_POST['save_as_new_recipe']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_recipe']) || $is_save_as_new)) {
    check_admin_referer('save_recipe_' . $recipe_id);

    // The stored language wins; the form's value only fills it in when unknown
    // (hand-typed recipe whose language was detected on first translation)
    $posted_original_lang = isset($_POST['original_lang']) ? normalize_recipe_language($_POST['original_lang']) : '';
    $view_lang = isset($_POST['view_lang']) ? normalize_recipe_language($_POST['view_lang']) : '';
    $stored_original_lang = $is_editing ? get_post_meta($recipe_id, '_recipe_original_lang', true) : '';
    $original_lang = $stored_original_lang !== '' ? $stored_original_lang : $posted_original_lang;
    $is_translated_view = ($view_lang !== '' && ($original_lang === '' || strcasecmp($view_lang, $original_lang) !== 0));

    $title = sanitize_text_field($_POST['recipe_title']);
    $ingredients = sanitize_textarea_field($_POST['recipe_ingredients']);
    $method = sanitize_textarea_field($_POST['recipe_method']);
    $notes = sanitize_textarea_field($_POST['recipe_notes']);
    $servings = isset($_POST['recipe_servings']) ? sanitize_text_field($_POST['recipe_servings']) : '';
    $categories = isset($_POST['recipe_categories']) ? array_map('intval', $_POST['recipe_categories']) : array();
    $new_featured_image_id = isset($_POST['featured_image_id']) ? intval($_POST['featured_image_id']) : 0;
    $submitted_product_ids = isset($_POST['attached_product_ids']) ? array_map('intval', $_POST['attached_product_ids']) : array();
    
    // Shared with the CLI bulk importer — see format_recipe_content_html() in custom-category-functions.php
    $ingredients = format_recipe_content_html($ingredients, false);
    $method = format_recipe_content_html($method, true);
    $notes = format_recipe_notes_html($notes);
    
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
    if ($is_save_as_new && !$is_translated_view) {
        $errors[] = '"Save as new recipe" is only available while the recipe is shown in a translated language.';
    } elseif (!$is_save_as_new && $is_translated_view) {
        $errors[] = 'This recipe is shown translated into ' . $view_lang . ($original_lang !== '' ? ', but it was written in ' . $original_lang : '') . '. Use "Save as new recipe", or translate it back to its original language before saving.';
    }

    if (empty($errors)) {
        $post_data = array(
            'post_title' => $title,
            'post_type' => 'recipe',
            'post_status' => 'publish',
        );

        if ($is_save_as_new) {
            // Always a new post owned by the current user, even when editing someone
            // else's recipe via collection-editor access
            $post_data['post_author'] = get_current_user_id();
            $saved_id = wp_insert_post($post_data);

            // Category IDs in the form belong to the source recipe's owner; map them
            // by name onto the current user's own categories, as copy_recipe_to_user() does
            if ($is_editing) {
                $own_categories = array();
                foreach ($categories as $cat_id) {
                    $cat = get_category_by_id($cat_id);
                    if (!$cat) {
                        continue;
                    }
                    $own_categories[] = ($cat->user_id == get_current_user_id())
                        ? (int) $cat->cat_id
                        : get_or_create_user_category(get_current_user_id(), $cat->cat_name, $cat->category_type);
                }
                $categories = array_values(array_unique(array_filter($own_categories)));
            }
        } elseif ($is_editing) {
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
            update_post_meta($saved_id, '_recipe_servings', $servings);

            // The saved fields are this recipe's original, in the language they're shown in
            $saved_lang = $is_save_as_new ? $view_lang : $original_lang;
            if ($saved_lang !== '') {
                update_post_meta($saved_id, '_recipe_original_lang', $saved_lang);
            }
            if ($is_save_as_new && $is_editing) {
                update_post_meta($saved_id, '_recipe_translated_from', $recipe_id);
            }

            // Set featured image if one was uploaded
            if ($new_featured_image_id > 0) {
                set_post_thumbnail($saved_id, $new_featured_image_id);

                // Also update the attachment's post parent to link it to this recipe.
                // Not for a translated copy: the image is shared with the source recipe,
                // which keeps it as its own.
                if (!($is_save_as_new && $is_editing)) {
                    wp_update_post(array(
                        'ID' => $new_featured_image_id,
                        'post_parent' => $saved_id
                    ));
                }
            }
            
            if (!empty($categories)) {
                set_recipe_categories($saved_id, $categories);
            } else {
                set_recipe_categories($saved_id, array());
            }

            set_recipe_products($saved_id, $submitted_product_ids);

            // Dietary classification runs here, after set_recipe_categories() above,
            // using the ingredients just saved to _recipe_ingredients. A save_post
            // hook would run too early (inside wp_update_post()/wp_insert_post(),
            // before _recipe_ingredients and categories are updated) and would have
            // its changes overwritten by the set_recipe_categories() call above.
            $dietary_classification = classify_recipe_dietary($ingredients);

            if ($dietary_classification !== null) {
                $saved_recipe_author_id = get_post_field('post_author', $saved_id);

                $dietary_labels = array(
                    'vegan' => 'Vegan',
                    'vegetarian' => 'Vegetarian',
                    'gluten_free' => 'Gluten-Free',
                );

                $updated_cat_ids = wp_list_pluck(get_recipe_categories($saved_id), 'cat_id');

                foreach ($dietary_labels as $key => $cat_name) {
                    if (!$dietary_classification[$key]) {
                        continue;
                    }

                    $existing_cat = get_user_category_by_name($saved_recipe_author_id, $cat_name);

                    if ($existing_cat) {
                        $cat_id = $existing_cat->cat_id;
                    } else {
                        $result = create_user_category($saved_recipe_author_id, $cat_name, 'food');
                        $cat_id = isset($result['cat_id']) ? $result['cat_id'] : null;
                    }

                    if ($cat_id && !in_array($cat_id, $updated_cat_ids)) {
                        $updated_cat_ids[] = $cat_id;
                    }
                }

                set_recipe_categories($saved_id, array_values($updated_cat_ids));
            }

            // Servings estimation: add-only, same principle as the dietary
            // classification above. Only runs when the field is currently
            // empty, so it never overwrites a manually entered value or a
            // prior estimate the user has since edited.
            $current_servings = get_post_meta($saved_id, '_recipe_servings', true);
            if (empty($current_servings)) {
                $estimated_servings = estimate_recipe_servings($ingredients, $method);
                if ($estimated_servings !== null) {
                    update_post_meta($saved_id, '_recipe_servings', $estimated_servings);
                }
            }

            $redirect_url = home_url('/recipe-manager/?saved=1' . $state_query);
            
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
        $recipe_servings = $servings;
        $recipe_category = $categories;

        // Keep showing the posted view language. When editing, the stored recipe
        // is still the in-memory original; a new recipe has no stored original, so
        // the posted fields become the original, in the language they're shown in.
        $recipe_view_lang = $view_lang;
        if ($is_editing) {
            $recipe_original_lang = $original_lang;
        } else {
            $recipe_original_lang = $view_lang !== '' ? $view_lang : $original_lang;
        }
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
    
    <?php if ($translated_from_post): ?>
    <div style="background: #f0f8ff; border: 1px solid #2271b1; border-radius: 4px; padding: 10px 15px; margin: 20px 0; font-size: 14px; color: #333;">
        🌐 <a href="<?php echo esc_url(home_url('/recipe-view-page/?ids=' . $translated_from_post->ID)); ?>" title="<?php echo esc_attr($translated_from_post->post_title); ?>">Translated from #<?php echo intval($translated_from_post->ID); ?></a>
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
                    <strong>Maximum file size:</strong> 20 MB
                </p>
                
                <input type="file" id="recipeImageFile" accept="image/*" style="display: none;" />
                <button type="button" class="upload-btn" id="uploadImageBtn" onclick="document.getElementById('recipeImageFile').click();">
                    Choose Image
                </button>
                <button type="button" class="upload-btn" id="extractBtn" style="display: none; margin-left: 10px;">
                    Upload & Extract Recipe
                </button>

                <!-- Interpretation mode toggle — image -->
                <div id="imageInterpretToggle" style="display: none; margin-top: 12px;">
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: #444;">
                        <input type="checkbox" id="imageInterpretMode" style="width: 16px; height: 16px;" />
                        <span>🧠 <strong>Interpret recipe</strong> — fill gaps using culinary reasoning, tag inferred content with <code>[inferred]</code></span>
                    </label>
                    <p style="font-size: 12px; color: #888; margin: 4px 0 0 24px;">
                        Use for incomplete, handwritten, or non-Latin script recipes. Leave unchecked for strict extraction.
                    </p>
                </div>
                
                <div id="imagePreview" class="image-preview" style="display: none;">
                    <img id="previewImg" src="" alt="Recipe image preview" />
                </div>
                
                <div id="uploadStatus" class="upload-status" style="display: none;"></div>

                <details id="imageRawPreview" class="raw-preview" style="display: none;">
                    <summary>Show raw extraction</summary>
                    <pre></pre>
                </details>
                
                <?php if ($featured_image_url): ?>
                <div class="image-preview" style="display: block;">
                    <img src="<?php echo esc_url($featured_image_url); ?>" alt="Current featured image" />
                    <p style="font-size: 13px; color: #666; margin-top: 10px;">Current featured image</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Photo Gallery Section -->
            <div class="gallery-section" style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">
                <h3>Photo Gallery</h3>
                <?php if ($is_editing): ?>
                <p>Up to 10 extra photos, shown in the order you add them (separate from the featured image above).</p>
                <p style="font-size: 13px; color: #666; margin-top: 5px;">
                    <strong>Accepted formats:</strong> JPG, PNG, GIF, WebP<br>
                    <strong>Maximum file size:</strong> 5 MB each
                </p>

                <input type="file" id="galleryFiles" accept="image/*" multiple style="display: none;" />
                <button type="button" class="upload-btn" id="chooseGalleryBtn" onclick="document.getElementById('galleryFiles').click();" <?php echo (count($gallery_photos) >= 10) ? 'disabled' : ''; ?>>
                    Choose Photos
                </button>
                <span id="galleryCounter" style="margin-left: 10px; color: #666;">
                    <?php echo count($gallery_photos); ?> / 10
                </span>

                <div id="galleryStatus" class="upload-status" style="display: none; margin-top: 10px;"></div>

                <div id="galleryThumbnails" class="gallery-thumb-grid">
                    <?php foreach ($gallery_photos as $photo): ?>
                    <div class="gallery-thumb" data-photo-id="<?php echo intval($photo->photo_id); ?>">
                        <img src="<?php echo esc_url($photo->photo_url); ?>" alt="Recipe gallery photo" />
                        <button type="button" class="gallery-thumb-delete" onclick="deleteGalleryPhoto(<?php echo intval($photo->photo_id); ?>, this)" title="Remove photo">x</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color: #888;">Save the recipe first, then come back here to add gallery photos.</p>
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

                <!-- Interpretation mode toggle — text -->
                <div style="margin-bottom: 15px;">
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: #444;">
                        <input type="checkbox" id="textInterpretMode" style="width: 16px; height: 16px;" />
                        <span>🧠 <strong>Interpret recipe</strong> — fill gaps using culinary reasoning, tag inferred content with <code>[inferred]</code></span>
                    </label>
                    <p style="font-size: 12px; color: #888; margin: 4px 0 0 24px;">
                        Use for incomplete or non-Latin script recipes. Leave unchecked for strict extraction.
                    </p>
                </div>
                
                <button type="button" class="upload-btn" id="extractTextBtn" onclick="extractFromText()">
                    🔍 Extract Recipe from Text
                </button>
                
                <div id="textStatus" class="upload-status" style="display: none; margin-top: 15px;"></div>

                <details id="textRawPreview" class="raw-preview" style="display: none;">
                    <summary>Show raw extraction</summary>
                    <pre></pre>
                </details>
            </div>
        </div>
    </div>
    
    <?php if ($is_editing && !empty($recipe_categories_objs)): ?>
    <div style="background: #f0f8ff; border: 1px solid #2271b1; border-radius: 4px; padding: 10px 15px; margin-bottom: 20px; font-size: 14px; color: #333;">
        <strong>📁 Current categories:</strong>
        <?php echo esc_html(implode(', ', array_map(function($c) { return $c->cat_name; }, $recipe_categories_objs))); ?>
    </div>
    <?php endif; ?>

    <form method="post" class="editor-form" id="recipeForm">
        <?php wp_nonce_field('save_recipe_' . $recipe_id); ?>
        <input type="hidden" name="featured_image_id" id="featuredImageId" value="<?php echo $featured_image_id; ?>" />
        <input type="hidden" name="original_lang" id="originalLang" value="<?php echo esc_attr($recipe_original_lang); ?>" />
        <input type="hidden" name="view_lang" id="viewLang" value="<?php echo esc_attr($recipe_view_lang); ?>" />
        
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
            <label for="recipe_products">
                Attached Products (Optional)
            </label>
            <span class="help-text">Attach real scanned products from the library (e.g. a specific brand of chocolate chips) so the Allergen Checker can check their actual ingredient list instead of guessing from the recipe text</span>

            <input
                type="text"
                id="productSearchInput"
                placeholder="Search products by name..."
                oninput="filterAttachedProductPicker()"
                style="width: 100%; padding: 8px 12px; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;"
            />

            <div id="attachedProductPickerList" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; background: #fafafa; max-height: 300px; overflow-y: auto;">
                <?php
                $all_allergen_products = get_all_products();

                if (empty($all_allergen_products)) {
                    echo '<div style="padding: 20px; text-align: center; color: #666;">';
                    echo '<p style="margin: 0 0 10px 0;"><strong>No products in the library yet!</strong></p>';
                    echo '<p style="margin: 0; font-size: 14px;">Scan a product label to add one.</p>';
                    echo '<p style="margin: 10px 0 0 0;"><a href="' . home_url('/allergen-products/') . '" style="color: #2271b1; text-decoration: underline;">→ Go to Product Library</a></p>';
                    echo '</div>';
                } else {
                    foreach ($all_allergen_products as $product) {
                        $checked = in_array($product->product_id, $attached_product_ids) ? 'checked' : '';
                        echo '<div class="product-picker-item" data-search-text="' . esc_attr(strtolower($product->product_name)) . '" style="margin-bottom: 8px;">';
                        echo '<label style="display: flex; align-items: center; cursor: pointer; font-weight: normal;">';
                        echo '<input type="checkbox" name="attached_product_ids[]" value="' . $product->product_id . '" ' . $checked . ' style="margin-right: 8px; width: 18px; height: 18px;">';
                        echo '<span>' . esc_html($product->product_name) . '</span>';
                        echo '</label>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
            <div class="tip-box" style="margin-top: 10px;">
                <strong>💡 Tip:</strong> Zero products is normal for most recipes — only attach products for ingredients that are themselves packaged (like a specific brand of cake mix), not for plain ingredients like flour or eggs.
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
            <label for="recipe_servings">
                Servings (Optional)
            </label>
            <span class="help-text">e.g. "Serves 4" or "Makes 24 cookies" &mdash; left blank, it will be estimated automatically after you save</span>
            <input
                type="text"
                id="recipe_servings"
                name="recipe_servings"
                value="<?php echo esc_attr($recipe_servings); ?>"
                placeholder="Serves 4"
            />
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
            <button type="submit" name="save_recipe" id="saveRecipeBtn" class="btn btn-primary">
                💾 <?php echo $is_editing ? 'Update Recipe' : 'Save Recipe'; ?>
            </button>
            <!-- Shown instead of the button above while the form shows a translation -->
            <button type="submit" name="save_as_new_recipe" id="saveAsNewBtn" class="btn btn-primary" style="display: none;" disabled>
                💾 Save as new recipe
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
                        }).then(r => r.json()).then(res => {
                            if (res && res.success) {
                                window.location.href = '<?php echo esc_url($back_url); ?>';
                            } else {
                                alert('Could not delete this recipe.');
                            }
                        }).catch(() => alert('Could not delete this recipe.'));
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

.gallery-thumb-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 12px;
    margin-top: 15px;
}

.gallery-thumb {
    position: relative;
    aspect-ratio: 1 / 1;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    background: #f8f8f8;
}

.gallery-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.gallery-thumb-delete {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 24px;
    height: 24px;
    line-height: 22px;
    padding: 0;
    text-align: center;
    background: rgba(0, 0, 0, 0.6);
    color: #fff;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-size: 14px;
}

.gallery-thumb-delete:hover {
    background: rgba(200, 74, 49, 0.9);
}

.gallery-thumb-delete:disabled {
    opacity: 0.5;
    cursor: default;
}

.raw-preview {
    margin-top: 10px;
    font-size: 13px;
    color: #555;
}

.raw-preview summary {
    cursor: pointer;
}

.raw-preview pre {
    white-space: pre-wrap;
    background: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px;
    max-height: 300px;
    overflow-y: auto;
}
</style>

<script src="<?php echo get_stylesheet_directory_uri(); ?>/scan-image-resize.js"></script>
<script>
// Translation is form-only: the stored recipe is always the original. Its field
// values are kept here so every translation starts from them (translations never
// stack), and translating back to the original language restores them with no
// API call. Edits made while the original language is shown update this copy.
const RECIPE_FIELD_IDS = {
    title: 'recipe_title',
    ingredients: 'recipe_ingredients',
    method: 'recipe_method',
    notes: 'recipe_notes',
    servings: 'recipe_servings'
};
let recipeOriginal = <?php echo $recipe_original_fields ? wp_json_encode($recipe_original_fields, JSON_HEX_TAG | JSON_HEX_AMP) : 'null'; ?>;

const RECIPE_LANGUAGE_OPTIONS = `
    <option value="English">🇬🇧 English</option>
    <option value="French">🇫🇷 French</option>
    <option value="Spanish">🇪🇸 Spanish</option>
    <option value="Italian">🇮🇹 Italian</option>
    <option value="German">🇩🇪 German</option>
    <option value="Portuguese">🇵🇹 Portuguese</option>
    <option value="Gujarati">🇮🇳 Gujarati</option>
    <option value="Hindi">🇮🇳 Hindi</option>
    <option value="Mandarin Chinese">🇨🇳 Mandarin</option>
    <option value="Japanese">🇯🇵 Japanese</option>
    <option value="Korean">🇰🇷 Korean</option>
    <option value="Arabic">🇸🇦 Arabic</option>
    <option value="Russian">🇷🇺 Russian</option>
    <option value="Dutch">🇳🇱 Dutch</option>
    <option value="Polish">🇵🇱 Polish</option>
    <option value="Turkish">🇹🇷 Turkish</option>
    <option value="Vietnamese">🇻🇳 Vietnamese</option>
    <option value="Thai">🇹🇭 Thai</option>
`;

function readRecipeFields() {
    const fields = {};
    for (const key in RECIPE_FIELD_IDS) {
        fields[key] = document.getElementById(RECIPE_FIELD_IDS[key]).value;
    }
    return fields;
}

function writeRecipeFields(fields) {
    for (const key in RECIPE_FIELD_IDS) {
        document.getElementById(RECIPE_FIELD_IDS[key]).value = fields[key] || '';
    }
}

function getOriginalLang() {
    return document.getElementById('originalLang').value;
}

function setOriginalLang(lang) {
    document.getElementById('originalLang').value = lang;
}

function getViewLang() {
    return document.getElementById('viewLang').value;
}

function setViewLang(lang) {
    document.getElementById('viewLang').value = lang;
    updateSaveButtons();
}

function sameLang(a, b) {
    return a.toLowerCase() === b.toLowerCase();
}

// No view language yet means nothing has been translated, so the form shows the original
function isViewingOriginal() {
    const viewLang = getViewLang();
    const originalLang = getOriginalLang();
    return viewLang === '' || (originalLang !== '' && sameLang(viewLang, originalLang));
}

// A translated view can only be saved as a new recipe, never over the original
function updateSaveButtons() {
    const viewingOriginal = isViewingOriginal();
    const saveBtn = document.getElementById('saveRecipeBtn');
    const saveAsNewBtn = document.getElementById('saveAsNewBtn');

    saveBtn.style.display = viewingOriginal ? '' : 'none';
    saveBtn.disabled = !viewingOriginal;
    saveAsNewBtn.style.display = viewingOriginal ? 'none' : '';
    saveAsNewBtn.disabled = viewingOriginal;
}

function showRawPreview(previewId, rawText) {
    const preview = document.getElementById(previewId);
    preview.querySelector('pre').textContent = rawText || '';
    preview.style.display = rawText ? 'block' : 'none';
}

// Fill the form from an extraction result. The fields are stored as written, in
// the source language, and become the new in-memory original.
function applyImportedRecipe(extracted, previewId, rawText) {
    if (extracted.title) {
        document.getElementById('recipe_title').value = extracted.title;
    }
    if (extracted.ingredients) {
        document.getElementById('recipe_ingredients').value = extracted.ingredients;
    }
    if (extracted.method) {
        document.getElementById('recipe_method').value = extracted.method;
    }
    if (extracted.servings) {
        document.getElementById('recipe_servings').value = extracted.servings;
    }
    document.getElementById('recipe_notes').value = extracted.notes || '';

    showRawPreview(previewId, rawText);

    recipeOriginal = readRecipeFields();
    setOriginalLang(extracted.language || '');
    setViewLang(extracted.language || '');
}

// Translate the form to targetLang, always from the in-memory original
function translateFromOriginal(targetLang, statusDiv, translateBtn, idleLabel) {
    if (!recipeOriginal) {
        recipeOriginal = readRecipeFields();
    }
    const original = recipeOriginal;
    const originalLang = getOriginalLang();

    statusDiv.style.display = 'block';

    if (originalLang && sameLang(targetLang, originalLang)) {
        writeRecipeFields(original);
        setViewLang(originalLang);
        statusDiv.className = 'upload-status success';
        statusDiv.textContent = '✅ Showing the original recipe (' + originalLang + ').';
        return;
    }

    translateBtn.disabled = true;
    translateBtn.textContent = '🔄 Translating...';

    const formData = new FormData();
    formData.append('action', 'translate_recipe');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_translation'); ?>');
    formData.append('title', original.title);
    formData.append('ingredients', original.ingredients);
    formData.append('method', original.method);
    formData.append('notes', original.notes);
    formData.append('servings', original.servings);
    formData.append('source_language', originalLang);
    formData.append('target_language', targetLang);

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        translateBtn.disabled = false;
        translateBtn.textContent = idleLabel;

        if (!data.success) {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Translation failed: ' + (data.data ? data.data.message : 'Unknown error');
            return;
        }

        const translated = data.data.translated_data;

        // Hand-typed recipe: the server reports the language it was written in
        if (!getOriginalLang() && translated.source_language) {
            setOriginalLang(translated.source_language);
        }

        if (getOriginalLang() && sameLang(targetLang, getOriginalLang())) {
            writeRecipeFields(original);
            setViewLang(getOriginalLang());
            statusDiv.className = 'upload-status success';
            statusDiv.textContent = '✅ This recipe is already in ' + getOriginalLang() + ' — showing the original.';
            return;
        }

        // Never show a mix of languages: every field the original has must come back
        const missing = Object.keys(RECIPE_FIELD_IDS).filter(function(key) {
            return (original[key] || '').trim() !== '' && (translated[key] || '').trim() === '';
        });
        if (missing.length) {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Translation came back incomplete (missing: ' + missing.join(', ') + '). Nothing was changed.';
            return;
        }

        writeRecipeFields({
            title: translated.title,
            ingredients: translated.ingredients,
            method: (translated.method || '').replace(/\.\s+/g, '.\n').trim(),
            notes: translated.notes,
            servings: translated.servings
        });
        setViewLang(targetLang);

        statusDiv.className = 'upload-status success';
        statusDiv.textContent = '✅ Recipe translated to ' + targetLang + (getOriginalLang() ? ' from the ' + getOriginalLang() + ' original' : '') + '. Save it as a new recipe to keep this translation.';
    })
    .catch(error => {
        statusDiv.className = 'upload-status error';
        statusDiv.textContent = '❌ Translation failed: ' + error.message;
        translateBtn.disabled = false;
        translateBtn.textContent = idleLabel;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (!recipeOriginal) {
        recipeOriginal = readRecipeFields();
    }
    for (const key in RECIPE_FIELD_IDS) {
        document.getElementById(RECIPE_FIELD_IDS[key]).addEventListener('input', function() {
            if (isViewingOriginal()) {
                recipeOriginal = readRecipeFields();
            }
        });
    }
    updateSaveButtons();
});

// Allergen Checker v2: filter the attached-products checklist by search text
function filterAttachedProductPicker() {
    const searchTerm = document.getElementById('productSearchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('#attachedProductPickerList .product-picker-item');

    items.forEach(function(item) {
        const text = item.getAttribute('data-search-text') || '';
        item.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

// Tab switching
function switchTab(tab) {
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

document.getElementById('recipeImageFile').addEventListener('change', async function(e) {
    const file = e.target.files[0];
    if (!file) return;

    selectedFile = null;
    document.getElementById('extractBtn').style.display = 'none';

    // Type and 20 MB limit are checked on the original, before any resizing
    const validationError = validateScanFile(file);
    if (validationError) {
        alert(validationError);
        this.value = '';
        return;
    }

    // Downsize to 2000 px (JPEG) in the browser before upload
    try {
        selectedFile = await prepareScanFile(file);
    } catch (error) {
        alert(error.message);
        this.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('imagePreview').style.display = 'block';
        document.getElementById('extractBtn').style.display = 'inline-block';
        document.getElementById('imageInterpretToggle').style.display = 'block';
        document.getElementById('uploadStatus').style.display = 'none';
    };
    reader.readAsDataURL(selectedFile);
});

document.getElementById('extractBtn').addEventListener('click', function() {
    if (!selectedFile) {
        alert('Please select an image first');
        return;
    }
    
    const interpretMode = document.getElementById('imageInterpretMode').checked ? '1' : '0';

    const statusDiv = document.getElementById('uploadStatus');
    statusDiv.className = 'upload-status processing';
    statusDiv.textContent = interpretMode === '1'
        ? '🔄 Uploading image and interpreting recipe...'
        : '🔄 Uploading image and extracting recipe data...';
    statusDiv.style.display = 'block';
    
    this.disabled = true;
    document.getElementById('uploadImageBtn').disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'upload_recipe_image');
    formData.append('recipe_id', '<?php echo $recipe_id; ?>');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_image_upload'); ?>');
    formData.append('recipe_image', selectedFile);
    formData.append('interpretation_mode', interpretMode);
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Full API Response:', data);
        
        if (data.success) {
            statusDiv.className = 'upload-status success';
            
            if (data.data.extracted_data.found === false) {
                statusDiv.textContent = '✅ Image uploaded! No recipe data found in image.';
                document.getElementById('recipe_notes').value = 'No recipe interpreted in featured image';
            } else {
                statusDiv.textContent = interpretMode === '1'
                    ? '✅ Image uploaded and recipe interpreted! Content tagged [inferred] was added by AI — review carefully.'
                    : '✅ Image uploaded and recipe extracted! Check the fields below.';
                
                applyImportedRecipe(data.data.extracted_data, 'imageRawPreview', data.data.raw_response);

                showTranslateButton();
            }

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

// Photo Gallery upload/delete handling
const GALLERY_MAX = 10;
const galleryFilesInput = document.getElementById('galleryFiles');

if (galleryFilesInput) {
    galleryFilesInput.addEventListener('change', function(e) {
        const files = Array.from(e.target.files);
        if (files.length === 0) return;

        uploadGalleryPhotos(files);
    });
}

function setGalleryCount(count) {
    document.getElementById('galleryCounter').textContent = count + ' / ' + GALLERY_MAX;
    document.getElementById('chooseGalleryBtn').disabled = (count >= GALLERY_MAX);
}

async function uploadGalleryPhotos(files) {
    const statusDiv = document.getElementById('galleryStatus');
    const chooseBtn = document.getElementById('chooseGalleryBtn');
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    const maxSize = 5 * 1024 * 1024;

    chooseBtn.disabled = true;
    statusDiv.className = 'upload-status processing';
    statusDiv.style.display = 'block';

    let uploaded = 0;
    let stopped = false;

    for (const file of files) {
        if (stopped) break;

        if (!validTypes.includes(file.type)) {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Skipped "' + file.name + '": not a valid image file (JPG, PNG, GIF, or WebP)';
            continue;
        }

        if (file.size > maxSize) {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Skipped "' + file.name + '": too large (max 5 MB)';
            continue;
        }

        statusDiv.className = 'upload-status processing';
        statusDiv.textContent = '🔄 Uploading ' + file.name + ' (' + (uploaded + 1) + ' of ' + files.length + ')...';

        const formData = new FormData();
        formData.append('action', 'upload_recipe_gallery_photo');
        formData.append('recipe_id', '<?php echo $recipe_id; ?>');
        formData.append('nonce', '<?php echo wp_create_nonce('recipe_gallery_upload'); ?>');
        formData.append('gallery_image', file);

        try {
            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                addGalleryThumbnail(data.data.photo_id, data.data.photo_url);
                setGalleryCount(data.data.count);
                uploaded++;

                if (data.data.count >= GALLERY_MAX) {
                    stopped = true;
                }
            } else {
                statusDiv.className = 'upload-status error';
                statusDiv.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Upload failed');
                if (data.data && data.data.count !== undefined) {
                    setGalleryCount(data.data.count);
                }
                if (data.data && data.data.count >= GALLERY_MAX) {
                    stopped = true;
                }
            }
        } catch (error) {
            statusDiv.className = 'upload-status error';
            statusDiv.textContent = '❌ Upload failed: ' + error.message;
        }
    }

    if (!stopped && statusDiv.className !== 'upload-status error') {
        statusDiv.className = 'upload-status success';
        statusDiv.textContent = '✅ ' + uploaded + ' photo(s) uploaded';
    }

    galleryFilesInput.value = '';
    const currentCount = document.getElementById('galleryThumbnails').children.length;
    chooseBtn.disabled = (currentCount >= GALLERY_MAX);
}

function addGalleryThumbnail(photoId, photoUrl) {
    const grid = document.getElementById('galleryThumbnails');
    const thumb = document.createElement('div');
    thumb.className = 'gallery-thumb';
    thumb.setAttribute('data-photo-id', photoId);
    thumb.innerHTML = '<img src="' + photoUrl + '" alt="Recipe gallery photo" />' +
        '<button type="button" class="gallery-thumb-delete" onclick="deleteGalleryPhoto(' + photoId + ', this)" title="Remove photo">x</button>';
    grid.appendChild(thumb);
}

function deleteGalleryPhoto(photoId, buttonEl) {
    if (!confirm('Remove this photo?')) return;

    const statusDiv = document.getElementById('galleryStatus');
    const chooseBtn = document.getElementById('chooseGalleryBtn');

    buttonEl.disabled = true;

    const formData = new FormData();
    formData.append('action', 'delete_recipe_gallery_photo');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_gallery_delete'); ?>');
    formData.append('photo_id', photoId);

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            buttonEl.closest('.gallery-thumb').remove();
            setGalleryCount(data.data.count);
            statusDiv.style.display = 'none';
        } else {
            buttonEl.disabled = false;
            statusDiv.className = 'upload-status error';
            statusDiv.style.display = 'block';
            statusDiv.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Delete failed');
        }
    })
    .catch(error => {
        buttonEl.disabled = false;
        statusDiv.className = 'upload-status error';
        statusDiv.style.display = 'block';
        statusDiv.textContent = '❌ Delete failed: ' + error.message;
    });
}

// Text file upload handling
document.getElementById('recipeTextFile').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    document.getElementById('textFileName').textContent = file.name;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('pastedRecipeText').value = e.target.result;
    };
    reader.readAsText(file);
});

// Extract recipe from text (file or pasted)
function extractFromText() {
    const textContent = document.getElementById('pastedRecipeText').value.trim();
    
    if (!textContent) {
        alert('Please paste recipe text or upload a text file first');
        return;
    }
    
    const interpretMode = document.getElementById('textInterpretMode').checked ? '1' : '0';

    const statusDiv = document.getElementById('textStatus');
    const extractBtn = document.getElementById('extractTextBtn');
    
    statusDiv.className = 'upload-status processing';
    statusDiv.textContent = interpretMode === '1'
        ? '🔄 Interpreting recipe from text...'
        : '🔄 Extracting recipe from text...';
    statusDiv.style.display = 'block';
    extractBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'extract_recipe_from_text');
    formData.append('nonce', '<?php echo wp_create_nonce('recipe_text_extract'); ?>');
    formData.append('text_content', textContent);
    formData.append('interpretation_mode', interpretMode);
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            applyImportedRecipe(data.data.extracted_data, 'textRawPreview', data.data.raw_response);
            
            statusDiv.className = 'upload-status success';
            statusDiv.textContent = interpretMode === '1'
                ? '✅ Recipe interpreted! Content tagged [inferred] was added by AI — review carefully.'
                : '✅ Recipe extracted from text! Check the fields below.';


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

// Show translate button with language selector after successful extraction
function showTranslateButton() {
    if (document.getElementById('translateContainer')) return;
    
    const imageStatus = document.getElementById('uploadStatus');
    const textStatus = document.getElementById('textStatus');
    const statusDiv = imageStatus.style.display !== 'none' ? imageStatus : textStatus;
    
    const translateContainer = document.createElement('div');
    translateContainer.id = 'translateContainer';
    translateContainer.style.marginTop = '10px';
    translateContainer.style.display = 'flex';
    translateContainer.style.gap = '10px';
    translateContainer.style.alignItems = 'center';
    
    const langSelect = document.createElement('select');
    langSelect.id = 'targetLanguage';
    langSelect.className = 'upload-btn';
    langSelect.style.padding = '8px 12px';
    langSelect.innerHTML = RECIPE_LANGUAGE_OPTIONS;
    
    const translateBtn = document.createElement('button');
    translateBtn.type = 'button';
    translateBtn.id = 'translateBtn';
    translateBtn.className = 'upload-btn';
    translateBtn.textContent = '🌐 Translate';
    translateBtn.onclick = translateRecipe;
    
    translateContainer.appendChild(langSelect);
    translateContainer.appendChild(translateBtn);
    
    statusDiv.parentNode.insertBefore(translateContainer, statusDiv.nextSibling);
}

// Translate the extracted recipe to selected language. The selector stays in
// place afterwards so the recipe can be translated again or back to the original.
function translateRecipe() {
    const imageStatus = document.getElementById('uploadStatus');
    const textStatus = document.getElementById('textStatus');
    const statusDiv = imageStatus.style.display !== 'none' ? imageStatus : textStatus;

    translateFromOriginal(
        document.getElementById('targetLanguage').value,
        statusDiv,
        document.getElementById('translateBtn'),
        '🌐 Translate'
    );
}

<?php if ($is_editing): ?>
// When editing existing recipe, show translate button if fields are populated
document.addEventListener('DOMContentLoaded', function() {
    const title = document.getElementById('recipe_title').value;
    const ingredients = document.getElementById('recipe_ingredients').value;
    const method = document.getElementById('recipe_method').value;
    
    if (title || ingredients || method) {
        if (!document.getElementById('editTranslateStatus')) {
            const statusDiv = document.createElement('div');
            statusDiv.id = 'editTranslateStatus';
            statusDiv.className = 'upload-status';
            statusDiv.style.display = 'none';
            
            const form = document.getElementById('recipeForm');
            form.parentNode.insertBefore(statusDiv, form);
        }
        
        const formActions = document.querySelector('.form-actions');
        if (formActions && !document.getElementById('editTranslateContainer')) {
            const translateContainer = document.createElement('div');
            translateContainer.id = 'editTranslateContainer';
            translateContainer.style.display = 'flex';
            translateContainer.style.gap = '10px';
            translateContainer.style.alignItems = 'center';
            translateContainer.style.marginBottom = '10px';
            
            const langSelect = document.createElement('select');
            langSelect.id = 'editTargetLanguage';
            langSelect.className = 'upload-btn';
            langSelect.style.padding = '8px 12px';
            langSelect.innerHTML = RECIPE_LANGUAGE_OPTIONS;
            
            const translateBtn = document.createElement('button');
            translateBtn.type = 'button';
            translateBtn.className = 'upload-btn';
            translateBtn.textContent = '🌐 Translate Recipe';
            translateBtn.onclick = function() {
                translateFromOriginal(
                    langSelect.value,
                    document.getElementById('editTranslateStatus'),
                    translateBtn,
                    '🌐 Translate Recipe'
                );
            };
            
            translateContainer.appendChild(langSelect);
            translateContainer.appendChild(translateBtn);
            formActions.parentNode.insertBefore(translateContainer, formActions);
        }
    }
});
<?php endif; ?>
</script>

<?php get_footer(); ?>