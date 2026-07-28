<?php
/**
 * Template Name: Allergen Profiles
 *
 * Manage allergen profiles — create/edit/delete, select allergens, set
 * active profile, share with other users (view/apply only — never edit).
 */

get_header();

// Any logged-in user may manage their own allergen profiles — this is not
// restricted to authors/editors like the recipe collection features are.
if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/'));
    exit;
}

require_once(get_stylesheet_directory() . '/allergen-functions.php');
require_once(get_stylesheet_directory() . '/allergen-permissions.php');

$current_user_id = get_current_user_id();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add new profile
    if (isset($_POST['add_profile'])) {
        check_admin_referer('add_profile');

        $profile_name = sanitize_text_field($_POST['profile_name']);
        $profile_age = isset($_POST['profile_age']) && $_POST['profile_age'] !== '' ? intval($_POST['profile_age']) : null;

        $result = create_profile($current_user_id, $profile_name, $profile_age);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = "Profile '{$profile_name}' created! Select its allergens below.";
            $message_type = 'success';
        }
    }

    // Save profile edits (name, age, and allergen selection together)
    if (isset($_POST['edit_profile'])) {
        $profile_id = intval($_POST['profile_id']);
        check_admin_referer('edit_profile_' . $profile_id);

        $profile_name = sanitize_text_field($_POST['profile_name']);
        $profile_age = isset($_POST['profile_age']) && $_POST['profile_age'] !== '' ? intval($_POST['profile_age']) : null;
        $allergen_ids = isset($_POST['allergen_ids']) ? array_map('intval', (array) $_POST['allergen_ids']) : array();

        $result = update_profile($profile_id, $current_user_id, $profile_name, $profile_age);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            set_profile_allergens($profile_id, $current_user_id, $allergen_ids);
            $message = "Profile '{$profile_name}' updated!";
            $message_type = 'success';
        }
    }

    // Delete profile
    if (isset($_POST['delete_profile'])) {
        $profile_id = intval($_POST['profile_id']);
        check_admin_referer('delete_profile_' . $profile_id);

        $result = delete_profile($profile_id, $current_user_id);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = 'Profile deleted.';
            $message_type = 'success';
        }
    }

    // Set active profile
    if (isset($_POST['set_active_profile'])) {
        $profile_id = intval($_POST['profile_id']);
        check_admin_referer('set_active_profile_' . $profile_id);

        if (set_active_allergen_profile($current_user_id, $profile_id)) {
            $message = 'Active profile updated.';
            $message_type = 'success';
        } else {
            $message = 'Could not set that profile as active.';
            $message_type = 'error';
        }
    }

    // Share a profile with another user
    if (isset($_POST['share_profile'])) {
        $profile_id = intval($_POST['profile_id']);
        check_admin_referer('share_profile_' . $profile_id);

        $target_user_id = intval($_POST['share_with_user_id']);
        $result = share_profile($profile_id, $current_user_id, $target_user_id);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = 'Profile shared.';
            $message_type = 'success';
        }
    }

    // Revoke a profile share
    if (isset($_POST['unshare_profile'])) {
        $profile_id = intval($_POST['profile_id']);
        check_admin_referer('unshare_profile_' . $profile_id);

        $target_user_id = intval($_POST['share_with_user_id']);
        $result = unshare_profile($profile_id, $current_user_id, $target_user_id);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = 'Sharing access revoked.';
            $message_type = 'success';
        }
    }

    // Add a custom allergen to the user's own library
    if (isset($_POST['add_custom_allergen'])) {
        check_admin_referer('add_custom_allergen');

        $allergen_name = sanitize_text_field($_POST['custom_allergen_name']);
        $alias_input = isset($_POST['custom_allergen_aliases']) ? sanitize_textarea_field($_POST['custom_allergen_aliases']) : '';
        $aliases = array_filter(array_map('trim', preg_split('/[,\n]/', $alias_input)));

        $result = create_custom_allergen($current_user_id, $allergen_name, $aliases);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = "Custom allergen '{$allergen_name}' added. Select it on any profile below.";
            $message_type = 'success';
        }
    }

    // Save edits to a custom allergen (name + alias set)
    if (isset($_POST['edit_custom_allergen'])) {
        $allergen_id = intval($_POST['allergen_id']);
        check_admin_referer('edit_custom_allergen_' . $allergen_id);

        $allergen_name = sanitize_text_field($_POST['custom_allergen_name']);
        $alias_input = isset($_POST['custom_allergen_aliases']) ? sanitize_textarea_field($_POST['custom_allergen_aliases']) : '';
        $aliases = array_filter(array_map('trim', preg_split('/[,\n]/', $alias_input)));

        $result = update_custom_allergen($allergen_id, $current_user_id, $allergen_name, $aliases);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = "Custom allergen '{$allergen_name}' updated.";
            $message_type = 'success';
        }
    }

    // Delete a custom allergen
    if (isset($_POST['delete_custom_allergen'])) {
        $allergen_id = intval($_POST['allergen_id']);
        check_admin_referer('delete_custom_allergen_' . $allergen_id);

        $result = delete_custom_allergen($allergen_id, $current_user_id);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = 'Custom allergen deleted and removed from any profiles that had it selected.';
            $message_type = 'success';
        }
    }
}

