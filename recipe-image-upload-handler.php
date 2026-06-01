<?php
/**
 * Recipe Image Upload and OCR Handler
 * Handles featured image upload and Claude API OCR extraction
 *
 * @version 2.1.1
 * @changelog
 *   2.1.1 - Interpretation mode now generates a descriptive English title instead of
 *            "Untitled Recipe". Parser no longer strips the title. Both image and text
 *            interpretation prompts updated.
 *   2.1.0 - translate_recipe_to_language() now accepts and translates notes.
 *            Notes translation is returned separately so JS can prepend translated
 *            notes above original notes with a divider.
 *   2.0.0 - Added interpretation mode: fills recipe gaps using culinary reasoning,
 *            tags all inferred content with [inferred], supports non-Latin scripts.
 *            Both image and text extraction handlers support the new mode.
 *            Strict extraction mode now marks illegible/crossed-out content with [unclear].
 *            Translation preserves [inferred] and [unclear] tags.
 *   1.0.0 - Initial release: image OCR, text extraction, translation (18 languages).
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * AJAX Handler: Upload image and extract recipe via Claude API
 */
add_action('wp_ajax_upload_recipe_image', 'handle_recipe_image_upload');

function handle_recipe_image_upload() {
    // Verify nonce
    check_ajax_referer('recipe_image_upload', 'nonce');
    
    // Check permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    // Check if file was uploaded
    if (empty($_FILES['recipe_image'])) {
        wp_send_json_error(array('message' => 'No image file provided'));
    }
    
    // Get recipe ID if editing existing recipe
    $recipe_id = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;

    // Get interpretation mode flag (default: false = strict extraction)
    $interpretation_mode = !empty($_POST['interpretation_mode']) && $_POST['interpretation_mode'] === '1';
    
    // Upload the image to WordPress media library
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $attachment_id = media_handle_upload('recipe_image', $recipe_id);
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => 'Error uploading image: ' . $attachment_id->get_error_message()));
    }
    
    // Get the uploaded image URL
    $image_url = wp_get_attachment_url($attachment_id);
    $image_path = get_attached_file($attachment_id);
    
    // Convert image to base64 for Claude API
    $image_data = file_get_contents($image_path);
    $base64_image = base64_encode($image_data);
    
    // Determine image type
    $image_type = wp_check_filetype($image_path);
    $mime_type = $image_type['type'];
    
    // Call Claude API for OCR
    $extraction_result = extract_recipe_from_image($base64_image, $mime_type, $interpretation_mode);
    
    if ($extraction_result['success']) {
        // Set as featured image if recipe ID provided
        if ($recipe_id > 0) {
            set_post_thumbnail($recipe_id, $attachment_id);
        }
        
        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'image_url' => $image_url,
            'extracted_data' => $extraction_result['data'],
            'raw_response' => $extraction_result['raw'],
            'interpretation_mode' => $interpretation_mode
        ));
    } else {
        wp_send_json_error(array(
            'message' => $extraction_result['error'],
            'attachment_id' => $attachment_id,
            'image_url' => $image_url
        ));
    }
}

/**
 * Extract recipe data from image using Claude API
 *
 * @param string $base64_image   Base64-encoded image data
 * @param string $mime_type      MIME type of the image
 * @param bool   $interpretation_mode
 *   false (default) — strict extraction: preserve original wording, flag unclear parts,
 *                     leave gaps where content is missing or crossed out.
 *   true            — interpretation mode: fill in gaps using culinary reasoning,
 *                     tag all inferred content with [inferred].
 */
