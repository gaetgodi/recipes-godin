<?php
/**
 * Template Name: User Registration
 * 
 * Custom registration page with admin approval
 */

// Redirect if already logged in
if (is_user_logged_in()) {
    wp_redirect(home_url('/'));
    exit;
}

$errors = array();
$success = false;

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    check_admin_referer('custom_register_form');
    
    $username = sanitize_user($_POST['username']);
    $email = sanitize_email($_POST['email']);
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    
    // Validation
    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif (username_exists($username)) {
        $errors[] = 'Username already exists. Please choose another.';
    } elseif (!validate_username($username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!is_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (email_exists($email)) {
        $errors[] = 'An account with this email already exists.';
    }
    
    if (empty($first_name)) {
        $errors[] = 'First name is required.';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }
    
    // Create user if no errors
    if (empty($errors)) {
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $errors[] = 'Error creating account: ' . $user_id->get_error_message();
        } else {
            // Update user meta
            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'display_name' => $first_name . ' ' . $last_name,
            ));
            
            // Set role to subscriber (auto-approved)
            $user = new WP_User($user_id);
            $user->set_role('subscriber');
            
            // Mark as active (no admin approval needed)
            update_user_meta($user_id, 'account_status', 'active');
            update_user_meta($user_id, 'registration_date', current_time('mysql'));
            
            // Send notification to admin (info only, no approval needed)
            $admin_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            
            $subject = "New User Registration - {$site_name}";
            $message = "A new user has registered (auto-approved):\n\n";
            $message = "Name: {$first_name} {$last_name}\n";
            $message .= "Username: {$username}\n";
            $message .= "Email: {$email}\n";
            $message .= "Registration Date: " . current_time('F j, Y g:i a') . "\n\n";
            $message .= "User can log in immediately but has no collection access until granted.\n";
            $message .= "Manage permissions at: " . home_url('/permissions-manager/');
            
            wp_mail($admin_email, $subject, $message);
            
            // Send welcome email to user
            $user_subject = "Welcome to {$site_name}!";
            $user_message = "Hi {$first_name},\n\n";
            $user_message .= "Welcome to {$site_name}!\n\n";
            $user_message .= "Your account has been created and is ready to use. ";
            $user_message .= "You can log in now at: " . home_url('/login/') . "\n\n";
            $user_message .= "Username: {$username}\n\n";
            $user_message .= "Note: You won't have access to any recipe collections until an author grants you permission.\n\n";
            $user_message .= "If you have any questions, please contact us.\n\n";
            $user_message .= "Best regards,\n";
            $user_message .= "The Recipe Team";
            
            wp_mail($email, $user_subject, $user_message);
            
            $success = true;
        }
    }
}

get_header();
?>

<div class="register-container">
    <div class="register-card">
        <div class="register-header">
            <h1>Create Your Account</h1>
            <p>Join our family recipe collection</p>
        </div>
        
        <div class="register-body">
            <?php if ($success): ?>
                <div class="alert alert-success success-message">
                    <h2>✅ Registration Successful!</h2>
                    <p>Thank you for registering! Your account is active and you can log in immediately.</p>
                    <p>Note: You won't have access to recipe collections until an author grants you permission.</p>
                    <p style="margin-top: 20px;">
                        <a href="<?php echo home_url('/login/'); ?>" style="color: #060; font-weight: bold;">Log In Now</a>
                    </p>
                </div>
            <?php else: ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <strong>⚠️ Please fix the following errors:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <?php wp_nonce_field('custom_register_form'); ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" name="first_name" id="first_name" 
                                   value="<?php echo isset($_POST['first_name']) ? esc_attr($_POST['first_name']) : ''; ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" name="last_name" id="last_name" 
                                   value="<?php echo isset($_POST['last_name']) ? esc_attr($_POST['last_name']) : ''; ?>" 
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" name="username" id="username" 
                               value="<?php echo isset($_POST['username']) ? esc_attr($_POST['username']) : ''; ?>" 
                               required>
                        <div class="help-text">Letters, numbers, and underscores only</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" name="email" id="email" 
                               value="<?php echo isset($_POST['email']) ? esc_attr($_POST['email']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" name="password" id="password" required>
                        <div class="help-text">Minimum 8 characters</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm">Confirm Password *</label>
                        <input type="password" name="password_confirm" id="password_confirm" required>
                    </div>
                    
                    <button type="submit" name="register_submit" class="btn-register">
                        ✨ Create Account
                    </button>
                </form>
                
                <div class="register-links">
                    Already have an account? <a href="<?php echo home_url('/login/'); ?>">Log In</a>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
