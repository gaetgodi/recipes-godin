<?php
/**
 * Allergen Checker — Core Data Functions
 *
 * Helper functions for managing allergen definitions/aliases, allergen
 * profiles, and profile-allergen membership. Mirrors the flat procedural
 * style of custom-category-functions.php — no ORM, no classes.
 *
 * Mutating profile functions (update_profile, delete_profile,
 * set_profile_allergens) require the acting user's ID and refuse the
 * operation unless user_can_edit_profile() (allergen-permissions.php)
 * confirms that user is the profile's creator. This check lives here,
 * in the data layer, not just in which buttons a page renders, since
 * POST handlers are reachable independent of the rendered form.
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Get all seed (Health Canada priority list) allergens.
 */
function get_seed_allergens() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}allergen_definitions
         WHERE is_seed_allergen = 1
         ORDER BY allergen_name ASC"
    );
}

/**
 * Get a user's custom (non-seed) allergens.
 */
function get_user_custom_allergens($user_id) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_definitions
         WHERE is_seed_allergen = 0 AND user_id = %d
         ORDER BY allergen_name ASC",
        $user_id
    ));
}

/**
 * Get a single allergen definition by ID.
 */
function get_allergen_definition_by_id($allergen_id) {
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_definitions WHERE allergen_id = %d",
        $allergen_id
    ));
}

/**
 * Create a custom allergen (with aliases) owned by a user.
 *
 * @param int    $user_id
 * @param string $allergen_name
 * @param array  $aliases  Additional alias strings. The canonical name itself
 *                          is always stored as an alias row too.
 */
function create_custom_allergen($user_id, $allergen_name, $aliases = array()) {
    global $wpdb;

    $allergen_name = trim($allergen_name);
    if (empty($allergen_name)) {
        return array('error' => 'Allergen name cannot be empty');
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT allergen_id FROM {$wpdb->prefix}allergen_definitions
         WHERE user_id = %d AND allergen_name = %s",
        $user_id,
        $allergen_name
    ));

    if ($existing) {
        return array('error' => 'You already have an allergen with this name', 'allergen_id' => $existing->allergen_id);
    }

    $result = $wpdb->insert(
        $wpdb->prefix . 'allergen_definitions',
        array(
            'allergen_name' => $allergen_name,
            'is_seed_allergen' => 0,
            'user_id' => $user_id,
        ),
        array('%s', '%d', '%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    $allergen_id = $wpdb->insert_id;

    $alias_texts = array_unique(array_filter(array_map('trim', array_merge(
        array(strtolower($allergen_name)),
        $aliases
    ))));

    foreach ($alias_texts as $alias_text) {
        add_allergen_alias($allergen_id, $user_id, $alias_text);
    }

    return array('success' => true, 'allergen_id' => $allergen_id);
}

/**
 * Get all aliases for an allergen.
 */
function get_allergen_aliases($allergen_id) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_aliases WHERE allergen_id = %d ORDER BY alias_text ASC",
        $allergen_id
    ));
}

/**
 * Add an alias to an allergen. Only the allergen's owner may add aliases to
 * a custom allergen; seed allergens (user_id IS NULL) cannot be modified by
 * any user through this function.
 */