function extract_recipe_from_image($base64_image, $mime_type, $interpretation_mode = false) {
    // Get API key from wp-config.php
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        return array(
            'success' => false,
            'error' => 'Anthropic API key not configured. Please add your API key to wp-config.php'
        );
    }
    
    $api_key = ANTHROPIC_API_KEY;

    // Choose prompt based on mode
    if ($interpretation_mode) {
        $prompt = 'Please extract and interpret the recipe from this image. The source may be handwritten, incomplete, in a non-Latin script, or have crossed-out or scribbled sections.

Your job:
1. Extract what is clearly written (ingredients, quantities, steps).
2. Ignore crossed-out or scribbled content.
3. Where the recipe appears incomplete or a step is only hinted at, use culinary reasoning to fill in the gap — but TAG every inferred addition with [inferred].
4. Translate non-English or non-Latin script content into English.
5. Keep the original meaning and proportions; do not substitute ingredients.
6. For the title: if no title is visible, create a short descriptive English title based on the main ingredient and cuisine style (e.g. "Gujarati Soybean Curry", "Spiced Chickpea Stew"). Never use "Untitled Recipe".

Format your response EXACTLY like this:

TITLE: [descriptive English title]

INGREDIENTS:
[ingredient 1]
[ingredient 2]
[etc.]

METHOD:
[step 1]
[step 2]
[etc.]

Example of tagging: "Simmer for 20 minutes [inferred] until chickpeas are tender [inferred]."

If there is NO recipe visible in this image, respond with exactly: "NO_RECIPE_FOUND"';
    } else {
        $prompt = 'Please extract the recipe from this image. If this is a handwritten or printed recipe, extract:

1. Recipe title (if visible)
2. Ingredients list (one per line)
3. Method/Instructions (one step per line)

Format your response EXACTLY like this:

TITLE: [recipe title or "Untitled Recipe" if not visible]

INGREDIENTS:
[ingredient 1]
[ingredient 2]
[etc.]

METHOD:
[step 1]
[step 2]
[etc.]

If there is NO recipe visible in this image, respond with exactly: "NO_RECIPE_FOUND"

Be accurate with measurements and spelling. Preserve the original wording as much as possible.
If any part is illegible or crossed out, note it with [unclear] rather than guessing.';
    }
    
    // Prepare the API request
    $request_body = array(
        'model' => 'claude-sonnet-4-20250514',
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
    
    // Make API request
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
    
    // Better error reporting
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
    
    // Check if no recipe found
    if (trim($extracted_text) === 'NO_RECIPE_FOUND') {
        return array(
            'success' => true,
            'data' => array(
                'title' => '',
                'ingredients' => '',
                'method' => '',
                'found' => false
            ),
            'raw' => 'No recipe interpreted in featured image'
        );
    }
    
    // Parse the response
    $parsed = parse_recipe_extraction($extracted_text);
    $parsed['found'] = true;
    
    return array(
        'success' => true,
        'data' => $parsed,
        'raw' => $extracted_text
    );
}

/**
 * Parse Claude's formatted response into structured data
 */
function parse_recipe_extraction($text) {
    $result = array(
        'title' => '',
        'ingredients' => '',
        'method' => ''
    );
    
    // Extract title
    if (preg_match('/TITLE:\s*(.+?)(?=\n|$)/i', $text, $matches)) {
        $result['title'] = trim($matches[1]);
    }
    
    // Extract ingredients
    if (preg_match('/INGREDIENTS:\s*\n(.+?)(?=\n\s*METHOD:|$)/is', $text, $matches)) {
        $ingredients = trim($matches[1]);
        // Clean up and format
        $ingredients = preg_replace('/^[-•*]\s*/m', '', $ingredients);
        $result['ingredients'] = $ingredients;
    }
    
    // Extract method
    if (preg_match('/METHOD:\s*\n(.+?)$/is', $text, $matches)) {
        $method = trim($matches[1]);
        // Clean up and format
        $method = preg_replace('/^\d+[\.)]\s*/m', '', $method);
        $result['method'] = $method;
    }
    
    return $result;
}

/**
 * Translate extracted recipe text to any language using Claude API
 */
function translate_recipe_to_language($title, $ingredients, $method, $target_language = 'English', $notes = '') {
    // Get API key from wp-config.php
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        return array(
            'success' => false,
            'error' => 'Anthropic API key not configured'
        );
    }
    
    $api_key = ANTHROPIC_API_KEY;
    
    // Prepare the translation request
    $text_to_translate = "TITLE: $title\n\nINGREDIENTS:\n$ingredients\n\nMETHOD:\n$method";
    if (!empty($notes)) {
        $text_to_translate .= "\n\nNOTES:\n$notes";
    }
    
    $request_body = array(
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 2000,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => "Please translate this recipe to $target_language. Maintain the exact same format (TITLE:, INGREDIENTS:, METHOD:, and NOTES: if present). If it's already in $target_language, just return it as-is. Preserve any [inferred] or [unclear] tags as-is.\n\n" . $text_to_translate
            )
        )
    );
    
    // Make API request
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
            'error' => 'Translation request failed: ' . $response->get_error_message()
        );
    }
    
    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    $data = json_decode($body, true);
    
    if ($http_code !== 200) {
        $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
        return array(
            'success' => false,
            'error' => 'Translation API Error: ' . $error_msg
        );
    }
    
    if (empty($data['content'][0]['text'])) {
        return array(
            'success' => false,
            'error' => 'No translation response received'
        );
    }
    
    $translated_text = $data['content'][0]['text'];
    
    // Parse the translated response
    $parsed = parse_recipe_extraction($translated_text);
    
    // Extract notes if present
    if (preg_match('/NOTES:\s*\n(.+?)$/is', $translated_text, $matches)) {
        $parsed['notes'] = trim($matches[1]);
    } else {
        $parsed['notes'] = '';
    }
    
    return array(
        'success' => true,
        'data' => $parsed
    );
}

