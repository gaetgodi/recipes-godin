<?php
/**
 * Recipe Image Upload and OCR Handler
 * Handles featured image upload and Claude API OCR extraction
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
    $extraction_result = extract_recipe_from_image($base64_image, $mime_type);
    
    if ($extraction_result['success']) {
        // Set as featured image if recipe ID provided
        if ($recipe_id > 0) {
            set_post_thumbnail($recipe_id, $attachment_id);
        }
        
        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'image_url' => $image_url,
            'extracted_data' => $extraction_result['data'],
            'raw_response' => $extraction_result['raw']
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
 */
function extract_recipe_from_image($base64_image, $mime_type) {
    // Get API key from wp-config.php
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        return array(
            'success' => false,
            'error' => 'Anthropic API key not configured. Please add your API key to wp-config.php'
        );
    }
    
    $api_key = ANTHROPIC_API_KEY;
    
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
                        'text' => 'Please extract the recipe from this image. If this is a handwritten or printed recipe, extract:

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

Be accurate with measurements and spelling. Preserve the original wording as much as possible.'
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
    $data = json_decode($body, true);
    
    if (empty($data['content'][0]['text'])) {
        return array(
            'success' => false,
            'error' => 'No response from Claude API'
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
        if ($result['title'] === 'Untitled Recipe') {
            $result['title'] = '';
        }
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