function add_allergen_alias($allergen_id, $acting_user_id, $alias_text) {
    global $wpdb;

    $allergen = get_allergen_definition_by_id($allergen_id);
    if (!$allergen || $allergen->user_id === null || intval($allergen->user_id) !== intval($acting_user_id)) {
        return array('error' => 'You do not have permission to modify this allergen');
    }

    $alias_text = strtolower(trim($alias_text));
    if (empty($alias_text)) {
        return array('error' => 'Alias cannot be empty');
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}allergen_aliases WHERE allergen_id = %d AND alias_text = %s",
        $allergen_id,
        $alias_text
    ));

    if ($existing > 0) {
        return array('success' => true); // Already present
    }

    $result = $wpdb->insert(
        $wpdb->prefix . 'allergen_aliases',
        array(
            'allergen_id' => $allergen_id,
            'alias_text' => $alias_text,
        ),
        array('%d', '%s')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Rename a custom allergen and replace its alias set (delete-all-then-
 * reinsert, same pattern as set_profile_allergens()). Only the owner may
 * edit; seed allergens cannot be modified through this function.
 */
function update_custom_allergen($allergen_id, $acting_user_id, $allergen_name, $aliases = array()) {
    global $wpdb;

    $allergen = get_allergen_definition_by_id($allergen_id);
    if (!$allergen || $allergen->user_id === null || intval($allergen->user_id) !== intval($acting_user_id)) {
        return array('error' => 'You do not have permission to edit this allergen');
    }

    $allergen_name = trim($allergen_name);
    if (empty($allergen_name)) {
        return array('error' => 'Allergen name cannot be empty');
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT allergen_id FROM {$wpdb->prefix}allergen_definitions
         WHERE user_id = %d AND allergen_name = %s AND allergen_id != %d",
        $acting_user_id,
        $allergen_name,
        $allergen_id
    ));

    if ($existing) {
        return array('error' => 'You already have another allergen with this name');
    }

    $wpdb->update(
        $wpdb->prefix . 'allergen_definitions',
        array('allergen_name' => $allergen_name),
        array('allergen_id' => $allergen_id),
        array('%s'),
        array('%d')
    );

    $wpdb->delete(
        $wpdb->prefix . 'allergen_aliases',
        array('allergen_id' => $allergen_id),
        array('%d')
    );

    $alias_texts = array_unique(array_filter(array_map('trim', array_merge(
        array(strtolower($allergen_name)),
        $aliases
    ))));

    foreach ($alias_texts as $alias_text) {
        $wpdb->insert(
            $wpdb->prefix . 'allergen_aliases',
            array(
                'allergen_id' => $allergen_id,
                'alias_text' => strtolower($alias_text),
            ),
            array('%d', '%s')
        );
    }

    return array('success' => true);
}

/**
 * Delete a custom allergen — removes its aliases and any profile
 * memberships referencing it, so no profile is left pointing at a
 * dangling allergen_id. Only the owner may delete; seed allergens cannot
 * be deleted through this function.
 */
function delete_custom_allergen($allergen_id, $acting_user_id) {
    global $wpdb;

    $allergen = get_allergen_definition_by_id($allergen_id);
    if (!$allergen || $allergen->user_id === null || intval($allergen->user_id) !== intval($acting_user_id)) {
        return array('error' => 'You do not have permission to delete this allergen');
    }

    $wpdb->delete(
        $wpdb->prefix . 'allergen_profile_items',
        array('allergen_id' => $allergen_id),
        array('%d')
    );

    $wpdb->delete(
        $wpdb->prefix . 'allergen_aliases',
        array('allergen_id' => $allergen_id),
        array('%d')
    );

    $result = $wpdb->delete(
        $wpdb->prefix . 'allergen_definitions',
        array('allergen_id' => $allergen_id),
        array('%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Get all profiles owned by a user.
 */
function get_user_profiles($user_id) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_profiles
         WHERE owner_user_id = %d
         ORDER BY profile_name ASC",
        $user_id
    ));
}

/**
 * Get a single profile by ID.
 */
function get_profile_by_id($profile_id) {
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_profiles WHERE profile_id = %d",
        $profile_id
    ));
}

/**
 * Create a new allergen profile.
 */
