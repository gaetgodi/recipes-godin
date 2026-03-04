<?php
/**
 * 404 Error Page Template
 */

get_header();
?>

<div style="max-width: 800px; margin: 80px auto; padding: 60px 20px; text-align: center;">
    <div style="font-size: 120px; font-weight: bold; color: #c84a31; margin-bottom: 20px;">
        404
    </div>
    
    <h1 style="font-size: 36px; color: #333; margin-bottom: 20px;">
        Oops! Recipe Not Found or page not found
    </h1>
    
    <p style="font-size: 18px; color: #666; margin-bottom: 40px;">
        Looks like this recipe has been eaten! The page you're looking for doesn't exist.
    </p>
    
    <div style="background: #f9f9f9; padding: 30px; border-radius: 8px; margin-bottom: 40px;">
        <p style="font-size: 16px; color: #555; margin-bottom: 20px;">
            <strong>Here's what you can do:</strong>
        </p>
        
        <div style="display: flex; flex-direction: column; gap: 15px; max-width: 500px; margin: 0 auto;">
            <a href="<?php echo home_url('/recipe-manager/'); ?>" 
               style="background: #c84a31; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px; display: inline-block;">
                🍳 Go to Recipe Manager
            </a>
            
            <a href="<?php echo home_url('/'); ?>" 
               style="background: #2271b1; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px; display: inline-block;">
                🏠 Go to Home Page
            </a>
            
            <?php if (current_user_can('edit_posts')): ?>
            <a href="<?php echo home_url('/recipe-editor/'); ?>" 
               style="background: #00a32a; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px; display: inline-block;">
                ➕ Add New Recipe
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <div style="margin-top: 40px;">
        <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/404-recipe.png" 
             alt="Recipe not found" 
             style="max-width: 300px; opacity: 0.7;"
             onerror="this.style.display='none'">
    </div>
    
    <p style="font-size: 14px; color: #999; margin-top: 40px;">
        If you believe this is an error, please contact the site administrator.
    </p>
</div>

<?php get_footer(); ?>