<?php
/**
 * Allergen Checker — Product Image Upload and OCR Handler
 *
 * Handles photo upload and Claude API OCR extraction for the product
 * library. Deliberately new sibling functions rather than a parameterized
 * extract_recipe_from_image() (recipe-image-upload-handler.php) — that
 * function's prompt and parser are hard-shaped around
 * TITLE:/INGREDIENTS:/METHOD: and already have three call sites assuming
 * recipe shape. This file follows the same wp_remote_post/ANTHROPIC_MODEL
 * pattern the recipe handler already triplicates internally, rather than
 * introducing a shared abstraction it doesn't use itself.
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * AJAX Handler: Upload a product label photo and extract product name +
 * ingredient list via Claude API.
 *
 * Gated on is_user_logged_in(), not current_user_can('edit_posts') — the
 * allergen product library is available to any logged-in user, same as
 * allergen profiles, not restricted to authors/editors like recipe OCR is.
 */
add_action('wp_ajax_upload_allergen_product_image', 'handle_allergen_product_image_upload');

function handle_allergen_product_image_upload() {
    check_ajax_referer('allergen_product_image_upload', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    if (empty($_FILES['product_image'])) {
        wp_send_json_error(array('message' => 'No image file provided'));
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    // Not attached to any post — products aren't a CPT.
    $attachment_id = media_handle_upload('product_image', 0);

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => 'Error uploading image: ' . $attachment_id->get_error_message()));
    }

    $image_url = wp_get_attachment_url($attachment_id);
    $image_path = get_attached_file($attachment_id);

    $image_data = file_get_contents($image_path);
    $base64_image = base64_encode($image_data);

    $image_type = wp_check_filetype($image_path);
    $mime_type = $image_type['type'];

    $extraction_result = extract_product_from_image($base64_image, $mime_type);

    if ($extraction_result['success']) {
        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'image_url' => $image_url,
            'extracted_data' => $extraction_result['data'],
            'raw_response' => $extraction_result['raw'],
        ));
    } else {
        wp_send_json_error(array(
            'message' => $extraction_result['error'],
            'attachment_id' => $attachment_id,
            'image_url' => $image_url,
        ));
    }
}

/**
 * Extract product name + ingredient list from a product label image using
 * the Claude API.
 *
 * @param string $base64_image  Base64-encoded image data
 * @param string $mime_type     MIME type of the image
 */
function extract_product_from_image($base64_image, $mime_type) {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        return array(
            'success' => false,
            'error' => 'Anthropic API key not configured. Please add your API key to wp-config.php'
        );
    }

    $api_key = ANTHROPIC_API_KEY;

    $prompt = 'Please extract product information from this image of a packaged food product\'s label. Extract:

1. Product name (if visible)
2. The full ingredient list, exactly as printed, one ingredient per line — including any "may contain," "processed in a facility that also processes," or similar precautionary statements exactly as worded.

Format your response EXACTLY like this:

PRODUCT_NAME: [product name, or "Unknown Product" if not visible]

INGREDIENTS:
[ingredient 1]
[ingredient 2]
[etc.]

If there is NO ingredient list visible in this image, respond with exactly: "NO_INGREDIENTS_FOUND"';

    $request_body = array(
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 2000,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => array(
                    array(
                        'type' => 'image',
                        'source' => array(
                            'type' => 'base64',
                            'media_type' => $mime_type,
                            'data' => $base64_image
                        )
                    ),
                    array(
                        'type' => 'text',
                        'text' => $prompt
                    )
                )
            )
        )
    );

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        ),
        'body' => json_encode($request_body)
    ));

    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'error' => 'API request failed: ' . $response->get_error_message()
        );
    }

    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    $data = json_decode($body, true);

    if ($http_code !== 200) {
        $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
        return array(
            'success' => false,
            'error' => 'API Error: ' . $error_msg . ' (Full response: ' . substr($body, 0, 500) . ')'
        );
    }

    if (empty($data['content'][0]['text'])) {
        return array(
            'success' => false,
            'error' => 'No response from Claude API. Response: ' . substr($body, 0, 500)
        );
    }

    $extracted_text = $data['content'][0]['text'];

    if (trim($extracted_text) === 'NO_INGREDIENTS_FOUND') {
        return array(
            'success' => true,
            'data' => array(
                'product_name' => '',
                'ingredient_text' => '',
                'found' => false
            ),
            'raw' => 'No ingredient list found in image'
        );
    }

    $parsed = parse_product_extraction($extracted_text);
    $parsed['found'] = true;

    return array(
        'success' => true,
        'data' => $parsed,
        'raw' => $extracted_text
    );
}

/**
 * Parse Claude's formatted product-label response into structured data.
 */
function parse_product_extraction($text) {
    $result = array(
        'product_name' => '',
        'ingredient_text' => ''
    );

    if (preg_match('/PRODUCT_NAME:\s*(.+?)(?=\n|$)/i', $text, $matches)) {
        $result['product_name'] = trim($matches[1]);
    }

    if (preg_match('/INGREDIENTS:\s*\n(.+)$/is', $text, $matches)) {
        $ingredients = trim($matches[1]);
        $ingredients = preg_replace('/^[-•*]\s*/m', '', $ingredients);
        $result['ingredient_text'] = $ingredients;
    }

    return $result;
}
