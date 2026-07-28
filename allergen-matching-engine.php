<?php
/**
 * Allergen Checker — Matching Engine
 *
 * Produces the three-tier risk report (Contains / May contain / Not
 * detected) for a single checkable item (a recipe, in Phase 2 — products
 * are added in Phase 3) against a given allergen profile.
 *
 * Matching is deliberately simple and fully auditable: a fixed phrase list
 * for precautionary statements, and word-boundary substring matching for
 * allergen aliases. No suppression/negation heuristics are applied (e.g.
 * a line like "milk-free" still matches "milk") — the exact matched line
 * is always shown next to the flag so a human can immediately recognize
 * a false positive, rather than hiding a decision behind an unauditable
 * rule that could just as easily suppress a real allergen match.
 */

// Security check
if (!defined('ABSPATH')) exit;

/**
 * Fixed, human-reviewable list of precautionary/shared-facility phrases.
 * Not tied to any specific allergen — these are structural English
 * disclaimers that can appear alongside any allergen name.
 */
function get_precautionary_phrases() {
    return array(
        'may contain',
        'may contain traces of',
        'processed in a facility that also processes',
        'processed on shared equipment',
        'manufactured on shared equipment',
        'manufactured in a facility that also',
        'shared equipment with',
        'produced in a facility',
    );
}

/**
 * Whether a line contains a precautionary/shared-facility statement.
 */
