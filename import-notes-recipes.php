<?php
/**
 * Bulk-import Apple Notes recipe exports into one user's collection.
 *
 * Reuses rather than reimplements:
 *   - extract_recipe_via_claude()          recipe-image-upload-handler.php
 *   - format_recipe_content_html()         custom-category-functions.php
 *   - format_recipe_notes_html()           custom-category-functions.php
 *   - get_or_create_user_category()        custom-category-functions.php
 *   - get_all_category_names_cross_user()  custom-category-functions.php
 *   - set_recipe_categories()              custom-category-functions.php
 *
 * Usage:
 *   php import-notes-recipes.php /path/to/wordpress /path/to/export-folder --user=7 [--dry-run] [--fresh]
 *
 * Source files are expected in the format:
 *   =====RECIPE=====
 *   TITLE: ...
 *   FOLDER: ...
 *
 *   BODY: ...
 *
 * --dry-run runs the parse/junk-filter/dedup stages only — writes skipped-junk.txt and
 * dedupe-log.txt into the export folder and prints a count summary, but makes no Claude
 * API calls and no database writes.
 *
 * Without --dry-run, progress is checkpointed to import-checkpoint.json in the export
 * folder so an interrupted run can be restarted without re-spending API calls or
 * duplicating drafts. --fresh clears that checkpoint and starts over.
 */

// CLI-only guard — mirrors import-recipes-v3.php / assign-recipe-ids.php
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}

// ---------------------------------------------------------------------------
// Tunables
// ---------------------------------------------------------------------------
const JUNK_MIN_BODY_LEN = 6;           // trimmed body shorter than this is always junk
const JUNK_ECHO_SLACK = 15;            // body <= title + this many chars counts as a title-echo
const JUNK_SIGNAL_LEN_CEILING = 260;   // only bodies shorter than this need a unit/verb signal
const JUNK_BOOKMARK_NONURL_LEN = 40;   // bare-URL bodies need at least this much non-URL text
const DEDUPE_UNIT_VERB_BONUS = 15;     // per distinct matched keyword, capped below
const DEDUPE_KEYWORD_CAP = 5;
const CLASSIFY_BATCH_SIZE = 40;
const API_CALL_DELAY_SECONDS = 1;      // pause between Claude calls to stay clear of rate limits
const EXTRACTION_MAX_ATTEMPTS = 3;

const DUMP_FILES = [
    '=====RECIPE=====.txt',
    'recipes-vegan-all.txt',
    'recipes-weightwatchers-all.txt',
];

const UNIT_WORDS = [
    'cup', 'cups', 'tbsp', 'tbs', 'tbls', 'tablespoon', 'tablespoons', 'tsp', 'teaspoon', 'teaspoons',
    'oz', 'ounce', 'ounces', 'lb', 'lbs', 'pound', 'pounds', 'gram', 'grams', 'kg', 'ml',
    'litre', 'liter', 'litres', 'liters', 'clove', 'cloves', 'pinch', 'can', 'cans',
    'package', 'packages', 'degrees', 'quart', 'quarts', 'pint', 'pints',
];

const VERB_WORDS = [
    'mix', 'bake', 'boil', 'simmer', 'stir', 'whisk', 'combine', 'preheat', 'cook', 'add', 'pour',
    'melt', 'knead', 'chill', 'marinate', 'season', 'serve', 'heat', 'fry', 'roast', 'grill',
    'blend', 'fold', 'saute', 'sauté', 'drain', 'garnish', 'spread', 'sprinkle', 'beat', 'whip',
    'toss', 'coat', 'dice', 'chop', 'slice', 'cover',
];

// ---------------------------------------------------------------------------
// Arg parsing
// ---------------------------------------------------------------------------
$positional = [];
$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0) {
        $arg = substr($arg, 2);
        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $flags[$k] = $v;
        } else {
            $flags[$arg] = true;
        }
    } else {
        $positional[] = $arg;
    }
}

if (count($positional) < 2) {
    die("Usage: php import-notes-recipes.php /path/to/wordpress /path/to/export-folder --user=7 [--dry-run] [--fresh]\n");
}

$wp_path = rtrim($positional[0], '/\\');
$export_folder = rtrim($positional[1], '/\\');
$target_user_id = isset($flags['user']) ? intval($flags['user']) : 0;
$dry_run = !empty($flags['dry-run']);
$fresh = !empty($flags['fresh']);

