<?php
/**
 * Custom Recipe Category Functions
 * 
 * Helper functions for managing custom recipe categories
 * Replaces WordPress taxonomy functions
 */

/**
 * Get all categories for a specific user
 */
function get_user_categories($user_id, $orderby = 'cat_name', $order = 'ASC') {
    global $wpdb;
    
    $allowed_orderby = array('cat_id', 'cat_name', 'created_date');
    $orderby = in_array($orderby, $allowed_orderby) ? $orderby : 'cat_name';
    
    $allowed_order = array('ASC', 'DESC');
    $order = in_array(strtoupper($order), $allowed_order) ? strtoupper($order) : 'ASC';
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}recipe_categories 
         WHERE user_id = %d 
         ORDER BY {$orderby} {$order}",
        $user_id
    ));
    
    return $results;
}

/**
 * Get a specific category by ID
 */
function get_category_by_id($cat_id) {
    global $wpdb;
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}recipe_categories WHERE cat_id = %d",
        $cat_id
    ));
}

/**
 * Get category by name for specific user
 */
function get_user_category_by_name($user_id, $cat_name) {
    global $wpdb;
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}recipe_categories 
         WHERE user_id = %d AND cat_name = %s",
        $user_id,
        $cat_name
    ));
}

/**
 * Create a new category for a user
 */
function create_user_category($user_id, $cat_name) {
    global $wpdb;
    
    // Check if already exists
    $existing = get_user_category_by_name($user_id, $cat_name);
    if ($existing) {
        return array('error' => 'Category already exists', 'cat_id' => $existing->cat_id);
    }
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'recipe_categories',
        array(
            'cat_name' => $cat_name,
            'user_id' => $user_id,
        ),
        array('%s', '%d')
    );
    
    if ($result === false) {
        return array('error' => 'Database error', 'cat_id' => null);
    }
    
    return array('success' => true, 'cat_id' => $wpdb->insert_id);
}

/**
 * Update category name
 */
function update_category_name($cat_id, $new_name) {
    global $wpdb;
    
    // Get category to check user_id for duplicate check
    $category = get_category_by_id($cat_id);
    if (!$category) {
        return array('error' => 'Category not found');
    }
    
    // Check if new name already exists for this user
    $existing = get_user_category_by_name($category->user_id, $new_name);
    if ($existing && $existing->cat_id != $cat_id) {
        return array('error' => 'Category name already exists');
    }
    
    $result = $wpdb->update(
        $wpdb->prefix . 'recipe_categories',
        array('cat_name' => $new_name),
        array('cat_id' => $cat_id),
        array('%s'),
        array('%d')
    );
    
    if ($result === false) {
        return array('error' => 'Database error');
    }
    
    return array('success' => true);
}

/**
 * Delete a category (and its relationships)
 */
function delete_category($cat_id) {
    global $wpdb;
    
    // Delete relationships first
    $wpdb->delete(
        $wpdb->prefix . 'recipe_category_relationships',
        array('cat_id' => $cat_id),
        array('%d')
    );
    
    // Delete category
    $result = $wpdb->delete(
        $wpdb->prefix . 'recipe_categories',
        array('cat_id' => $cat_id),
        array('%d')
    );
    
    if ($result === false) {
        return array('error' => 'Database error');
    }
    
    return array('success' => true);
}

/**
 * Get categories for a recipe
 */
function get_recipe_categories($recipe_id) {
    global $wpdb;
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT c.* 
         FROM {$wpdb->prefix}recipe_categories c
         INNER JOIN {$wpdb->prefix}recipe_category_relationships r ON c.cat_id = r.cat_id
         WHERE r.recipe_id = %d
         ORDER BY c.cat_name ASC",
        $recipe_id
    ));
}

/**
 * Assign categories to a recipe (replaces all existing)
 */
function set_recipe_categories($recipe_id, $cat_ids) {
    global $wpdb;
    
    // Remove all existing relationships
    $wpdb->delete(
        $wpdb->prefix . 'recipe_category_relationships',
        array('recipe_id' => $recipe_id),
        array('%d')
    );
    
    // Add new relationships
    if (!empty($cat_ids)) {
        foreach ($cat_ids as $cat_id) {
            $wpdb->insert(
                $wpdb->prefix . 'recipe_category_relationships',
                array(
                    'recipe_id' => $recipe_id,
                    'cat_id' => $cat_id,
                ),
                array('%d', '%d')
            );
        }
    }
    
    return true;
}

/**
 * Add a category to a recipe (doesn't remove existing)
 */
function add_recipe_category($recipe_id, $cat_id) {
    global $wpdb;
    
    // Check if already exists
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}recipe_category_relationships 
         WHERE recipe_id = %d AND cat_id = %d",
        $recipe_id,
        $cat_id
    ));
    
    if ($existing > 0) {
        return true; // Already assigned
    }
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'recipe_category_relationships',
        array(
            'recipe_id' => $recipe_id,
            'cat_id' => $cat_id,
        ),
        array('%d', '%d')
    );
    
    return $result !== false;
}

/**
 * Remove a category from a recipe
 */
function remove_recipe_category($recipe_id, $cat_id) {
    global $wpdb;
    
    $result = $wpdb->delete(
        $wpdb->prefix . 'recipe_category_relationships',
        array(
            'recipe_id' => $recipe_id,
            'cat_id' => $cat_id,
        ),
        array('%d', '%d')
    );
    
    return $result !== false;
}

/**
 * Get count of recipes in a category
 */
function get_category_recipe_count($cat_id) {
    global $wpdb;
    
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}recipe_category_relationships 
         WHERE cat_id = %d",
        $cat_id
    ));
}

/**
 * Get recipes by category
 */
function get_recipes_by_category($cat_id, $limit = -1) {
    global $wpdb;
    
    $sql = $wpdb->prepare(
        "SELECT p.* 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->prefix}recipe_category_relationships r ON p.ID = r.recipe_id
         WHERE r.cat_id = %d 
         AND p.post_type = 'recipe' 
         AND p.post_status = 'publish'
         ORDER BY p.post_title ASC",
        $cat_id
    );
    
    if ($limit > 0) {
        $sql .= $wpdb->prepare(" LIMIT %d", $limit);
    }
    
    return $wpdb->get_results($sql);
}

/**
 * Get all categories with recipe counts for a user
 */
function get_user_categories_with_counts($user_id) {
    global $wpdb;
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT c.*, COUNT(r.recipe_id) as recipe_count
         FROM {$wpdb->prefix}recipe_categories c
         LEFT JOIN {$wpdb->prefix}recipe_category_relationships r ON c.cat_id = r.cat_id
         WHERE c.user_id = %d
         GROUP BY c.cat_id
         ORDER BY c.cat_name ASC",
        $user_id
    ));
}
