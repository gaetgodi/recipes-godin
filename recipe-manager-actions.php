<?php
/**
 * Recipe Manager Actions Handler
 * 
 * Handles all GET and POST actions for recipe management
 */

// Security check - must be included from WordPress
if (!defined('ABSPATH')) {
    exit;
}

 
// Handle GET actions (like copy_recipe from links)
if (isset($_GET['action'])) {
    $action = sanitize_text_field($_GET['action']);
    
    if ($action === 'copy_recipe' && !empty($_GET['recipe_id'])) {
        require_once(get_stylesheet_directory() . '/collection-permissions.php');
        $result = copy_recipe_to_my_collection(intval($_GET['recipe_id']), get_current_user_id());
        
        if (isset($result['success'])) {
            // Copy featured image
            copy_recipe_featured_image(intval($_GET['recipe_id']), $result['recipe_id']);
            
            // Success - redirect to editor with promotion flag if needed
            $redirect_url = home_url('/recipe-editor/?id=' . $result['recipe_id'] . '&copied=1');
            if (isset($result['promoted']) && $result['promoted']) {
                $redirect_url .= '&promoted=1';
            }
            wp_redirect($redirect_url);
            exit;
        } else {
            // Error - show message (set variable to display later)
            $copy_error = $result['error'];
        }
    }
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && !empty($_POST['selected_recipes'])) {
    $selected_ids = array_map('intval', $_POST['selected_recipes']);
    $action = sanitize_text_field($_POST['bulk_action']);
    
    switch ($action) {
        case 'delete':
            if (current_user_can('edit_posts')) {
                $is_admin = current_user_can('administrator');
                
                foreach ($selected_ids as $post_id) {
                    // Administrators can delete any recipe, others only their own
                    if ($is_admin || get_post_field('post_author', $post_id) == get_current_user_id()) {
                        // Delete category relationships first (custom table cleanup)
                        global $wpdb;
                        $wpdb->delete(
                            $wpdb->prefix . 'recipe_category_relationships',
                            array('recipe_id' => $post_id),
                            array('%d')
                        );
                        
                        // Delete the recipe post (WordPress will auto-delete attached featured image)
                        wp_delete_post($post_id, true);
                    }
                }
                
                // Clear WordPress object cache to refresh category counts
                wp_cache_flush();
                
                // Redirect back with category filter and collection
                $redirect_url = home_url('/recipe-manager/?deleted=1');
                if (!empty($_GET['recipe_cat'])) {
                    $redirect_url .= '&recipe_cat=' . intval($_GET['recipe_cat']);
                }
                if (!empty($_GET['collection'])) {
                    $redirect_url .= '&collection=' . intval($_GET['collection']);
                }
                wp_redirect($redirect_url);
                exit;
            } else {
                echo '<div style="background: #f8d7da; padding: 15px; margin: 20px; border: 1px solid #f5c6cb; color: #721c24;">You do not have permission to delete recipes.</div>';
            }
            break;
            
        case 'view':
            // Everyone can view
            set_transient('recipe_view_' . get_current_user_id(), $selected_ids, 300);
            
            // Build redirect URL with recipe IDs and category
            $redirect_url = home_url('/recipe-view-page/?ids=' . implode(',', $selected_ids));
            if (!empty($_GET['recipe_cat'])) {
                $redirect_url .= '&recipe_cat=' . intval($_GET['recipe_cat']);
            }
            
            wp_redirect($redirect_url);
            exit;
            break;
            
        case 'print':
            // Everyone can print
            set_transient('recipe_print_' . get_current_user_id(), $selected_ids, 300);
            
            // Build redirect URL with recipe IDs and category
            $redirect_url = home_url('/recipe-print-page/?ids=' . implode(',', $selected_ids));
            if (!empty($_GET['recipe_cat'])) {
                $redirect_url .= '&recipe_cat=' . intval($_GET['recipe_cat']);
            }
            
            wp_redirect($redirect_url);
            exit;
            break;
            
        case 'copy_to_my_recipes':
            // Bulk copy recipes to viewer's own collection
            require_once(get_stylesheet_directory() . '/collection-permissions.php');
            
            $copied_count = 0;
            $skipped_count = 0;
            $error_messages = array();
            
            foreach ($selected_ids as $recipe_id) {
                $result = copy_recipe_to_my_collection($recipe_id, get_current_user_id());
                
                if (isset($result['success'])) {
                    // Copy featured image
                    copy_recipe_featured_image($recipe_id, $result['recipe_id']);
                    $copied_count++;
                } elseif (isset($result['error'])) {
                    $skipped_count++;
                }
            }
            
            // Build redirect message
            $redirect_url = home_url('/recipe-manager/?collection=' . get_current_user_id());
            if ($copied_count > 0) {
                $redirect_url .= '&bulk_copied=' . $copied_count;
            }
            if ($skipped_count > 0) {
                $redirect_url .= '&bulk_skipped=' . $skipped_count;
            }
            
            wp_redirect($redirect_url);
            exit;
            break;
            
        case 'copy':
            if (!empty($selected_ids) && current_user_can('edit_posts')) {
                $original_id = $selected_ids[0]; // Copy first selected
                $original = get_post($original_id);
                
                if ($original && $original->post_author == get_current_user_id()) {
                    $new_post = array(
                        'post_title' => $original->post_title . ' (Copy)',
                        'post_type' => 'recipe',
                        'post_status' => 'draft',
                        'post_author' => get_current_user_id(),
                    );
                    
                    $new_id = wp_insert_post($new_post);
                    
                    if ($new_id) {
                        // Copy meta
                        update_post_meta($new_id, '_recipe_ingredients', get_post_meta($original_id, '_recipe_ingredients', true));
                        update_post_meta($new_id, '_recipe_method', get_post_meta($original_id, '_recipe_method', true));
                        update_post_meta($new_id, '_recipe_notes', get_post_meta($original_id, '_recipe_notes', true));
                        
                        // Copy featured image
                        copy_recipe_featured_image($original_id, $new_id);
                        
                        // Copy categories using custom tables
                        $source_cats = get_recipe_categories($original_id);
                        if (!empty($source_cats)) {
                            $cat_ids = array_map(function($cat) { return $cat->cat_id; }, $source_cats);
                            set_recipe_categories($new_id, $cat_ids);
                        }
                        
                        // Redirect to editor with category filter
                        $redirect_url = home_url('/recipe-editor/?id=' . $new_id . '&copied=1');
                        if (!empty($_GET['recipe_cat'])) {
                            $redirect_url .= '&from_cat=' . intval($_GET['recipe_cat']);
                        }
                        wp_redirect($redirect_url);
                        exit;
                    }
                }
            } else {
                echo '<div style="background: #f8d7da; padding: 15px; margin: 20px; border: 1px solid #f5c6cb; color: #721c24;">You do not have permission to copy recipes.</div>';
            }
            break;
            
        case 'share':
            if (!empty($selected_ids) && current_user_can('edit_posts') && !empty($_POST['share_to_user'])) {
                $recipient_id = intval($_POST['share_to_user']);
                $shared_count = 0;
                
                foreach ($selected_ids as $original_id) {
                    $original = get_post($original_id);
                    
                    // Verify user can manage this recipe (owner, co-editor, or administrator)
                    require_once(get_stylesheet_directory() . '/collection-permissions.php');
                    $is_admin = current_user_can('administrator');
                    $can_share = $is_admin ||
                                 ($original->post_author == get_current_user_id()) || 
                                 user_can_manage_collection(get_current_user_id(), $original->post_author);
                    
                    if ($original && $can_share) {
                        // Auto-promote subscriber to author if needed (Editors already have publish rights)
                        $recipient = get_userdata($recipient_id);
                        if ($recipient && in_array('subscriber', $recipient->roles)) {
                            $recipient->set_role('author');
                        }
                        // Check if recipient already has a recipe with this title
                        global $wpdb;
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} 
                             WHERE post_title = %s 
                             AND post_type = 'recipe' 
                             AND post_author = %d",
                            $original->post_title,
                            $recipient_id
                        ));
                        
                        if ($existing) {
                            // Skip this recipe - recipient already has it
                            continue;
                        }
                        
                        // Create copy in recipient's collection
                        $new_post = array(
                            'post_title' => $original->post_title,
                            'post_type' => 'recipe',
                            'post_status' => 'publish',
                            'post_author' => $recipient_id, // Assign to recipient
                        );
                        
                        $new_id = wp_insert_post($new_post);
                        
                        if ($new_id) {
                            // Copy meta (NOT _recipe_id - we'll generate a new unique one)
                            update_post_meta($new_id, '_recipe_ingredients', get_post_meta($original_id, '_recipe_ingredients', true));
                            update_post_meta($new_id, '_recipe_method', get_post_meta($original_id, '_recipe_method', true));
                            update_post_meta($new_id, '_recipe_notes', get_post_meta($original_id, '_recipe_notes', true));
                            
                            // Copy featured image
                            copy_recipe_featured_image($original_id, $new_id);
                            
                            // Generate new unique recipe ID
                            $recipe_permanent_id = 'R' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
                            update_post_meta($new_id, '_recipe_id', $recipe_permanent_id);
                            
                            // Copy categories - create for recipient if they don't exist
                            $source_categories = get_recipe_categories($original_id);
                            $new_category_ids = array();
                            
                            foreach ($source_categories as $cat) {
                                // Check if recipient already has this category name
                                $existing = get_user_category_by_name($recipient_id, $cat->cat_name);
                                
                                if ($existing) {
                                    // Use existing category
                                    $new_category_ids[] = $existing->cat_id;
                                } else {
                                    // Create new category for recipient
                                    $result = create_user_category($recipient_id, $cat->cat_name);
                                    if (isset($result['cat_id'])) {
                                        $new_category_ids[] = $result['cat_id'];
                                    }
                                }
                            }
                            
                            // Add source author as a category for attribution
                            $source_author_id = $original->post_author;
                            if ($source_author_id != $recipient_id) {
                                $source_author = get_userdata($source_author_id);
                                if ($source_author) {
                                    $author_category_name = $source_author->user_login; // Use username, not display name
                                    
                                    // Check if recipient already has this author category
                                    $existing_author_cat = get_user_category_by_name($recipient_id, $author_category_name);
                                    
                                    if ($existing_author_cat) {
                                        $new_category_ids[] = $existing_author_cat->cat_id;
                                    } else {
                                        // Create author category
                                        $result = create_user_category($recipient_id, $author_category_name);
                                        if (isset($result['cat_id'])) {
                                            $new_category_ids[] = $result['cat_id'];
                                        }
                                    }
                                }
                            }
                            
                            // Assign all categories to new recipe
                            if (!empty($new_category_ids)) {
                                set_recipe_categories($new_id, $new_category_ids);
                            }
                            
                            $shared_count++;
                        }
                    }
                }
                
                // Redirect with success message and preserve collection view
                $redirect_url = home_url('/recipe-manager/?shared=' . $shared_count);
                if (!empty($_GET['collection'])) {
                    $redirect_url .= '&collection=' . intval($_GET['collection']);
                }
                if (!empty($_GET['recipe_cat'])) {
                    $redirect_url .= '&recipe_cat=' . intval($_GET['recipe_cat']);
                }
                wp_redirect($redirect_url);
                exit;
            }
            break;
    }
}