[33mcommit 58c6843d2aa96566884e0ec5a71b36c21487f64e[m[33m ([m[1;36mHEAD[m[33m -> [m[1;32mmain[m[33m, [m[1;31morigin/main[m[33m, [m[1;31morigin/HEAD[m[33m)[m
Merge: cdd901a 9bfc9c4
Author: Gaetan Godin <gaetgodi@godin.on.ca>
Date:   Wed Aug 5 15:23:06 2026 -0400

    Merge branch 'main' of github.com:gaetgodi/recipes-godin

[1mdiff --cc page-recipe-editor.php[m
[1mindex 8df90c9,fa9bb16..0dc9bec[m
[1m--- a/page-recipe-editor.php[m
[1m+++ b/page-recipe-editor.php[m
[36m@@@ -185,11 -190,36 +190,12 @@@[m [mif ($_SERVER['REQUEST_METHOD'] === 'POS[m
      $notes = sanitize_textarea_field($_POST['recipe_notes']);[m
      $categories = isset($_POST['recipe_categories']) ? array_map('intval', $_POST['recipe_categories']) : array();[m
      $new_featured_image_id = isset($_POST['featured_image_id']) ? intval($_POST['featured_image_id']) : 0;[m
[32m+     $submitted_product_ids = isset($_POST['attached_product_ids']) ? array_map('intval', $_POST['attached_product_ids']) : array();[m
      [m
[31m -    function auto_format_content($content, $is_method = false) {[m
[31m -        if (empty($content)) return '';[m
[31m -        if (strpos($content, '<ul>') !== false || strpos($content, '<ol>') !== false) return $content;[m
[31m -        [m
[31m -        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content)));[m
[31m -        if (empty($lines)) return '';[m
[31m -        if (count($lines) === 1) return '<p>' . esc_html($lines[0]) . '</p>';[m
[31m -        [m
[31m -        $list_items = [];[m
[31m -        foreach ($lines as $line) {[m
[31m -            $line = preg_replace('/^[\-•*]\s+/', '', $line);[m
[31m -            [m
[31m -            if ($is_method) {[m
[31m -                $line = preg_replace('/^\d+[\.)]\s+/', '', $line);[m
[31m -            }[m
[31m -            [m
[31m -            if (!empty($line)) $list_items[] = '<li>' . esc_html($line) . '</li>';[m
[31m -        }[m
[31m -        [m
[31m -        $tag = $is_method ? 'ol' : 'ul';[m
[31m -        return empty($list_items) ? '' : '<' . $tag . '>' . implode('', $list_items) . '</' . $tag . '>';[m
[31m -    }[m
[31m -    [m
[31m -    $ingredients = auto_format_content($ingredients, false);[m
[31m -    $method = auto_format_content($method, true);[m
[31m -    if (!empty($notes) && strpos($notes, '<p>') === false) {[m
[31m -        $notes = '<p>' . esc_html($notes) . '</p>';[m
[31m -    }[m
[32m +    // Shared with the CLI bulk importer — see format_recipe_content_html() in custom-category-functions.php[m
[32m +    $ingredients = format_recipe_content_html($ingredients, false);[m
[32m +    $method = format_recipe_content_html($method, true);[m
[32m +    $notes = format_recipe_notes_html($notes);[m
      [m
      $errors = array();[m
      if (empty($title)) {[m