function create_profile($owner_user_id, $profile_name, $profile_age = null) {
    global $wpdb;

    $profile_name = trim($profile_name);
    if (empty($profile_name)) {
        return array('error' => 'Profile name cannot be empty');
    }

    $result = $wpdb->insert(
        $wpdb->prefix . 'allergen_profiles',
        array(
            'profile_name' => $profile_name,
            'profile_age' => ($profile_age === null || $profile_age === '') ? null : intval($profile_age),
            'owner_user_id' => $owner_user_id,
        ),
        array('%s', '%d', '%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true, 'profile_id' => $wpdb->insert_id);
}

/**
 * Update a profile's name/age. Only the creator may edit.
 */
function update_profile($profile_id, $acting_user_id, $profile_name, $profile_age = null) {
    global $wpdb;

    $profile = get_profile_by_id($profile_id);
    if (!$profile || !user_can_edit_profile($acting_user_id, $profile)) {
        return array('error' => 'You do not have permission to edit this profile');
    }

    $profile_name = trim($profile_name);
    if (empty($profile_name)) {
        return array('error' => 'Profile name cannot be empty');
    }

    $result = $wpdb->update(
        $wpdb->prefix . 'allergen_profiles',
        array(
            'profile_name' => $profile_name,
            'profile_age' => ($profile_age === null || $profile_age === '') ? null : intval($profile_age),
        ),
        array('profile_id' => $profile_id),
        array('%s', '%d'),
        array('%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Delete a profile (and its allergen memberships). Only the creator may delete.
 */
function delete_profile($profile_id, $acting_user_id) {
    global $wpdb;

    $profile = get_profile_by_id($profile_id);
    if (!$profile || !user_can_edit_profile($acting_user_id, $profile)) {
        return array('error' => 'You do not have permission to delete this profile');
    }

    $wpdb->delete(
        $wpdb->prefix . 'allergen_profile_items',
        array('profile_id' => $profile_id),
        array('%d')
    );

    $wpdb->delete(
        $wpdb->prefix . 'allergen_profile_shares',
        array('profile_id' => $profile_id),
        array('%d')
    );

    $result = $wpdb->delete(
        $wpdb->prefix . 'allergen_profiles',
        array('profile_id' => $profile_id),
        array('%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Get the allergens (full definition rows) assigned to a profile.
 */
function get_profile_allergens($profile_id) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT d.*
         FROM {$wpdb->prefix}allergen_definitions d
         INNER JOIN {$wpdb->prefix}allergen_profile_items i ON d.allergen_id = i.allergen_id
         WHERE i.profile_id = %d
         ORDER BY d.allergen_name ASC",
        $profile_id
    ));
}

/**
 * Replace a profile's allergen set (delete-all-then-reinsert, same pattern
 * as set_recipe_categories()). Only the creator may edit.
 */
function set_profile_allergens($profile_id, $acting_user_id, $allergen_ids) {
    global $wpdb;

    $profile = get_profile_by_id($profile_id);
    if (!$profile || !user_can_edit_profile($acting_user_id, $profile)) {
        return array('error' => 'You do not have permission to edit this profile');
    }

    $wpdb->delete(
        $wpdb->prefix . 'allergen_profile_items',
        array('profile_id' => $profile_id),
        array('%d')
    );

    if (!empty($allergen_ids)) {
        foreach ($allergen_ids as $allergen_id) {
            $wpdb->insert(
                $wpdb->prefix . 'allergen_profile_items',
                array(
                    'profile_id' => $profile_id,
                    'allergen_id' => intval($allergen_id),
                ),
                array('%d', '%d')
            );
        }
    }

    return array('success' => true);
}

/**
 * Get all products owned by a user — powers the Product Library page's
 * "my products" list, where add/edit/delete happen.
 */
function get_user_products($user_id) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_products
         WHERE owner_user_id = %d
         ORDER BY product_name ASC",
        $user_id
    ));
}

/**
 * Get every product in the system, across all users' libraries, each
 * tagged with its owner's display name.
 *
 * Products are fully global for viewing/selecting — any logged-in user
 * can check any product on the Allergen Checker page, no sharing step
 * required (scope decision: skip the collection-style sharing/copy
 * mechanism entirely for products). Editing/deleting remains
 * creator-only regardless — see update_product()/delete_product(),
 * which this function has no bearing on.
 */
function get_all_products() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT p.*, u.display_name AS owner_display_name
         FROM {$wpdb->prefix}allergen_products p
         LEFT JOIN {$wpdb->users} u ON p.owner_user_id = u.ID
         ORDER BY p.product_name ASC"
    );
}

/**
 * Get a single product by ID.
 */
