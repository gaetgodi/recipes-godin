<?php
/**
 * Divi Recipe Child Theme Functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Include custom category functions
require_once(get_stylesheet_directory() . '/custom-category-functions.php');

/**
 * Display header ticker on all pages except Print Book
 */
function display_recipe_header_ticker() {
    // Don't show on Print Book page
    if (is_page('print-book')) {
        return;
    }
    
    // Include the header ticker
    get_template_part('header-ticker');
}
add_action('et_before_main_content', 'display_recipe_header_ticker');

/**
 * Enqueue parent and child theme styles
 */
function divi_recipe_child_enqueue_styles() {
    // Parent theme stylesheet
    wp_enqueue_style('divi-parent-style', get_template_directory_uri() . '/style.css');
    
    // Child theme stylesheet
    wp_enqueue_style('divi-recipe-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array('divi-parent-style'),
        wp_get_theme()->get('Version')
    );
    
    // Login/Register page styles
    if (is_page('login') || is_page('register')) {
        wp_enqueue_style('recipe-login-style',
            get_stylesheet_directory_uri() . '/login.css',
            array(),
            wp_get_theme()->get('Version')
        );
    }
}
add_action('wp_enqueue_scripts', 'divi_recipe_child_enqueue_styles');

/**
 * Login and Registration Helper Functions
 */

// Redirect wp-login.php to custom login page
add_action('init', function() {
    // Skip if in admin area
    if (is_admin()) {
        return;
    }
    
    global $pagenow;
    if ($pagenow === 'wp-login.php' && 
        !isset($_GET['action']) && 
        !isset($_GET['checkemail'])) {
        wp_redirect(home_url('/login/'));
        exit;
    }
});

// Redirect after login
add_filter('login_redirect', function($redirect_to, $request, $user) {
    // Check if user account is pending approval
    if (isset($user->ID)) {
        $status = get_user_meta($user->ID, 'account_status', true);
        if ($status === 'pending') {
            wp_logout();
            wp_redirect(home_url('/login/?pending=1'));
            exit;
        }
    }
    
    // If user is trying to access wp-admin, let them go there
    if (strpos($redirect_to, 'wp-admin') !== false) {
        return $redirect_to;
    }
    
    // Otherwise redirect to home page (recipe manager is the front page)
    return home_url('/');
}, 10, 3);

// Redirect after logout
add_action('wp_logout', function() {
    wp_redirect(home_url('/login/?logged_out=1'));
    exit;
});

// Show pending approval message on login page
add_action('wp_footer', function() {
    if (isset($_GET['pending']) && $_GET['pending'] == '1') {
        ?>
        <script>
        alert('Your account is pending approval. You will receive an email when your account is activated.');
        </script>
        <?php
    }
});

// Prevent pending users from accessing the site
add_action('init', function() {
    // Skip admin area
    if (is_admin()) {
        return;
    }
    
    // Skip administrators - they're always approved
    if (current_user_can('administrator')) {
        return;
    }
    
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $status = get_user_meta($user_id, 'account_status', true);
        
        if ($status === 'pending') {
            wp_logout();
            wp_redirect(home_url('/login/?pending=1'));
            exit;
        }
    }
});

// Protect frontend pages - require login
add_action('template_redirect', function() {
    // Skip login/register pages
    if (is_page('login') || is_page('register')) {
        return;
    }
    
    // Skip 404 pages (let them see the 404 message)
    if (is_404()) {
        return;
    }
    
    // Skip if user is logged in
    if (is_user_logged_in()) {
        return;
    }
    
    // Redirect to login page
    wp_redirect(home_url('/login/'));
    exit;
});

// Add "Approve User" action in admin
add_filter('user_row_actions', function($actions, $user) {
    $status = get_user_meta($user->ID, 'account_status', true);
    
    // If it's an array, get the first element
    if (is_array($status)) {
        $status = isset($status[0]) ? $status[0] : '';
    }
    
    // Trim
    $status = trim($status, " \t\n\r\0\x0B'\"");
    
    if ($status === 'pending') {
        $approve_url = add_query_arg(array(
            'action' => 'approve_user',
            'user_id' => $user->ID,
            '_wpnonce' => wp_create_nonce('approve_user_' . $user->ID)
        ), admin_url('users.php'));
        
        $actions['approve'] = '<a href="' . esc_url($approve_url) . '" style="color: green; font-weight: bold;">Approve Account</a>';
    }
    
    return $actions;
}, 10, 2);