/**
 * AJAX Handler: Translate recipe text to any language
 */
add_action('wp_ajax_translate_recipe', 'handle_recipe_translation');

function handle_recipe_translation() {
    // Verify nonce
    check_ajax_referer('recipe_translation', 'nonce');
    
    // Check permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $ingredients = isset($_POST['ingredients']) ? sanitize_textarea_field($_POST['ingredients']) : '';
    $method = isset($_POST['method']) ? sanitize_textarea_field($_POST['method']) : '';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $target_language = isset($_POST['target_language']) ? sanitize_text_field($_POST['target_language']) : 'English';
    
    if (empty($title) && empty($ingredients) && empty($method)) {
        wp_send_json_error(array('message' => 'No text to translate'));
    }
    
    // Call translation function with target language
    $result = translate_recipe_to_language($title, $ingredients, $method, $target_language, $notes);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'translated_data' => $result['data']
        ));
    } else {
        wp_send_json_error(array(
            'message' => $result['error']
        ));
    }
}

/**
 * AJAX Handler: Extract recipe from plain text
 */
add_action('wp_ajax_extract_recipe_from_text', 'handle_text_recipe_extraction');

function handle_text_recipe_extraction() {
    // Verify nonce
    check_ajax_referer('recipe_text_extract', 'nonce');
    
    // Check permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $text_content = isset($_POST['text_content']) ? sanitize_textarea_field($_POST['text_content']) : '';

    // Get interpretation mode flag (default: false = strict extraction)
    $interpretation_mode = !empty($_POST['interpretation_mode']) && $_POST['interpretation_mode'] === '1';
    
    if (empty($text_content)) {
        wp_send_json_error(array('message' => 'No text provided'));
    }
    
    // Get API key from wp-config.php
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        wp_send_json_error(array('message' => 'Anthropic API key not configured'));
    }
    
    $api_key = ANTHROPIC_API_KEY;

    // Choose prompt based on mode
    if ($interpretation_mode) {
        $prompt = 'Please extract and interpret this recipe text. The source may be incomplete, in a non-Latin script, or have notes and cross-outs mixed in.

Your job:
1. Extract what is clearly written (ingredients, quantities, steps).
2. Where the recipe appears incomplete or a step is only hinted at, use culinary reasoning to fill in the gap — but TAG every inferred addition with [inferred].
3. Translate non-English or non-Latin script content into English.
4. Keep the original meaning and proportions; do not substitute ingredients.
5. For the title: if no title is visible, create a short descriptive English title based on the main ingredient and cuisine style (e.g. "Gujarati Soybean Curry", "Spiced Chickpea Stew"). Never use "Untitled Recipe".

Format your response EXACTLY like this:

TITLE: [descriptive English title]

INGREDIENTS:
[ingredient 1]
[ingredient 2]
[etc.]

METHOD:
[step 1]
[step 2]
[etc.]

Example of tagging: "Simmer for 20 minutes [inferred] until chickpeas are tender [inferred]."

Here is the text:

' . $text_content;
    } else {
        $prompt = 'Please extract the recipe from this text. Parse it into structured format:

TITLE: [recipe title or "Untitled Recipe" if not visible]

INGREDIENTS:
[ingredient 1]
[ingredient 2]
[etc.]

METHOD:
[step 1]
[step 2]
[etc.]

Do NOT translate the text - keep it in the original language. Just extract and structure it.
If any part is illegible or unclear, note it with [unclear] rather than guessing.

Here is the text:

' . $text_content;
    }
    
    // Prepare the API request to extract recipe from text
    $request_body = array(
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 2000,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => $prompt
            )
        )
    );
    
    // Make API request
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
        wp_send_json_error(array('message' => 'API request failed: ' . $response->get_error_message()));
    }
    
    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    $data = json_decode($body, true);
    
    if ($http_code !== 200) {
        $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
        wp_send_json_error(array('message' => 'API Error: ' . $error_msg));
    }
    
    if (empty($data['content'][0]['text'])) {
        wp_send_json_error(array('message' => 'No response from Claude API'));
    }
    
    $extracted_text = $data['content'][0]['text'];
    
    // Parse the response using existing parser
    $parsed = parse_recipe_extraction($extracted_text);
    
    wp_send_json_success(array(
        'extracted_data' => $parsed,
        'raw_response' => $extracted_text,
        'interpretation_mode' => $interpretation_mode
    ));
}