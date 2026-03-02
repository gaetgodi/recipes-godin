<?php
/**
 * Template Name: Custom Login
 * 
 * Custom login page for recipes.godin.com
 */

// Redirect if already logged in
if (is_user_logged_in()) {
    wp_redirect(home_url('/'));
    exit;
}

// Handle login form submission
$login_error = '';
$login_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    check_admin_referer('custom_login_form');
    
    $username = sanitize_text_field($_POST['log']);
    $password = $_POST['pwd'];
    $remember = isset($_POST['rememberme']);
    
    $creds = array(
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => $remember
    );
    
    // Use secure cookie (second parameter true for admin access)
    $user = wp_signon($creds, is_ssl());
    
    if (is_wp_error($user)) {
        $login_error = 'Invalid username or password. Please try again.';
    } else {
        // Check if there's a redirect_to parameter (for wp-admin access)
        $redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : home_url('/');
        
        // Apply login_redirect filter to allow other code to modify
        $redirect_to = apply_filters('login_redirect', $redirect_to, isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '', $user);
        
        wp_redirect($redirect_to);
        exit;
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    wp_logout();
    $login_success = 'You have been logged out successfully.';
}

// Show logged out message
if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') {
    $login_success = 'You have been logged out successfully.';
}

// Handle password reset
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $login_success = 'Password reset email sent! Check your inbox.';
}

get_header();
?>

<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <h1>Our Recipes</h1>
            <p>Family Recipe Collection</p>
        </div>
        
        <div class="login-body">
            <?php if ($login_error): ?>
                <div class="alert alert-error">
                    ⚠️ <?php echo esc_html($login_error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($login_success): ?>
                <div class="alert alert-success">
                    ✅ <?php echo esc_html($login_success); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('custom_login_form'); ?>
                
                <div class="form-group">
                    <label for="log">Username or Email</label>
                    <input type="text" name="log" id="log" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="pwd">Password</label>
                    <input type="password" name="pwd" id="pwd" required autocomplete="current-password">
                </div>
                
                <div class="remember-me">
                    <input type="checkbox" name="rememberme" id="rememberme" value="1">
                    <label for="rememberme">Remember me</label>
                </div>
                
                <button type="submit" name="login_submit" class="btn-login">
                    🔐 Log In
                </button>
            </form>
            
            <div class="login-links">
                <a href="<?php echo home_url('/register/'); ?>">Create Account</a>
                <span>•</span>
                <a href="<?php echo wp_lostpassword_url(); ?>">Forgot Password?</a>
            </div>
        </div>
    </div>
</div>

<?php get_footer(); ?>
