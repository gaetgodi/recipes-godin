<?php
/**
 * Anthropic Model Health Check
 *
 * Weekly check that the configured ANTHROPIC_MODEL is still valid.
 * Sends an email warning if the model is retired (live API call fails)
 * or found deprecated/retiring on Anthropic's status page.
 *
 * @version 1.1.0
 * @changelog
 *   1.1.0 - Fixed false positive: Check 2 now requires "Deprecated" or "Retired/Retiring"
 *            in the surrounding context AND absence of "Active" near the model name,
 *            instead of flagging on mere presence of the model name on the page.
 *            (Every active model is also listed on that page, so presence alone
 *            was triggering warnings for healthy models.)
 *   1.0.0 - Initial release.
 */

if (!defined('ABSPATH')) exit;

// Schedule the weekly check
add_action('wp', 'recipe_model_health_check_schedule');
function recipe_model_health_check_schedule() {
    if (!wp_next_scheduled('recipe_model_health_check_event')) {
        wp_schedule_event(time(), 'weekly', 'recipe_model_health_check_event');
    }
}

add_action('recipe_model_health_check_event', 'recipe_model_health_check_run');

function recipe_model_health_check_run() {
    if (!defined('ANTHROPIC_API_KEY') || !defined('ANTHROPIC_MODEL')) {
        return;
    }

    $model = ANTHROPIC_MODEL;
    $site_name = home_url();
    $issues = array();

    // --- Check 1: Live test call ---
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 20,
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-api-key' => ANTHROPIC_API_KEY,
            'anthropic-version' => '2023-06-01'
        ),
        'body' => json_encode(array(
            'model' => $model,
            'max_tokens' => 1,
            'messages' => array(
                array('role' => 'user', 'content' => 'hi')
            )
        ))
    ));

    if (is_wp_error($response)) {
        $issues[] = "Live test call failed (network error): " . $response->get_error_message();
    } else {
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
            $issues[] = "Live test call FAILED — model may be retired: " . $error_msg;
        }
    }

    // --- Check 2: Anthropic deprecations page scrape ---
    // Note: every active model is also listed on this page, so we can't just
    // check for presence of the model name. We require "Deprecated" or
    // "Retired"/"Retiring" to appear near the model name, AND that "Active"
    // does NOT appear immediately after it (which would mean it's the
    // Active-status row, not a Deprecated one).
    $docs_response = wp_remote_get('https://platform.claude.com/docs/en/about-claude/model-deprecations', array(
        'timeout' => 20
    ));

    if (!is_wp_error($docs_response)) {
        $docs_body = wp_remote_retrieve_body($docs_response);
        if (strpos($docs_body, $model) !== false) {
            $pos = strpos($docs_body, $model);
            $context = substr($docs_body, max(0, $pos - 100), 600);
            $context_clean = wp_strip_all_tags($context);

            // Look only at the text immediately following the model name —
            // that's where the status column value appears on the real page.
            $after_name = substr($context_clean, strpos($context_clean, $model) + strlen($model), 200);

            $looks_active = preg_match('/\bActive\b/i', $after_name);
            $looks_deprecated = preg_match('/\b(Deprecated|Retired|Retiring)\b/i', $after_name);

            if ($looks_deprecated && !$looks_active) {
                $issues[] = "Model '$model' appears DEPRECATED/RETIRING on Anthropic's status page. Context:\n" . $context_clean;
            }
        }
    }

    // --- Send warning email if anything was found ---
    if (!empty($issues)) {
        $admin_email = get_option('admin_email');
        $subject = "⚠️ Anthropic Model Warning — $site_name";
        $message = "The Anthropic model currently configured for this site may need attention:\n\n";
        $message .= "Site: $site_name\n";
        $message .= "Model in use (ANTHROPIC_MODEL): $model\n";
        $message .= "Defined in: wp-config.php\n";
        $message .= "Used by: recipe-image-upload-handler.php (OCR extraction, text extraction, translation)\n\n";
        $message .= "Findings:\n\n" . implode("\n\n---\n\n", $issues);
        $message .= "\n\nAction needed: update ANTHROPIC_MODEL in wp-config.php to a current model string, ";
        $message .= "then redeploy. Check https://platform.claude.com/docs/en/about-claude/model-deprecations for the current recommended replacement.";

        wp_mail($admin_email, $subject, $message);
    }
}

// Clean up the scheduled event if this file is ever removed
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('recipe_model_health_check_event');
});