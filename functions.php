<?php
/**
 * Template Name: Permissions Manager
 * 
 * Manage who can access your recipe collection
 */

// Must be logged in and have edit_posts capability
if (!is_user_logged_in() || !current_user_can('edit_posts')) {
    wp_redirect(home_url('/login/'));
    exit;
}

// Include permission functions
require_once(get_stylesheet_directory() . '/collection-permissions.php');

$current_user_id = get_current_user_id();

// Clean up any deleted users from permission arrays
cleanup_deleted_users_from_permissions($current_user_id);

$success_message = '';
$error_message = '';

// Handle permission actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_admin_referer('manage_permissions');
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    switch ($_POST['action']) {
        case 'grant_editor':
            if (grant_editor_permission($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Granted Editor access to {$user->display_name}";
            }
            break;
            
        case 'grant_viewer':
            if (grant_viewer_permission($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Granted Viewer access to {$user->display_name}";
            }
            break;
            
        case 'revoke_editor':
            if (revoke_editor_permission($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Revoked Editor access from {$user->display_name}";
            }
            break;
            
        case 'revoke_viewer':
            if (revoke_viewer_permission($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Revoked Viewer access from {$user->display_name}";
            }
            break;
            
        case 'approve_editor':
            if (approve_access_request($current_user_id, $user_id, true)) {
                $user = get_userdata($user_id);
                $success_message = "Approved {$user->display_name} as Editor";
            }
            break;
            
        case 'approve_viewer':
            if (approve_access_request($current_user_id, $user_id, false)) {
                $user = get_userdata($user_id);
                $success_message = "Approved {$user->display_name} as Viewer";
            }
            break;
            
        case 'deny_request':
            if (deny_access_request($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Denied access request from {$user->display_name}";
            }
            break;
            
        case 'promote_to_editor':
            revoke_viewer_permission($current_user_id, $user_id);
            if (grant_editor_permission($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Promoted {$user->display_name} to Editor";
            }
            break;
            
        case 'demote_to_viewer':
            revoke_editor_permission($current_user_id, $user_id);
            if (grant_viewer_permission($current_user_id, $user_id)) {
                $user = get_userdata($user_id);
                $success_message = "Demoted {$user->display_name} to Viewer";
            }
            break;
    }
}

// Get current permissions
$editors = get_user_meta($current_user_id, '_collection_editors', true);
if (!is_array($editors)) $editors = array();

$viewers = get_user_meta($current_user_id, '_collection_viewers', true);
if (!is_array($viewers)) $viewers = array();

$requests = get_user_meta($current_user_id, '_access_requests', true);
if (!is_array($requests)) $requests = array();

// Get all users who could be granted access (exclude current user and admins)
$all_users = get_users(array(
    'exclude' => array($current_user_id),
    'orderby' => 'display_name',
    'order' => 'ASC'
));

// Filter out users who already have access
$available_users = array();
foreach ($all_users as $user) {
    // Skip admins
    if (in_array('administrator', $user->roles)) {
        continue;
    }
    
    // Skip if already has access
    if (in_array($user->ID, $editors) || in_array($user->ID, $viewers) || in_array($user->ID, $requests)) {
        continue;
    }
    
    $available_users[] = $user;
}

get_header();
?>

<style>
.permissions-manager {
    max-width: 1200px;
    margin: 40px auto;
    padding: 20px;
}

.permissions-manager h1 {
    color: #c84a31;
    margin-bottom: 10px;
}

.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #2271b1;
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 6px;
}

.alert-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.alert-error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.permissions-section {
    background: white;
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.permissions-section h2 {
    margin-top: 0;
    color: #333;
    font-size: 20px;
    border-bottom: 2px solid #c84a31;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.user-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.user-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
}

.user-item:last-child {
    border-bottom: none;
}

.user-info {
    flex: 1;
}

.user-name {
    font-weight: 600;
    font-size: 15px;
}

.user-role {
    font-size: 13px;
    color: #666;
    margin-left: 10px;
}

.user-actions {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 6px 15px;
    font-size: 13px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: #2271b1;
    color: white;
}

.btn-success {
    background: #00a32a;
    color: white;
}

.btn-danger {
    background: #d63638;
    color: white;
}

.btn-warning {
    background: #dba617;
    color: white;
}

.btn:hover {
    opacity: 0.9;
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #666;
    font-style: italic;
}

.grant-form {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 6px;
    margin-top: 20px;
}

.grant-form select {
    padding: 8px;
    margin-right: 10px;
    min-width: 200px;
}

.stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.stat-box {
    background: #f0f8ff;
    padding: 15px 20px;
    border-radius: 6px;
    border: 2px solid #2271b1;
    flex: 1;
    min-width: 150px;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #2271b1;
}

.stat-label {
    font-size: 14px;
    color: #666;
}
</style>

<div class="permissions-manager">
    <a href="<?php echo home_url('/recipe-manager/'); ?>" class="back-link">← Back to Recipe Manager</a>
    
    <h1>Manage Collection Permissions</h1>
    <p style="color: #666; margin-bottom: 30px;">Control who can access and manage your recipe collection</p>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?php echo esc_html($success_message); ?></div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-error">⚠️ <?php echo esc_html($error_message); ?></div>
    <?php endif; ?>
    
    <!-- Stats -->
    <?php $stats = get_collection_stats($current_user_id); ?>
    <div class="stats">
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats['recipes']; ?></div>
            <div class="stat-label">Recipes</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats['editors']; ?></div>
            <div class="stat-label">Co-Editors</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats['viewers']; ?></div>
            <div class="stat-label">Viewers</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats['pending_requests']; ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
    </div>
    
    <!-- Pending Requests -->
    <?php if (!empty($requests)): ?>
    <div class="permissions-section">
        <h2>⏳ Pending Access Requests</h2>
        <ul class="user-list">
            <?php foreach ($requests as $request_user_id): 
                $user = get_userdata($request_user_id);
                if (!$user) continue;
            ?>
            <li class="user-item">
                <div class="user-info">
                    <span class="user-name"><?php echo esc_html($user->display_name); ?></span>
                    <span class="user-role">(<?php echo esc_html($user->user_email); ?>)</span>
                </div>
                <div class="user-actions">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="approve_editor" class="btn btn-success">
                            Approve as Editor
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="approve_viewer" class="btn btn-primary">
                            Approve as Viewer
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="deny_request" class="btn btn-danger" 
                                onclick="return confirm('Deny this access request?')">
                            Deny
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Co-Editors -->
    <div class="permissions-section">
        <h2>✏️ Co-Editors (Full Access)</h2>
        <?php if (!empty($editors)): ?>
        <ul class="user-list">
            <?php foreach ($editors as $editor_id): 
                $user = get_userdata($editor_id);
                if (!$user) continue;
            ?>
            <li class="user-item">
                <div class="user-info">
                    <span class="user-name"><?php echo esc_html($user->display_name); ?></span>
                    <span class="user-role">(<?php echo esc_html($user->user_email); ?>)</span>
                </div>
                <div class="user-actions">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="demote_to_viewer" class="btn btn-warning" 
                                onclick="return confirm('Change to Viewer? They will lose edit access.')">
                            Change to Viewer
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="revoke_editor" class="btn btn-danger" 
                                onclick="return confirm('Remove access completely?')">
                            Remove Access
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty-state">No co-editors yet. Grant editor access to let others manage your recipes.</div>
        <?php endif; ?>
    </div>
    
    <!-- Viewers -->
    <div class="permissions-section">
        <h2>👁️ Viewers (Read-Only)</h2>
        <?php if (!empty($viewers)): ?>
        <ul class="user-list">
            <?php foreach ($viewers as $viewer_id): 
                $user = get_userdata($viewer_id);
                if (!$user) continue;
            ?>
            <li class="user-item">
                <div class="user-info">
                    <span class="user-name"><?php echo esc_html($user->display_name); ?></span>
                    <span class="user-role">(<?php echo esc_html($user->user_email); ?>)</span>
                </div>
                <div class="user-actions">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="promote_to_editor" class="btn btn-success">
                            Promote to Editor
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('manage_permissions'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                        <button type="submit" name="action" value="revoke_viewer" class="btn btn-danger" 
                                onclick="return confirm('Remove access?')">
                            Remove Access
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty-state">No viewers yet. Grant viewer access to let others see your recipes.</div>
        <?php endif; ?>
    </div>
    
    <!-- Grant New Access -->
    <?php if (!empty($available_users)): ?>
    <div class="permissions-section">
        <h2>➕ Grant Access to New User</h2>
        <div class="grant-form">
            <form method="post">
                <?php wp_nonce_field('manage_permissions'); ?>
                <select name="user_id" required>
                    <option value="">Select a user...</option>
                    <?php foreach ($available_users as $user): ?>
                        <option value="<?php echo $user->ID; ?>">
                            <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="action" value="grant_editor" class="btn btn-success">
                    Grant as Editor
                </button>
                <button type="submit" name="action" value="grant_viewer" class="btn btn-primary">
                    Grant as Viewer
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<?php get_footer(); ?>
