-- Custom Recipe Category System
-- Replaces WordPress taxonomies for user-specific categories

-- Categories table: Each user can have their own "soups", "desserts", etc.
CREATE TABLE IF NOT EXISTS recipe_categories (
    cat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cat_name VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_category (user_id, cat_name),
    INDEX idx_user_id (user_id),
    INDEX idx_cat_name (cat_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recipe-Category relationships: Many-to-many
CREATE TABLE IF NOT EXISTS recipe_category_relationships (
    recipe_id BIGINT UNSIGNED NOT NULL,
    cat_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recipe_id, cat_id),
    INDEX idx_recipe_id (recipe_id),
    INDEX idx_cat_id (cat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
