<?php
/**
 * Recipe Servings Classifier
 *
 * Shared function to estimate a recipe's yield/servings from its ingredient
 * quantities and any explicit yield mentioned in the method, via the Claude
 * API. Follows the same wp_remote_post/ANTHROPIC_MODEL pattern as
 * recipe-dietary-classifier.php's classify_recipe_dietary(), rather than
 * introducing a shared HTTP helper neither of those use.
 *
 * Used by:
 *  - page-recipe-editor.php (add-only: only called when _recipe_servings
 *    is currently empty, same principle as the dietary auto-classification
 *    wired there)
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Estimate a recipe's servings/yield from its ingredients and method text.
 *
 * @param string $ingredients_text Plain text or HTML ingredient list.
 * @param string $method_text      Plain text or HTML method/instructions.
 * @return string|null Short conversational estimate (e.g. "4-6 servings",
 *                      "Makes about 24 cookies"), or null on any API/parse
 *                      error or if there's nothing to estimate from.
 */
function estimate_recipe_servings($ingredients_text, $method_text) {
    $ingredients_plain = trim(wp_strip_all_tags($ingredients_text));
    $method_plain = trim(wp_strip_all_tags($method_text));

    if (empty($ingredients_plain) && empty($method_plain)) {
        return null;
    }

    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_KEY_GOES_HERE_WHEN_READY') {
        return null;
    }

    $api_key = ANTHROPIC_API_KEY;

    $prompt = 'Estimate how many servings this recipe makes, based on the ingredient quantities and any explicit yield mentioned in the method (e.g. "makes 24 cookies", "serves 4"). Respond with ONLY a short, conversational estimate and nothing else - no explanation, no JSON. Examples of the expected format: "4-6 servings", "Makes about 24 cookies", "Serves 8". If you truly cannot estimate at all, respond with exactly: UNKNOWN

Ingredients:
' . $ingredients_plain . '

Method:
' . $method_plain;

    $request_body = array(
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 40,
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

    // Strip surrounding quotes in case the model wraps its answer despite instructions.
    $text = trim($text, "\"' \t\n\r\0\x0B");

    if ($text === '' || strtoupper($text) === 'UNKNOWN') {
        return null;
    }

    return $text;
}