// Force approve button to be visible with CSS
add_action('admin_head-users.php', function() {
    ?>
    <style>
    .row-actions .approve {
        display: inline !important;
        visibility: visible !important;
    }
    
    .row-actions .approve a {
        display: inline !important;
        visibility: visible !important;
    }
    </style>
    <?php
});

// Handle user approval
add_action('admin_init', function() {
    if (isset($_GET['action']) && $_GET['action'] === 'approve_user' && isset($_GET['user_id'])) {
        $user_id = intval($_GET['user_id']);
        
        check_admin_referer('approve_user_' . $user_id);
        
        // Update account status
        update_user_meta($user_id, 'account_status', 'approved');
        update_user_meta($user_id, 'approval_date', current_time('mysql'));
        
        // Send approval email to user
        $user = get_userdata($user_id);
        $site_name = get_bloginfo('name');
        $login_url = home_url('/login/');
        
        $subject = "Your Account Has Been Approved - {$site_name}";
        $message = "Hi {$user->first_name},\n\n";
        $message .= "Great news! Your account at {$site_name} has been approved.\n\n";
        $message .= "You can now log in and start viewing and sharing recipes:\n";
        $message .= $login_url . "\n\n";
        $message .= "Username: {$user->user_login}\n\n";
        $message .= "Welcome to our recipe family!\n\n";
        $message .= "Best regards,\n";
        $message .= "The Recipe Team";
        
        wp_mail($user->user_email, $subject, $message);
        
        // Redirect back with success message
        wp_redirect(add_query_arg('approved', '1', admin_url('users.php')));
        exit;
    }
});

// Show success message after approval
add_action('admin_notices', function() {
    if (isset($_GET['approved']) && $_GET['approved'] == '1') {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>User account approved!</strong> The user has been notified via email.</p>
        </div>
        <?php
    }
});

// Add pending status indicator in users list
add_filter('manage_users_columns', function($columns) {
    $columns['account_status'] = 'Account Status';
    return $columns;
});

add_filter('manage_users_custom_column', function($value, $column_name, $user_id) {
    if ($column_name === 'account_status') {
        $status = get_user_meta($user_id, 'account_status', true);
        if ($status === 'pending') {
            return '<span style="color: orange; font-weight: bold;">⏳ Pending Approval</span>';
        } elseif ($status === 'approved') {
            return '<span style="color: green;">✅ Approved</span>';
        } else {
            return '<span style="color: #999;">—</span>';
        }
    }
    return $value;
}, 10, 3);

// Hide admin bar for non-administrators
add_action('after_setup_theme', 'hide_admin_bar_for_non_admins');
function hide_admin_bar_for_non_admins() {
    if (!current_user_can('administrator') && !is_admin()) {
        show_admin_bar(false);
    }
}

// Prevent non-admins from accessing wp-admin
add_action('admin_init', 'restrict_admin_access');
function restrict_admin_access() {
    if (!current_user_can('administrator') && !wp_doing_ajax()) {
        wp_redirect(home_url('/recipe-manager/'));
        exit;
    }
}

/**
 * Enqueue Recipe Page Styles
 */
function enqueue_recipe_page_styles() {
    // Recipe Editor
    if (is_page_template('page-recipe-editor.php')) {
        wp_enqueue_style('recipe-editor-style',
            get_stylesheet_directory_uri() . '/css/recipe-editor.css',
            array(),
            wp_get_theme()->get('Version')
        );
    }
    
    // Recipe Manager
    if (is_page_template('page-recipe-manager.php')) {
        wp_enqueue_style('recipe-manager-style',
            get_stylesheet_directory_uri() . '/css/recipe-manager.css',
            array(),
            wp_get_theme()->get('Version')
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_recipe_page_styles');

// Load recipe image upload handler for AJAX
require_once(get_stylesheet_directory() . '/recipe-image-upload-handler.php');