function get_product_by_id($product_id) {
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}allergen_products WHERE product_id = %d",
        $product_id
    ));
}

/**
 * Create a new product.
 */
function create_product($owner_user_id, $product_name, $ingredient_text, $source_image_attachment_id = null) {
    global $wpdb;

    $product_name = trim($product_name);
    if (empty($product_name)) {
        return array('error' => 'Product name cannot be empty');
    }

    $ingredient_text = trim($ingredient_text);
    if (empty($ingredient_text)) {
        return array('error' => 'Ingredient list cannot be empty');
    }

    $result = $wpdb->insert(
        $wpdb->prefix . 'allergen_products',
        array(
            'product_name' => $product_name,
            'ingredient_text' => $ingredient_text,
            'owner_user_id' => $owner_user_id,
            'source_image_attachment_id' => $source_image_attachment_id ? intval($source_image_attachment_id) : null,
        ),
        array('%s', '%s', '%d', '%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true, 'product_id' => $wpdb->insert_id);
}

/**
 * Update a product. Only the owner may edit (Phase 3: no sharing yet, so
 * this is a plain ownership check — the same reasoning applies as
 * user_can_edit_profile(), just without a shares table to also consult
 * since Phase 5 reuses collection-permissions.php's copy-on-share model
 * for products instead of a join-table share model).
 */
function update_product($product_id, $acting_user_id, $product_name, $ingredient_text) {
    global $wpdb;

    $product = get_product_by_id($product_id);
    if (!$product || $product->owner_user_id != $acting_user_id) {
        return array('error' => 'You do not have permission to edit this product');
    }

    $product_name = trim($product_name);
    if (empty($product_name)) {
        return array('error' => 'Product name cannot be empty');
    }

    $ingredient_text = trim($ingredient_text);
    if (empty($ingredient_text)) {
        return array('error' => 'Ingredient list cannot be empty');
    }

    $result = $wpdb->update(
        $wpdb->prefix . 'allergen_products',
        array(
            'product_name' => $product_name,
            'ingredient_text' => $ingredient_text,
        ),
        array('product_id' => $product_id),
        array('%s', '%s'),
        array('%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Delete a product. Only the owner may delete.
 */
function delete_product($product_id, $acting_user_id) {
    global $wpdb;

    $product = get_product_by_id($product_id);
    if (!$product || $product->owner_user_id != $acting_user_id) {
        return array('error' => 'You do not have permission to delete this product');
    }

    $result = $wpdb->delete(
        $wpdb->prefix . 'allergen_products',
        array('product_id' => $product_id),
        array('%d')
    );

    if ($result === false) {
        return array('error' => 'Database error');
    }

    return array('success' => true);
}

/**
 * Get the products (full rows) attached to a recipe.
 *
 * Mirrors get_recipe_categories() in custom-category-functions.php — same
 * join-table read pattern, applied to allergen_recipe_products instead of
 * recipe_category_relationships.
 */
function get_recipe_products($recipe_id) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT p.*
         FROM {$wpdb->prefix}allergen_products p
         INNER JOIN {$wpdb->prefix}allergen_recipe_products r ON p.product_id = r.product_id
         WHERE r.recipe_id = %d
         ORDER BY p.product_name ASC",
        $recipe_id
    ));
}

/**
 * Replace a recipe's attached-product set (delete-all-then-reinsert, same
 * pattern as set_recipe_categories()/set_profile_allergens()).
 */
function set_recipe_products($recipe_id, $product_ids) {
    global $wpdb;

    $wpdb->delete(
        $wpdb->prefix . 'allergen_recipe_products',
        array('recipe_id' => $recipe_id),
        array('%d')
    );

    if (!empty($product_ids)) {
        foreach ($product_ids as $product_id) {
            $wpdb->insert(
                $wpdb->prefix . 'allergen_recipe_products',
                array(
                    'recipe_id' => $recipe_id,
                    'product_id' => intval($product_id),
                ),
                array('%d', '%d')
            );
        }
    }

    return true;
}