function is_precautionary_phrase($line) {
    $line_lower = strtolower($line);
    foreach (get_precautionary_phrases() as $phrase) {
        if (strpos($line_lower, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Fixed, human-reviewable list of compound/packaged-ingredient terms — items
 * that are themselves a manufactured product with its own ingredient list
 * the recipe text never states (e.g. "cake mix," "chocolate chips"), so a
 * hidden allergen inside them cannot be ruled out from the recipe alone.
 * Same philosophy and same maintenance model as get_precautionary_phrases():
 * a plain list to extend as real recipes surface more cases, not a model
 * call or a heuristic.
 */
function get_compound_ingredient_terms() {
    return array(
        'cake mix', 'brownie mix', 'muffin mix', 'pancake mix', 'waffle mix',
        'biscuit mix', 'cornbread mix', 'bread mix',
        'chocolate chips', 'butterscotch chips', 'peanut butter chips', 'caramel bits',
        'chocolate bar', 'candy bar', 'dark chocolate', 'white chocolate', 'milk chocolate',
        'frosting', 'icing', 'pudding mix', 'gelatin dessert', 'jello', 'marshmallow fluff',
        'graham cracker crumbs', 'breadcrumbs', 'bread crumbs', 'panko', 'croutons', 'stuffing mix',
        'pie crust', 'puff pastry', 'phyllo dough', 'pizza dough', 'cookie dough',
        'bouillon', 'stock cube', 'gravy mix', 'gravy packet', 'soup mix', 'cream of mushroom soup',
        'condensed soup', 'canned soup', 'taco seasoning', 'ranch dressing mix', 'onion soup mix',
        'seasoning packet', 'salad dressing', 'barbecue sauce', 'bbq sauce', 'teriyaki sauce',
        'hoisin sauce', 'enchilada sauce', 'alfredo sauce', 'curry paste',
        'whipped topping', 'cool whip', 'coffee creamer', 'non-dairy creamer',
        'imitation crab', 'veggie burger', 'plant-based meat', 'protein bar', 'granola bar',
        'energy bar', 'trail mix', 'granola', 'cereal', 'instant noodles', 'ramen seasoning',
    );
}

/**
 * Whether a line names a compound/packaged ingredient whose own allergen
 * content can't be determined from this text alone.
 */
function is_compound_ingredient_phrase($line) {
    $line_lower = strtolower($line);
    foreach (get_compound_ingredient_terms() as $phrase) {
        if (strpos($line_lower, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Convert an HTML fragment (as stored in recipe postmeta) into an array of
 * plain-text lines. Deliberately a small self-contained helper rather than
 * reusing page-recipe-editor.php's html_to_plain_text() — that function is
 * defined inline inside that template with no function_exists() guard and
 * is only in scope when that specific template happens to load.
 */
function strip_html_to_lines($html) {
    if (empty($html)) {
        return array();
    }

    $with_breaks = preg_replace('/<\/(li|p|div|h[1-6])>|<br\s*\/?>/i', "\n", $html);
    $plain = wp_strip_all_tags($with_breaks);
    $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');

    $lines = preg_split('/\r\n|\r|\n/', $plain);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, function ($line) {
        return $line !== '';
    });

    return array_values($lines);
}

/**
 * Assemble the checkable text lines for an item, each tagged with the
 * source field it came from.
 *
 * Returns an array of ['line' => string, 'source' => string].
 */
function get_checkable_text($item_type, $item_id) {
    $tagged_lines = array();

    if ($item_type === 'recipe') {
        $fields = array(
            '_recipe_ingredients' => 'Ingredients',
            '_recipe_method' => 'Method',
            '_recipe_notes' => 'Notes',
        );

        foreach ($fields as $meta_key => $source_label) {
            $html = get_post_meta($item_id, $meta_key, true);
            foreach (strip_html_to_lines($html) as $line) {
                $tagged_lines[] = array('line' => $line, 'source' => $source_label);
            }
        }
    } elseif ($item_type === 'product') {
        // Added in Phase 3 — allergen_products.ingredient_text is already
        // plain text, split directly on newline.
        global $wpdb;
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT ingredient_text FROM {$wpdb->prefix}allergen_products WHERE product_id = %d",
            $item_id
        ));

        if ($product) {
            $lines = preg_split('/\r\n|\r|\n/', $product->ingredient_text);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $tagged_lines[] = array('line' => $line, 'source' => 'Product');
                }
            }
        }
    }

    return $tagged_lines;
}

/**
 * Get the alias set for a profile's allergens, grouped by allergen.
 *
 * Returns an array of allergen_id => ['allergen_name' => ..., 'aliases' => [...]].
 */
function get_profile_match_terms($profile_id) {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT d.allergen_id, d.allergen_name, a.alias_text
         FROM {$wpdb->prefix}allergen_profile_items i
         INNER JOIN {$wpdb->prefix}allergen_definitions d ON i.allergen_id = d.allergen_id
         INNER JOIN {$wpdb->prefix}allergen_aliases a ON a.allergen_id = d.allergen_id
         WHERE i.profile_id = %d",
        $profile_id
    ));

    $terms = array();
    foreach ($rows as $row) {
        if (!isset($terms[$row->allergen_id])) {
            $terms[$row->allergen_id] = array(
                'allergen_name' => $row->allergen_name,
                'aliases' => array(),
            );
        }
        $terms[$row->allergen_id]['aliases'][] = $row->alias_text;
    }

    return $terms;
}

/**
 * Run the allergen check for one item against one profile.
 *
 * Returns:
 * [
 *   'item_type' => 'recipe'|'product',
 *   'item_id' => int,
 *   'flagged' => bool,   // true if any allergen resolved to contains/may_contain
 *   'allergens' => [
 *       allergen_id => [
 *           'allergen_name' => string,
 *           'aliases' => [string, ...],  // full alias set searched for this allergen, for display transparency
 *           'tier' => 'contains'|'may_contain'|'not_detected',
 *           'matches' => [ ['line' => string, 'source' => string, 'precautionary' => bool], ... ],
 *       ],
 *       ...
 *   ],
 *   'general_cautions' => [ ['line' => string, 'source' => string], ... ],
 *   'indeterminate_ingredients' => [ ['line' => string, 'source' => string], ... ],
 *       // Lines naming a compound/packaged ingredient (e.g. "cake mix") whose
 *       // own allergen content this tool cannot determine from recipe text
 *       // alone — layered on top of, not instead of, whatever tier the line
 *       // otherwise resolved to. "Cannot determine — verify package label."
 * ]
 */
function run_allergen_check($item_type, $item_id, $profile_id) {
    $tagged_lines = get_checkable_text($item_type, $item_id);
    $match_terms = get_profile_match_terms($profile_id);

    $allergens = array();
    foreach ($match_terms as $allergen_id => $term) {
        $allergens[$allergen_id] = array(
            'allergen_name' => $term['allergen_name'],
            'aliases' => $term['aliases'],
            'tier' => 'not_detected',
            'matches' => array(),
        );
    }

    $general_cautions = array();
    $indeterminate_ingredients = array();

    foreach ($tagged_lines as $tagged_line) {
        $line = $tagged_line['line'];
        $source = $tagged_line['source'];
        $is_precautionary = is_precautionary_phrase($line);

        if (is_compound_ingredient_phrase($line)) {
            $indeterminate_ingredients[] = array('line' => $line, 'source' => $source);
        }

        $line_matched_any_allergen = false;

        foreach ($match_terms as $allergen_id => $term) {
            foreach ($term['aliases'] as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/iu', $line)) {
                    $line_matched_any_allergen = true;
                    $allergens[$allergen_id]['matches'][] = array(
                        'line' => $line,
                        'source' => $source,
                        'precautionary' => $is_precautionary,
                    );
                    break; // one match per allergen per line is enough
                }
            }
        }

        if ($is_precautionary && !$line_matched_any_allergen) {
            $general_cautions[] = array('line' => $line, 'source' => $source);
        }
    }

    $flagged = false;

    foreach ($allergens as $allergen_id => &$allergen) {
        $has_direct_match = false;
        $has_precautionary_match = false;

        foreach ($allergen['matches'] as $match) {
            if ($match['precautionary']) {
                $has_precautionary_match = true;
            } else {
                $has_direct_match = true;
            }
        }

        if ($has_direct_match) {
            $allergen['tier'] = 'contains';
            $flagged = true;
        } elseif ($has_precautionary_match) {
            $allergen['tier'] = 'may_contain';
            $flagged = true;
        } else {
            $allergen['tier'] = 'not_detected';
        }
    }
    unset($allergen);

    return array(
        'item_type' => $item_type,
        'item_id' => $item_id,
        'flagged' => $flagged,
        'allergens' => $allergens,
        'general_cautions' => $general_cautions,
        'indeterminate_ingredients' => $indeterminate_ingredients,
    );
}