if ($target_user_id <= 0) {
    die("Error: --user=<id> is required.\n");
}
if (!is_dir($export_folder)) {
    die("Error: export folder not found: {$export_folder}\n");
}

// ---------------------------------------------------------------------------
// Load WordPress
// ---------------------------------------------------------------------------
define('WP_USE_THEMES', false);
require_once($wp_path . '/wp-load.php');

if (!function_exists('wp_insert_post')) {
    die("Error: could not load WordPress. Check path: {$wp_path}\n");
}

global $wpdb;
$wpdb->query("SET NAMES 'utf8mb4'");
$wpdb->query("SET CHARACTER SET utf8mb4");

$target_user = get_user_by('id', $target_user_id);
if (!$target_user) {
    die("Error: user_id {$target_user_id} does not exist.\n");
}

echo "=== Apple Notes Recipe Importer ===\n";
echo "Target user:    {$target_user->display_name} (ID {$target_user_id})\n";
echo "Export folder:  {$export_folder}\n";
echo "Mode:           " . ($dry_run ? 'DRY RUN (no API calls, no database writes)' : 'LIVE') . "\n\n";

// ---------------------------------------------------------------------------
// Helpers: parsing / normalization / heuristics
// ---------------------------------------------------------------------------

function normalize_title($title) {
    $t = strtolower(trim($title));
    $t = preg_replace('/[^\p{L}\p{N}\s]/u', '', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

/** Count how many distinct words from $words appear in $text (each word counts once). */
function count_distinct_keyword_matches($text, array $words) {
    $text_lower = strtolower($text);
    $hits = 0;
    foreach ($words as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/iu', $text_lower)) {
            $hits++;
        }
    }
    return $hits;
}

function parse_export_files($export_folder) {
    $files = glob($export_folder . '/*.txt');
    sort($files); // deterministic alphabetical order (also feeds the dedupe tie-break)

    $blocks = [];
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $content = file_get_contents($filepath);
        if ($content === false) {
            echo "WARNING: could not read {$filename}, skipping file.\n";
            continue;
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $raw_blocks = explode('=====RECIPE=====', $content);
        array_shift($raw_blocks); // preamble before the first delimiter

        foreach ($raw_blocks as $raw) {
            if (trim($raw) === '') {
                continue;
            }
            if (!preg_match('/TITLE:\s*(.*)\nFOLDER:\s*(.*)\n\s*\nBODY:\s*(.*)/s', $raw, $m)) {
                $blocks[] = [
                    'file' => $filename,
                    'title' => '',
                    'folder' => '',
                    'body' => '',
                    'parse_error' => true,
                    'raw_snippet' => trim(preg_replace('/\s+/', ' ', substr($raw, 0, 100))),
                ];
                continue;
            }
            $blocks[] = [
                'file' => $filename,
                'title' => trim($m[1]),
                'folder' => trim($m[2]),
                'body' => trim($m[3]),
                'parse_error' => false,
            ];
        }
    }
    return $blocks;
}

/** Returns a junk reason string, or null if the block should be kept. */
function classify_junk(array $block) {
    if ($block['parse_error']) {
        return 'Malformed block — could not parse TITLE/FOLDER/BODY structure';
    }

    $body = $block['body'];
    $title = $block['title'];
    $len = mb_strlen($body);

    if ($len < JUNK_MIN_BODY_LEN) {
        return "Empty/near-empty body ({$len} chars)";
    }

    $norm_title = normalize_title($title);
    $norm_body = normalize_title($body);
    if ($norm_title !== '') {
        if ($norm_body === $norm_title) {
            return 'Body is just a repeat of the title, no other content';
        }
        if (strpos($norm_body, $norm_title) === 0 && (mb_strlen($norm_body) - mb_strlen($norm_title)) <= JUNK_ECHO_SLACK) {
            return 'Body is the title plus a few extra characters — no real content';
        }
    }

    if (preg_match('/https?:\/\//i', $body)) {
        $non_url = trim(preg_replace('/https?:\/\/\S+/i', '', $body));
        if (mb_strlen($non_url) < JUNK_BOOKMARK_NONURL_LEN) {
            return 'Bare URL/bookmark with no recipe text';
        }
    }

    if ($len < JUNK_SIGNAL_LEN_CEILING) {
        $unit_hits = count_distinct_keyword_matches($body, UNIT_WORDS);
        $verb_hits = count_distinct_keyword_matches($body, VERB_WORDS);
        if ($unit_hits === 0 && $verb_hits === 0) {
            return "Short body ({$len} chars) with no recognizable quantity/unit or cooking-instruction words";
        }
    }

    return null;
}

function score_block(array $block) {
    $len = mb_strlen($block['body']);
    $unit_hits = min(DEDUPE_KEYWORD_CAP, count_distinct_keyword_matches($block['body'], UNIT_WORDS));
    $verb_hits = min(DEDUPE_KEYWORD_CAP, count_distinct_keyword_matches($block['body'], VERB_WORDS));
    return $len + DEDUPE_UNIT_VERB_BONUS * $unit_hits + DEDUPE_UNIT_VERB_BONUS * $verb_hits;
}

// ---------------------------------------------------------------------------
// Step 1: parse
// ---------------------------------------------------------------------------
$all_blocks = parse_export_files($export_folder);
$file_count = count(glob($export_folder . '/*.txt'));
echo "Parsed " . count($all_blocks) . " recipe block(s) from {$file_count} file(s).\n\n";

// ---------------------------------------------------------------------------
// Step 2: junk filter
// ---------------------------------------------------------------------------
$kept_blocks = [];
$junk_log = [];

foreach ($all_blocks as $block) {
    $reason = classify_junk($block);
    if ($reason !== null) {
        $block['reason'] = $reason;
        $junk_log[] = $block;
    } else {
        $kept_blocks[] = $block;
    }
}

echo "Junk filter: skipped " . count($junk_log) . " of " . count($all_blocks) . " block(s).\n";

$junk_log_path = $export_folder . '/skipped-junk.txt';
$fh = fopen($junk_log_path, 'w');
fwrite($fh, "SKIPPED JUNK/EMPTY ENTRIES — " . date('Y-m-d H:i:s') . "\n");
fwrite($fh, "Nothing was deleted from the source files — recover any of these by hand if this heuristic got one wrong.\n");
fwrite($fh, str_repeat('=', 78) . "\n\n");
foreach ($junk_log as $entry) {
    fwrite($fh, 'TITLE: ' . ($entry['title'] !== '' ? $entry['title'] : '(blank)') . "\n");
    fwrite($fh, "FOLDER: {$entry['folder']}\n");
    fwrite($fh, "SOURCE FILE: {$entry['file']}\n");
    fwrite($fh, 'BODY LENGTH: ' . mb_strlen($entry['body']) . " chars\n");
    fwrite($fh, "REASON: {$entry['reason']}\n");
    if ($entry['parse_error']) {
        fwrite($fh, "RAW SNIPPET: {$entry['raw_snippet']}\n");
    }
    fwrite($fh, str_repeat('-', 78) . "\n\n");
}
fclose($fh);
echo "  -> logged to {$junk_log_path}\n\n";

// ---------------------------------------------------------------------------
// Step 3: duplicate resolution
// ---------------------------------------------------------------------------
$groups = [];
foreach ($kept_blocks as $block) {
    $groups[normalize_title($block['title'])][] = $block;
}

$final_candidates = [];
$dedupe_groups_log = [];
$dropped_duplicate_count = 0;

foreach ($groups as $norm_title => $members) {
    if (count($members) === 1) {
        $final_candidates[] = $members[0];
        continue;
    }

    $scored = [];
    foreach ($members as $i => $m) {
        $scored[] = [
            'idx' => $i,
            'block' => $m,
            'score' => score_block($m),
            'is_dump' => in_array($m['file'], DUMP_FILES, true),
        ];
    }

    usort($scored, function ($a, $b) {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        if ($a['is_dump'] !== $b['is_dump']) {
            return $a['is_dump'] <=> $b['is_dump']; // non-dump (false) sorts first
        }
        return 0;
    });

    $winner = $scored[0];
    $final_candidates[] = $winner['block'];
    $dropped_duplicate_count += count($scored) - 1;

    $tie_break_used = ($scored[0]['score'] === $scored[1]['score']);
    $dedupe_groups_log[] = [
        'norm_title' => $norm_title,
        'scored' => $scored,
        'winner_idx' => $winner['idx'],
        'tie_break_used' => $tie_break_used,
    ];
}

echo 'Duplicate resolution: ' . count($dedupe_groups_log) . ' title(s) had multiple versions; kept the strongest, dropped '
    . $dropped_duplicate_count . " weaker duplicate(s).\n";

$dedupe_log_path = $export_folder . '/dedupe-log.txt';
$fh = fopen($dedupe_log_path, 'w');
fwrite($fh, "DUPLICATE RESOLUTION LOG — " . date('Y-m-d H:i:s') . "\n");
fwrite($fh, "score = body length + 15 per distinct quantity/unit keyword (max 5) + 15 per distinct cooking-verb keyword (max 5)\n");
fwrite($fh, str_repeat('=', 78) . "\n\n");
foreach ($dedupe_groups_log as $g) {
    fwrite($fh, "TITLE (normalized): {$g['norm_title']}\n");
    foreach ($g['scored'] as $m) {
        $tag = ($m['idx'] === $g['winner_idx']) ? '[KEPT]   ' : '[DROPPED]';
        fwrite($fh, "  {$tag} \"{$m['block']['title']}\" — file: {$m['block']['file']}, folder: {$m['block']['folder']}, "
            . "score: {$m['score']} (body length " . mb_strlen($m['block']['body']) . " chars)\n");
    }
    $reason = $g['tie_break_used']
        ? 'kept — scores tied, tie-break preferred a specific per-folder file over a catch-all dump file'
        : 'kept — highest length/structure score';
    fwrite($fh, "  Reason: {$reason}\n");
    fwrite($fh, str_repeat('-', 78) . "\n\n");
}
fclose($fh);
echo "  -> logged to {$dedupe_log_path}\n\n";

echo 'Final import candidates: ' . count($final_candidates) . "\n\n";

// ---------------------------------------------------------------------------
// Dry run stops here — no API calls, no DB writes
// ---------------------------------------------------------------------------
if ($dry_run) {
    echo "=== DRY RUN SUMMARY ===\n";
    echo 'Total blocks parsed:      ' . count($all_blocks) . "\n";
    echo 'Skipped as junk/empty:    ' . count($junk_log) . "\n";
    echo 'Duplicate titles found:   ' . count($dedupe_groups_log) . "\n";
    echo 'Dropped as weaker dup:    ' . $dropped_duplicate_count . "\n";
    echo 'Final import candidates:  ' . count($final_candidates) . "\n\n";
    echo "No Claude API calls were made and no database writes occurred.\n";
    echo "Review skipped-junk.txt and dedupe-log.txt in {$export_folder}, then re-run without --dry-run.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Real run: checkpoint, extraction, insertion, batched classification
// ---------------------------------------------------------------------------

$checkpoint_path = $export_folder . '/import-checkpoint.json';
$checkpoint = [];
if (!$fresh && file_exists($checkpoint_path)) {
    $decoded = json_decode(file_get_contents($checkpoint_path), true);
    if (is_array($decoded)) {
        $checkpoint = $decoded;
    }
}

function save_checkpoint($path, &$checkpoint) {
    file_put_contents($path, json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function checkpoint_key($block) {
    // Keyed on source file + raw body (not title) so the ~5 blank-title blocks
    // in the same file still get distinct, stable keys across re-runs.
    return sha1($block['file'] . '|' . $block['body']);
}

$failed_log_path = $export_folder . '/failed-extractions.txt';
$failed_fh = fopen($failed_log_path, 'w');
fwrite($failed_fh, "FAILED EXTRACTIONS — " . date('Y-m-d H:i:s') . "\n");
fwrite($failed_fh, "Re-run the importer (without --fresh) to retry only these.\n");
fwrite($failed_fh, str_repeat('=', 78) . "\n\n");

// Terry-iPad tag — created once, reused every run (create_user_category() already
// no-ops and returns the existing cat_id if it's there, so this is idempotent).
$terry_ipad_cat_id = get_or_create_user_category($target_user_id, 'Terry-iPad', 'food');
echo "Terry-iPad tag category: cat_id {$terry_ipad_cat_id}\n";

$uncategorized_cat_id = get_or_create_user_category($target_user_id, 'Uncategorized', 'food');

// Cross-user naming vocabulary, grown in-memory as new categories get created this run
// so later classification batches match against them instead of inventing near-duplicates.
$category_vocab = get_all_category_names_cross_user();
$original_vocab_lower = array_map('mb_strtolower', $category_vocab);
$categories_created_this_run = [];

function classify_recipes_batch(array $items, array $vocab) {
    $vocab_list = implode(', ', $vocab);

    $lines = [];
    foreach ($items as $i => $item) {
        $lines[] = "[{$i}] Title: {$item['title']} | Original source folder (a hint, not a rule): {$item['folder']} | "
            . 'Ingredients: ' . mb_substr($item['ingredients_excerpt'], 0, 300) . ' | '
            . 'Method: ' . mb_substr($item['method_excerpt'], 0, 300);
    }
    $recipes_block = implode("\n", $lines);

    $prompt = "You are choosing the single best category for each recipe below, to organize a recipe collection.\n\n"
        . "EXISTING CATEGORIES (prefer matching one of these if it reasonably fits):\n{$vocab_list}\n\n"
        . "RULES:\n"
        . "1. If a recipe reasonably fits one of the EXISTING CATEGORIES above, use that exact name (same spelling/capitalization).\n"
        . "2. Only propose a brand-new category name if the recipe clearly does not fit any existing category AND represents a "
        . "distinct, meaningful type worth its own category (a specific cuisine, dish type, or dietary style not already covered). "
        . "This should be the exception, not a default — do not invent a new category just for stylistic preference.\n"
        . "3. If you are genuinely unsure and it does not clearly warrant a new category either, answer \"Uncategorized\".\n"
        . "4. New category names should be short, Title Case, and specific (e.g. \"Air Fryer\", \"Slow Cooker\", \"Middle Eastern\").\n\n"
        . "Respond with ONLY a JSON array, one object per recipe, in this exact format and nothing else:\n"
        . "[{\"index\": 0, \"category\": \"Category Name\"}, ...]\n\n"
        . "RECIPES:\n{$recipes_block}";

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key' => ANTHROPIC_API_KEY,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => json_encode([
            'model' => ANTHROPIC_MODEL,
            'max_tokens' => 4000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }
    $http_code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($http_code !== 200 || empty($data['content'][0]['text'])) {
        $err = isset($data['error']['message']) ? $data['error']['message'] : "HTTP {$http_code}";
        return ['success' => false, 'error' => $err];
    }

    $text = trim($data['content'][0]['text']);
    $text = preg_replace('/^```(json)?/i', '', $text);
    $text = preg_replace('/```$/', '', $text);
    $parsed = json_decode(trim($text), true);

    if (!is_array($parsed)) {
        return ['success' => false, 'error' => 'Could not parse classification JSON response'];
    }

    $by_index = [];
    foreach ($parsed as $row) {
        if (isset($row['index'], $row['category'])) {
            $by_index[(int) $row['index']] = trim($row['category']);
        }
    }
    return ['success' => true, 'data' => $by_index];
}

/** Resolve a Claude-proposed category name to a cat_id under the target user, growing $vocab in place. */
function resolve_category_name($target_user_id, $name, $uncategorized_cat_id, array &$vocab, array &$original_vocab_lower, array &$created_this_run) {
    $name = trim(preg_replace('/\s+/', ' ', (string) $name));
    if ($name === '' || mb_strtolower($name) === 'uncategorized') {
        return $uncategorized_cat_id;
    }

    $cat_id = get_or_create_user_category($target_user_id, $name, 'food');

    if (!in_array(mb_strtolower($name), $original_vocab_lower, true) && !in_array($name, $vocab, true)) {
        $vocab[] = $name; // so subsequent batches this run see it
        $created_this_run[] = $name;
    }
    return (int) $cat_id;
}

/** Retry wrapper around extract_recipe_via_claude() with backoff on transient errors. */
function extract_with_retry($body_text) {
    $last_error = '';
    for ($attempt = 1; $attempt <= EXTRACTION_MAX_ATTEMPTS; $attempt++) {
        $result = extract_recipe_via_claude($body_text, false);
        if ($result['success']) {
            return $result;
        }
        $last_error = $result['error'];
        $code = $result['http_code'] ?? null;
        $retryable = ($code === 429) || ($code !== null && $code >= 500) || strpos($last_error, 'API request failed') === 0;
        if (!$retryable || $attempt >= EXTRACTION_MAX_ATTEMPTS) {
            break;
        }
        $backoff = 2 ** $attempt;
        echo "    retrying ({$attempt}/" . EXTRACTION_MAX_ATTEMPTS . ") after error: {$last_error} — waiting {$backoff}s\n";
        sleep($backoff);
    }
    return ['success' => false, 'error' => $last_error];
}

$classification_buffer = []; // checkpoint_key => ['post_id','title','folder','ingredients_excerpt','method_excerpt']

function flush_classification_buffer(&$buffer, $target_user_id, $uncategorized_cat_id, $terry_ipad_cat_id, &$vocab, &$original_vocab_lower, &$created_this_run, &$checkpoint, $checkpoint_path) {
    if (empty($buffer)) {
        return;
    }

    $keys = array_keys($buffer);
    $items = array_values($buffer);
    $result = classify_recipes_batch($items, $vocab);

    foreach ($keys as $i => $key) {
        $entry = $buffer[$key];
        $category_name = ($result['success'] && isset($result['data'][$i])) ? $result['data'][$i] : 'Uncategorized';
        $content_cat_id = resolve_category_name($target_user_id, $category_name, $uncategorized_cat_id, $vocab, $original_vocab_lower, $created_this_run);

        set_recipe_categories($entry['post_id'], [$terry_ipad_cat_id, $content_cat_id]);

        $checkpoint[$key]['status'] = 'imported';
        $checkpoint[$key]['category'] = ($content_cat_id === $uncategorized_cat_id) ? 'Uncategorized' : $category_name;
    }

    if (!$result['success']) {
        echo "    WARNING: batch classification call failed ({$result['error']}) — " . count($items) . " recipe(s) in this batch fell back to Uncategorized.\n";
    }

    save_checkpoint($checkpoint_path, $checkpoint);
    $buffer = [];
    sleep(API_CALL_DELAY_SECONDS);
}

$created_count = 0;
$skipped_already_imported = 0;
$failed_count = 0;

foreach ($final_candidates as $i => $block) {
    $key = checkpoint_key($block);
    $num = $i + 1;
    $total = count($final_candidates);

    if (isset($checkpoint[$key]) && $checkpoint[$key]['status'] === 'imported') {
        $skipped_already_imported++;
        continue;
    }

    $display_title = $block['title'] !== '' ? $block['title'] : '(untitled — from body)';
    echo "[{$num}/{$total}] {$display_title} ({$block['file']})\n";

    // Resume case: post already created in a previous run, just needs classification.
    if (isset($checkpoint[$key]) && $checkpoint[$key]['status'] === 'draft_created') {
        $classification_buffer[$key] = [
            'post_id' => $checkpoint[$key]['post_id'],
            'title' => $checkpoint[$key]['title'],
            'folder' => $block['folder'],
            'ingredients_excerpt' => $checkpoint[$key]['ingredients_excerpt'],
            'method_excerpt' => $checkpoint[$key]['method_excerpt'],
        ];
        if (count($classification_buffer) >= CLASSIFY_BATCH_SIZE) {
            flush_classification_buffer($classification_buffer, $target_user_id, $uncategorized_cat_id, $terry_ipad_cat_id, $category_vocab, $original_vocab_lower, $categories_created_this_run, $checkpoint, $checkpoint_path);
        }
        continue;
    }

    $result = extract_with_retry($block['body']);
    sleep(API_CALL_DELAY_SECONDS);

    if (!$result['success']) {
        $failed_count++;
        $checkpoint[$key] = ['status' => 'failed', 'title' => $block['title'], 'error' => $result['error']];
        save_checkpoint($checkpoint_path, $checkpoint);
        fwrite($failed_fh, "TITLE: {$display_title}\nFILE: {$block['file']}\nFOLDER: {$block['folder']}\nERROR: {$result['error']}\n" . str_repeat('-', 78) . "\n\n");
        echo "    FAILED: {$result['error']}\n";
        continue;
    }

    $extracted = $result['data'];
    $ingredients_html = format_recipe_content_html($extracted['ingredients'] ?? '', false);
    $method_html = format_recipe_content_html($extracted['method'] ?? '', true);

    if ($ingredients_html === '' || $method_html === '') {
        $failed_count++;
        $reason = 'Extraction produced no usable ingredients/method despite passing the junk filter';
        $checkpoint[$key] = ['status' => 'failed', 'title' => $block['title'], 'error' => $reason];
        save_checkpoint($checkpoint_path, $checkpoint);
        fwrite($failed_fh, "TITLE: {$display_title}\nFILE: {$block['file']}\nFOLDER: {$block['folder']}\nERROR: {$reason}\n" . str_repeat('-', 78) . "\n\n");
        echo "    FAILED: {$reason}\n";
        continue;
    }

    // Prefer the source's own title over Claude's fallback; Claude only invents one
    // when the source truly had none (the ~5 blank-TITLE: blocks with an embedded
    // title in the body, which strict-mode extraction is instructed to surface).
    $final_title = $block['title'] !== '' ? $block['title'] : trim($extracted['title'] ?? '');
    if ($final_title === '') {
        $final_title = 'Untitled Recipe';
    }

    $notes_raw = "ORIGINAL TEXT:\n\n[Source: Apple Notes export — file: {$block['file']}, folder: \"{$block['folder']}\"]\n\n{$block['body']}";
    $notes_html = format_recipe_notes_html($notes_raw);

    $post_id = wp_insert_post([
        'post_title' => $final_title,
        'post_type' => 'recipe',
        'post_status' => 'draft',
        'post_author' => $target_user_id,
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
        $failed_count++;
        $error = is_wp_error($post_id) ? $post_id->get_error_message() : 'wp_insert_post returned no ID';
        $checkpoint[$key] = ['status' => 'failed', 'title' => $block['title'], 'error' => $error];
        save_checkpoint($checkpoint_path, $checkpoint);
        fwrite($failed_fh, "TITLE: {$display_title}\nFILE: {$block['file']}\nFOLDER: {$block['folder']}\nERROR: {$error}\n" . str_repeat('-', 78) . "\n\n");
        echo "    FAILED to create post: {$error}\n";
        continue;
    }

    update_post_meta($post_id, '_recipe_ingredients', $ingredients_html);
    update_post_meta($post_id, '_recipe_method', $method_html);
    update_post_meta($post_id, '_recipe_notes', $notes_html);
    update_post_meta($post_id, '_recipe_id', 'R' . str_pad($post_id, 4, '0', STR_PAD_LEFT));

    $created_count++;
    echo "    created draft #{$post_id}\n";

    $checkpoint[$key] = [
        'status' => 'draft_created',
        'post_id' => $post_id,
        'title' => $final_title,
        'ingredients_excerpt' => $extracted['ingredients'] ?? '',
        'method_excerpt' => $extracted['method'] ?? '',
    ];
    save_checkpoint($checkpoint_path, $checkpoint);

    $classification_buffer[$key] = [
        'post_id' => $post_id,
        'title' => $final_title,
        'folder' => $block['folder'],
        'ingredients_excerpt' => $extracted['ingredients'] ?? '',
        'method_excerpt' => $extracted['method'] ?? '',
    ];

    if (count($classification_buffer) >= CLASSIFY_BATCH_SIZE) {
        flush_classification_buffer($classification_buffer, $target_user_id, $uncategorized_cat_id, $terry_ipad_cat_id, $category_vocab, $original_vocab_lower, $categories_created_this_run, $checkpoint, $checkpoint_path);
    }
}

// Final partial batch
flush_classification_buffer($classification_buffer, $target_user_id, $uncategorized_cat_id, $terry_ipad_cat_id, $category_vocab, $original_vocab_lower, $categories_created_this_run, $checkpoint, $checkpoint_path);

fclose($failed_fh);

echo "\n=== IMPORT COMPLETE ===\n";
echo "Drafts created this run:        {$created_count}\n";
echo "Already imported (skipped):     {$skipped_already_imported}\n";
echo "Failed extractions:             {$failed_count}" . ($failed_count > 0 ? " (see {$failed_log_path})" : '') . "\n";
echo 'New categories created:         ' . count(array_unique($categories_created_this_run)) . (empty($categories_created_this_run) ? '' : ' (' . implode(', ', array_unique($categories_created_this_run)) . ')') . "\n";
echo "All imports are WordPress drafts — review under /recipe-manager/?collection={$target_user_id} before publishing.\n";
