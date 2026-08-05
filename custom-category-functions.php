<?php
/**
 * Custom Recipe Category Functions
 * 
 * Helper functions for managing custom recipe categories
 * Replaces WordPress taxonomy functions
 */

/**
 * Convert plain-text recipe content (one item per line) into the <ul>/<ol> HTML
 * that _recipe_ingredients / _recipe_method are expected to hold (rendered via
 * wp_kses_post() in page-recipe-view.php, so plain text with no list markup
 * would render as one unbroken blob with no line breaks).
 *
 * Shared by the recipe editor's save handler (page-recipe-editor.php) and the
 * CLI bulk importer (import-notes-recipes.php) so both produce identical markup.
 *
 * @param string $content  Raw plain-text content, one ingredient/step per line.
 * @param bool   $is_method True to strip leading step numbers and wrap in <ol>,
 *                          false to strip leading bullets and wrap in <ul>.
 */
function format_recipe_content_html($content, $is_method = false) {
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

/**
 * Wrap plain-text recipe notes in a <p> tag if they aren't already HTML,
 * matching the treatment ingredients/method get via format_recipe_content_html().
 * Shared by the recipe editor's save handler and the CLI bulk importer.
 */
function format_recipe_notes_html($notes) {
    if (!empty($notes) && strpos($notes, '<p>') === false) {
        return '<p>' . esc_html($notes) . '</p>';
    }
    return $notes;
}

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
 *
 * @param int    $user_id
 * @param string $cat_name
 * @param string $category_type  'food' (default) or 'author'. Author categories
 *                                are auto-created during share/copy to record
 *                                where a recipe originally came from.
 */
function create_user_category($user_id, $cat_name, $category_type = 'food') {
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
            'category_type' => $category_type,
            'user_id' => $user_id,
        ),
        array('%s', '%s', '%d')
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
 * Get every distinct category name across ALL users (not scoped to one collection).
 *
 * Used as a naming vocabulary when matching new content against existing categories
 * (e.g. the CLI bulk importer) — so we reuse names like an existing "Desserts"
 * instead of inventing a near-duplicate like "Dessert". This does NOT return
 * cat_id/user_id pairs because the vocabulary is name-only; any match still has
 * to be resolved (get-or-created) within the target user's own scope via
 * get_or_create_user_category(), since categories remain strictly per-user.
 *
 * @param string|null $category_type Optional filter: 'food', 'author', or null for all.
 * @return string[] Distinct category names, alphabetically sorted.
 */
function get_all_category_names_cross_user($category_type = null) {
    global $wpdb;

    if ($category_type !== null) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cat_name FROM {$wpdb->prefix}recipe_categories
             WHERE category_type = %s
             ORDER BY cat_name ASC",
            $category_type
        ));
    }

    return $wpdb->get_col(
        "SELECT DISTINCT cat_name FROM {$wpdb->prefix}recipe_categories
         ORDER BY cat_name ASC"
    );
}

/**
 * Get-or-create a category by name within one user's scope, trimming/normalizing
 * whitespace first so trivially-different spellings ("Soups " vs "Soups") don't
 * spawn duplicates. Thin wrapper — create_user_category() already no-ops and
 * returns the existing cat_id when the (trimmed) name matches, this just saves
 * every caller from re-deriving that trim + fallback logic.
 *
 * @return int cat_id
 */
function get_or_create_user_category($user_id, $cat_name, $category_type = 'food') {
    $cat_name = trim(preg_replace('/\s+/', ' ', $cat_name));

    $existing = get_user_category_by_name($user_id, $cat_name);
    if ($existing) {
        return (int) $existing->cat_id;
    }

    $result = create_user_category($user_id, $cat_name, $category_type);
    return (int) $result['cat_id'];
}

/**
 * Get all categories with recipe counts for a user
 *
 * @param int         $user_id
 * @param string|null $category_type  Optional filter: 'food', 'author', or null for all (default).
 */
function get_user_categories_with_counts($user_id, $category_type = null) {
    global $wpdb;
    
    if ($category_type !== null) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, COUNT(r.recipe_id) as recipe_count
             FROM {$wpdb->prefix}recipe_categories c
             LEFT JOIN {$wpdb->prefix}recipe_category_relationships r ON c.cat_id = r.cat_id
             WHERE c.user_id = %d AND c.category_type = %s
             GROUP BY c.cat_id
             ORDER BY c.cat_name ASC",
            $user_id,
            $category_type
        ));
    }
    
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