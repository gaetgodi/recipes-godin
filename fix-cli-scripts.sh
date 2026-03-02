#!/bin/bash
# Add CLI protection to all utility scripts in theme directory

cd /var/www/vhosts/godin.com/recipes.godin.com/wp-content/themes/divi-recipe-child

# List of CLI-only scripts that need protection
scripts=(
    "cleanup-temp-tables.php"
    "debug-mulligatawny.php"
    "debug-paula-query.php"
    "assign-recipe-ids.php"
    "category-check.php"
    "delete-numeric-categories.php"
    "migrate-to-custom-categories.php"
    "recipe-cleanup.php"
    "test-french-characters.php"
)

for script in "${scripts[@]}"; do
    if [ -f "$script" ]; then
        echo "Processing: $script"
        # Check if it already has CLI protection
        if ! grep -q "php_sapi_name()" "$script"; then
            # Add CLI protection after opening <?php tag
            sed -i '1 a\
// CLI-only script protection\
if (!isset($argc) || php_sapi_name() !== '"'"'cli'"'"') {\
    return;\
}' "$script"
            echo "  ✓ Added CLI protection"
        else
            echo "  - Already protected"
        fi
        # Fix ownership
        chown godin.com_mn3mxiulc0n:psacln "$script"
        chmod 644 "$script"
    fi
done

echo ""
echo "✓ All utility scripts protected and ownership fixed"
echo "Restarting PHP-FPM..."
service php-fpm restart 2>/dev/null || service php8.3-fpm restart 2>/dev/null || service php8.2-fpm restart 2>/dev/null
echo "✓ Done! Try logging in now."
