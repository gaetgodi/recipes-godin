<?php
/**
 * Recipe Dietary Classifier
 *
 * Shared function to classify a recipe's dietary attributes (vegan,
 * vegetarian, gluten-free) from its ingredient text via the Claude API.
 * Follows the same wp_remote_post/ANTHROPIC_MODEL pattern as
 * allergen-image-upload-handler.php's extract_product_from_image() and
 * the recipe OCR handler, rather than introducing a shared HTTP helper
 * neither of those use.
 *
 * Used by:
 *  - functions.php (save_post hook, automatic classification on recipe save)
 *  - bulk-dietary-classify.php (CLI backfill for existing recipes)
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Classify a recipe's ingredient text for vegan / vegetarian / gluten-free.
 *
 * @param string $ingredients_text Plain text or HTML ingredient list.
 * @return array|null ['vegan' => bool, 'vegetarian' => bool, 'gluten_free' => bool]
 *                     or null on any API/parse error.
 */
function classify_recipe_dietary($ingredients_text) {
    if (empty(trim(wp_strip_all_tags($ingredients_text)))) {
        return null;
    }

    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        return null;
    }

    $api_key = ANTHROPIC_API_KEY;

    $prompt = 'Given this ingredient list, respond ONLY with JSON: {"vegan": true/false, "vegetarian": true/false, "gluten_free": true/false}. Vegan = no animal products (no meat, fish, dairy, eggs, honey). Vegetarian = no meat or fish but allows dairy and eggs. Gluten-free = no wheat, barley, rye, or oats.

Ingredient list:
' . wp_strip_all_tags($ingredients_text);

    $request_body = array(
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 50,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => $prompt
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
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    $data = json_decode($body, true);

    if ($http_code !== 200) {
        return null;
    }

    if (empty($data['content'][0]['text'])) {
        return null;
    }

    $text = trim($data['content'][0]['text']);

    // Strip markdown code fences in case the model wraps the JSON despite instructions.
    if (strpos($text, '```') !== false) {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $text = trim($text);
    }

    $parsed = json_decode($text, true);

    if (!is_array($parsed) ||
        !isset($parsed['vegan']) ||
        !isset($parsed['vegetarian']) ||
        !isset($parsed['gluten_free'])) {
        return null;
    }

    return array(
        'vegan' => (bool) $parsed['vegan'],
        'vegetarian' => (bool) $parsed['vegetarian'],
        'gluten_free' => (bool) $parsed['gluten_free']
    );
}