$profiles = get_user_profiles($current_user_id);
$seed_allergens = get_seed_allergens();
$custom_allergens = get_user_custom_allergens($current_user_id);
$active_profile = get_active_allergen_profile($current_user_id);
$active_profile_id = $active_profile ? $active_profile->profile_id : 0;

$shared_with_me = get_profiles_accessible_to_user($current_user_id)['shared'];

// Any registered user is a valid share recipient — allergen profiles are
// available to every logged-in user (not just authors/editors), so
// sharing isn't restricted by role the way collection sharing is.
$shareable_users = get_users(array('exclude' => array($current_user_id)));

?>

<style>
.allergen-profile-manager {
    max-width: 1200px;
    margin: 40px auto;
    padding: 0 20px;
}

.allergen-profile-manager h1 {
    color: #c84a31;
    font-size: 36px;
    margin-bottom: 10px;
}

.allergen-profile-manager-subtitle {
    color: #666;
    margin-bottom: 30px;
}

.allergen-disclaimer {
    background: #fff3cd;
    border: 2px solid #ffc107;
    color: #664d03;
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 25px;
    font-size: 14px;
}

.message {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.message.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.message.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.add-profile-section {
    background: #f9f9f9;
    padding: 20px;
    margin-bottom: 30px;
    border: 2px solid #c84a31;
    border-radius: 4px;
}

.add-profile-section h2 {
    color: #c84a31;
    font-size: 20px;
    margin-bottom: 15px;
}

.add-profile-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.add-profile-form input[type="text"],
.add-profile-form input[type="number"] {
    padding: 10px;
    font-size: 14px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.add-profile-form input[type="text"] {
    flex: 1;
    min-width: 200px;
}

.add-profile-form input[type="number"] {
    width: 90px;
}

.add-profile-form button {
    padding: 10px 20px;
    background: #00a32a;
    color: white;
    border: none;
    border-radius: 3px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

.add-profile-form button:hover {
    background: #008a20;
}

.profiles-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.profiles-table thead {
    background: #c84a31;
    color: white;
}

.profiles-table th {
    padding: 12px;
    text-align: left;
    font-weight: bold;
}

.profiles-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
}

.profiles-table tbody tr:hover {
    background: #f9f9f9;
}

.active-badge {
    display: inline-block;
    background: #7c3aed;
    color: white;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: bold;
    margin-left: 8px;
}

.edit-row {
    display: none;
}

.edit-row.active {
    display: table-row;
}

div.edit-row.active {
    display: block;
}

.edit-form-fields {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

.edit-form-fields input[type="text"] {
    flex: 1;
    min-width: 200px;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.edit-form-fields input[type="number"] {
    width: 90px;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.allergen-checklist-group {
    margin-bottom: 12px;
}

.allergen-checklist-group h4 {
    margin: 0 0 6px 0;
    font-size: 13px;
    text-transform: uppercase;
    color: #666;
}

.allergen-checklist {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
}

.allergen-checklist label {
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn {
    padding: 6px 12px;
    font-size: 13px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-edit { background: #0073aa; color: white; }
.btn-delete { background: #d63638; color: white; }
.btn-save { background: #00a32a; color: white; }
.btn-cancel { background: #666; color: white; }
.btn-activate { background: #7c3aed; color: white; }

.btn:hover { opacity: 0.8; }

.custom-allergen-section {
    background: #f9f9f9;
    padding: 20px;
    border: 2px solid #dee2e6;
    border-radius: 4px;
    margin-bottom: 30px;
}

.custom-allergen-section h2 {
    font-size: 18px;
    margin-bottom: 10px;
    color: #495057;
}

.custom-allergen-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-start;
}

.custom-allergen-form input[type="text"] {
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    min-width: 180px;
}

.custom-allergen-form textarea {
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    flex: 1;
    min-width: 220px;
    min-height: 40px;
}

.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #0073aa;
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
}
</style>

<div class="allergen-profile-manager">
    <a href="<?php echo home_url('/recipe-manager/'); ?>" class="back-link">← Back to Recipe Manager</a>

    <h1>Allergen Profiles</h1>
    <p class="allergen-profile-manager-subtitle">Create and manage allergen profiles used by the Allergen Checker.</p>

    <div class="allergen-disclaimer">
        This tool assists in reading ingredient labels and recipes. It does not replace reading the label yourself. Always verify against the physical product before consuming.
    </div>

    <?php if ($message): ?>
    <div class="message <?php echo esc_attr($message_type); ?>">
        <?php echo esc_html($message); ?>
    </div>
    <?php endif; ?>

    <!-- Add New Profile -->
    <div class="add-profile-section">
        <h2>Add New Profile</h2>
        <form method="post" class="add-profile-form">
            <?php wp_nonce_field('add_profile'); ?>
            <input type="text" name="profile_name" placeholder="Person's name (e.g., Jamie)" required />
            <input type="number" name="profile_age" placeholder="Age" min="0" max="130" />
            <button type="submit" name="add_profile">+ Add Profile</button>
        </form>
    </div>

    <!-- Add Custom Allergen -->
    <div class="custom-allergen-section">
        <h2>Add a Custom Allergen</h2>
        <p style="font-size: 13px; color: #666; margin-top: 0;">Not on the standard list? Add your own — it'll be available to select on any of your profiles.</p>
        <form method="post" class="custom-allergen-form">
            <?php wp_nonce_field('add_custom_allergen'); ?>
            <input type="text" name="custom_allergen_name" placeholder="Allergen name (e.g., Kiwi)" required />
            <textarea name="custom_allergen_aliases" placeholder="Optional aliases, comma or newline separated (e.g., kiwifruit, actinidia)"></textarea>
            <button type="submit" name="add_custom_allergen" class="btn" style="background: #6c757d; color: white;">+ Add Allergen</button>
        </form>

        <?php if (!empty($custom_allergens)): ?>
        <div style="margin-top: 20px;">
            <h4 style="font-size: 13px; text-transform: uppercase; color: #666; margin-bottom: 8px;">Your Custom Allergens</h4>
            <?php foreach ($custom_allergens as $custom_allergen):
                $custom_aliases = get_allergen_aliases($custom_allergen->allergen_id);
                $custom_alias_texts = wp_list_pluck($custom_aliases, 'alias_text');
                $custom_alias_display = array_values(array_diff($custom_alias_texts, array(strtolower($custom_allergen->allergen_name))));
            ?>
            <!-- View Row -->
            <div id="view-allergen-<?php echo $custom_allergen->allergen_id; ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee;">
                <div>
                    <strong><?php echo esc_html($custom_allergen->allergen_name); ?></strong>
                    <?php if (!empty($custom_alias_display)): ?>
                    <span style="color: #999; font-size: 13px;">(<?php echo esc_html(implode(', ', $custom_alias_display)); ?>)</span>
                    <?php endif; ?>
                </div>
                <div>
                    <button type="button" onclick="editAllergen(<?php echo $custom_allergen->allergen_id; ?>)" class="btn btn-edit">✏️ Edit</button>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('delete_custom_allergen_' . $custom_allergen->allergen_id); ?>
                        <input type="hidden" name="allergen_id" value="<?php echo $custom_allergen->allergen_id; ?>">
                        <button
                            type="submit"
                            name="delete_custom_allergen"
                            class="btn btn-delete"
                            onclick="return confirm('Delete allergen \'<?php echo esc_js($custom_allergen->allergen_name); ?>\'? It will be removed from any profiles that have it selected.')"
                        >🗑️ Delete</button>
                    </form>
                </div>
            </div>

            <!-- Edit Row -->
            <div id="edit-allergen-<?php echo $custom_allergen->allergen_id; ?>" class="edit-row" style="padding: 10px 0; border-bottom: 1px solid #eee;">
                <form method="post">
                    <?php wp_nonce_field('edit_custom_allergen_' . $custom_allergen->allergen_id); ?>
                    <input type="hidden" name="allergen_id" value="<?php echo $custom_allergen->allergen_id; ?>">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px;">
                        <input type="text" name="custom_allergen_name" value="<?php echo esc_attr($custom_allergen->allergen_name); ?>" style="flex: 1; min-width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 3px;" required />
                        <textarea name="custom_allergen_aliases" style="flex: 2; min-width: 220px; padding: 8px; border: 1px solid #ddd; border-radius: 3px; min-height: 40px;" placeholder="Aliases, comma or newline separated"><?php echo esc_textarea(implode(', ', $custom_alias_display)); ?></textarea>
                    </div>
                    <button type="submit" name="edit_custom_allergen" class="btn btn-save">✓ Save</button>
                    <button type="button" onclick="cancelEditAllergen(<?php echo $custom_allergen->allergen_id; ?>)" class="btn btn-cancel">Cancel</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Profiles List -->
    <table class="profiles-table">
        <thead>
            <tr>
                <th>Profile</th>
                <th>Age</th>
                <th>Allergens</th>
                <th style="width: 260px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($profiles)): ?>
                <?php foreach ($profiles as $profile):
                    $profile_allergens = get_profile_allergens($profile->profile_id);
                    $selected_ids = wp_list_pluck($profile_allergens, 'allergen_id');
                    $is_active = ($profile->profile_id == $active_profile_id);
                ?>
                <!-- View Row -->
                <tr id="view-<?php echo $profile->profile_id; ?>">
                    <td>
                        <strong><?php echo esc_html($profile->profile_name); ?></strong>
                        <?php if ($is_active): ?><span class="active-badge">Active</span><?php endif; ?>
                    </td>
                    <td><?php echo $profile->profile_age !== null ? esc_html($profile->profile_age) : '—'; ?></td>
                    <td>
                        <?php
                        if (!empty($profile_allergens)) {
                            echo esc_html(implode(', ', wp_list_pluck($profile_allergens, 'allergen_name')));
                        } else {
                            echo '<span style="color: #999;">None selected</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <button type="button" onclick="editProfile(<?php echo $profile->profile_id; ?>)" class="btn btn-edit">✏️ Edit</button>

                        <?php if (!$is_active): ?>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('set_active_profile_' . $profile->profile_id); ?>
                            <input type="hidden" name="profile_id" value="<?php echo $profile->profile_id; ?>">
                            <button type="submit" name="set_active_profile" class="btn btn-activate">Set Active</button>
                        </form>
                        <?php endif; ?>

                        <button type="button" onclick="toggleShare(<?php echo $profile->profile_id; ?>)" class="btn" style="background: #7c3aed; color: white;">🔗 Share</button>

                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('delete_profile_' . $profile->profile_id); ?>
                            <input type="hidden" name="profile_id" value="<?php echo $profile->profile_id; ?>">
                            <button
                                type="submit"
                                name="delete_profile"
                                class="btn btn-delete"
                                onclick="return confirm('Delete profile \'<?php echo esc_js($profile->profile_name); ?>\'?')"
                            >🗑️ Delete</button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Row -->
                <tr id="edit-<?php echo $profile->profile_id; ?>" class="edit-row">
                    <td colspan="4">
                        <form method="post">
                            <?php wp_nonce_field('edit_profile_' . $profile->profile_id); ?>
                            <input type="hidden" name="profile_id" value="<?php echo $profile->profile_id; ?>">

                            <div class="edit-form-fields">
                                <input type="text" name="profile_name" value="<?php echo esc_attr($profile->profile_name); ?>" required />
                                <input type="number" name="profile_age" value="<?php echo esc_attr($profile->profile_age); ?>" placeholder="Age" min="0" max="130" />
                            </div>

                            <div class="allergen-checklist-group">
                                <h4>Standard Allergens</h4>
                                <div class="allergen-checklist">
                                    <?php foreach ($seed_allergens as $allergen): ?>
                                    <label>
                                        <input type="checkbox" name="allergen_ids[]" value="<?php echo $allergen->allergen_id; ?>" <?php checked(in_array($allergen->allergen_id, $selected_ids)); ?>>
                                        <?php echo esc_html($allergen->allergen_name); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if (!empty($custom_allergens)): ?>
                            <div class="allergen-checklist-group">
                                <h4>Your Custom Allergens</h4>
                                <div class="allergen-checklist">
                                    <?php foreach ($custom_allergens as $allergen): ?>
                                    <label>
                                        <input type="checkbox" name="allergen_ids[]" value="<?php echo $allergen->allergen_id; ?>" <?php checked(in_array($allergen->allergen_id, $selected_ids)); ?>>
                                        <?php echo esc_html($allergen->allergen_name); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <button type="submit" name="edit_profile" class="btn btn-save">✓ Save</button>
                            <button type="button" onclick="cancelEditProfile(<?php echo $profile->profile_id; ?>)" class="btn btn-cancel">Cancel</button>
                        </form>
                    </td>
                </tr>

                <!-- Share Row -->
                <tr id="share-<?php echo $profile->profile_id; ?>" class="edit-row">
                    <td colspan="4">
                        <h4 style="margin: 0 0 8px 0; font-size: 13px; text-transform: uppercase; color: #666;">Shared With</h4>
                        <?php
                        $current_shares = get_profile_shares($profile->profile_id);
                        if (!empty($current_shares)):
                        ?>
                        <div style="margin-bottom: 12px;">
                            <?php foreach ($current_shares as $shared_user): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid #eee;">
                                <span><?php echo esc_html($shared_user->display_name); ?></span>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('unshare_profile_' . $profile->profile_id); ?>
                                    <input type="hidden" name="profile_id" value="<?php echo $profile->profile_id; ?>">
                                    <input type="hidden" name="share_with_user_id" value="<?php echo $shared_user->ID; ?>">
                                    <button type="submit" name="unshare_profile" class="btn btn-delete" style="padding: 3px 10px; font-size: 12px;">Revoke</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p style="color: #999; font-size: 13px;">Not shared with anyone yet.</p>
                        <?php endif; ?>

                        <?php if (!empty($shareable_users)): ?>
                        <form method="post" style="display: flex; gap: 8px; align-items: center;">
                            <?php wp_nonce_field('share_profile_' . $profile->profile_id); ?>
                            <input type="hidden" name="profile_id" value="<?php echo $profile->profile_id; ?>">
                            <select name="share_with_user_id" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 3px;">
                                <?php foreach ($shareable_users as $user): ?>
                                <option value="<?php echo $user->ID; ?>"><?php echo esc_html($user->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="share_profile" class="btn" style="background: #7c3aed; color: white;">Share</button>
                        </form>
                        <?php endif; ?>

                        <div style="margin-top: 10px;">
                            <button type="button" onclick="toggleShare(<?php echo $profile->profile_id; ?>)" class="btn btn-cancel">Close</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="4" style="text-align: center; padding: 40px;">
                    No profiles yet. Add one above to get started.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($shared_with_me)): ?>
    <h2 style="margin-top: 30px;">Shared With Me</h2>
    <p style="color: #666; font-size: 14px; margin-top: 0;">You can view and set these active, but only their creator can edit the allergen list.</p>
    <table class="profiles-table">
        <thead>
            <tr>
                <th>Profile</th>
                <th>Age</th>
                <th>Allergens</th>
                <th style="width: 160px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shared_with_me as $profile):
                $profile_allergens = get_profile_allergens($profile->profile_id);
                $is_active = ($profile->profile_id == $active_profile_id);
                $owner = get_userdata($profile->owner_user_id);
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($profile->profile_name); ?></strong>
                    <?php if ($is_active): ?><span class="active-badge">Active</span><?php endif; ?>
                    <div style="color: #999; font-size: 12px;">Shared by <?php echo esc_html($owner ? $owner->display_name : 'Unknown'); ?></div>
                </td>
                <td><?php echo $profile->profile_age !== null ? esc_html($profile->profile_age) : '—'; ?></td>
                <td>
                    <?php
                    if (!empty($profile_allergens)) {
                        echo esc_html(implode(', ', wp_list_pluck($profile_allergens, 'allergen_name')));
                    } else {
                        echo '<span style="color: #999;">None selected</span>';
                    }
                    ?>
                </td>
                <td>
                    <?php if (!$is_active): ?>
                    <form method="post">
                        <?php wp_nonce_field('set_active_profile_' . $profile->profile_id); ?>
                        <input type="hidden" name="profile_id" value="<?php echo $profile->profile_id; ?>">
                        <button type="submit" name="set_active_profile" class="btn btn-activate">Set Active</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
function toggleShare(profileId) {
    const row = document.getElementById('share-' + profileId);
    row.classList.toggle('active');
}

function editProfile(profileId) {
    document.getElementById('view-' + profileId).style.display = 'none';
    document.getElementById('edit-' + profileId).classList.add('active');
}

function cancelEditProfile(profileId) {
    document.getElementById('view-' + profileId).style.display = 'table-row';
    document.getElementById('edit-' + profileId).classList.remove('active');
}

function editAllergen(allergenId) {
    document.getElementById('view-allergen-' + allergenId).style.display = 'none';
    document.getElementById('edit-allergen-' + allergenId).classList.add('active');
}

function cancelEditAllergen(allergenId) {
    document.getElementById('view-allergen-' + allergenId).style.display = 'flex';
    document.getElementById('edit-allergen-' + allergenId).classList.remove('active');
}
</script>

<?php get_footer(); ?>
