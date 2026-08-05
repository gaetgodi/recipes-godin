<?php
/**
 * Template Name: Allergen Product Library
 *
 * Scan packaged products (photo -> OCR -> editable review -> save), then
 * manage the resulting library — edit, delete. Products are then checkable
 * from the Allergen Checker page alongside recipes.
 *
 * Phase 3: own library only. Product sharing/copy is added in Phase 5,
 * reusing collection-permissions.php's existing copy-on-share mechanism.
 */

get_header();

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/'));
    exit;
}

require_once(get_stylesheet_directory() . '/allergen-functions.php');

$current_user_id = get_current_user_id();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save a new product (manually entered, or reviewed after a scan)
    if (isset($_POST['add_product'])) {
        check_admin_referer('add_product');

        $product_name = sanitize_text_field($_POST['product_name']);
        $ingredient_text = sanitize_textarea_field($_POST['ingredient_text']);
        $source_image_attachment_id = !empty($_POST['source_image_attachment_id']) ? intval($_POST['source_image_attachment_id']) : null;

        $result = create_product($current_user_id, $product_name, $ingredient_text, $source_image_attachment_id);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = "Product '{$product_name}' added to your library!";
            $message_type = 'success';
        }
    }

    // Save edits to an existing product
    if (isset($_POST['edit_product'])) {
        $product_id = intval($_POST['product_id']);
        check_admin_referer('edit_product_' . $product_id);

        $product_name = sanitize_text_field($_POST['product_name']);
        $ingredient_text = sanitize_textarea_field($_POST['ingredient_text']);

        $result = update_product($product_id, $current_user_id, $product_name, $ingredient_text);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = "Product '{$product_name}' updated!";
            $message_type = 'success';
        }
    }

    // Delete a product
    if (isset($_POST['delete_product'])) {
        $product_id = intval($_POST['product_id']);
        check_admin_referer('delete_product_' . $product_id);

        $result = delete_product($product_id, $current_user_id);

        if (isset($result['error'])) {
            $message = 'Error: ' . $result['error'];
            $message_type = 'error';
        } else {
            $message = 'Product deleted.';
            $message_type = 'success';
        }
    }
}

$products = get_user_products($current_user_id);

?>

<style>
.allergen-product-manager {
    max-width: 1200px;
    margin: 40px auto;
    padding: 0 20px;
}

.allergen-product-manager h1 {
    color: #c84a31;
    font-size: 36px;
    margin-bottom: 10px;
}

.allergen-product-manager-subtitle {
    color: #666;
    margin-bottom: 20px;
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

.scan-section {
    background: #f9f9f9;
    padding: 20px;
    margin-bottom: 15px;
    border: 2px solid #c84a31;
    border-radius: 4px;
}

.scan-section h2 {
    color: #c84a31;
    font-size: 20px;
    margin-bottom: 10px;
}

.scan-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.scan-status {
    display: none;
    padding: 10px 15px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 14px;
}

.scan-status.processing {
    display: block;
    background: #fff3cd;
    color: #664d03;
}

.scan-status.success {
    display: block;
    background: #d4edda;
    color: #155724;
}

.scan-status.error {
    display: block;
    background: #f8d7da;
    color: #721c24;
}

.add-product-section {
    background: #f9f9f9;
    padding: 20px;
    margin-bottom: 30px;
    border: 2px solid #dee2e6;
    border-radius: 4px;
}

.add-product-section h3 {
    font-size: 16px;
    color: #495057;
    margin-bottom: 10px;
}

.add-product-form input[type="text"] {
    width: 100%;
    padding: 10px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    box-sizing: border-box;
}

.add-product-form textarea {
    width: 100%;
    min-height: 120px;
    padding: 10px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    box-sizing: border-box;
    font-family: monospace;
}

.add-product-form button {
    padding: 10px 20px;
    background: #00a32a;
    color: white;
    border: none;
    border-radius: 3px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

.add-product-form button:hover {
    background: #008a20;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.products-table thead {
    background: #c84a31;
    color: white;
}

.products-table th {
    padding: 12px;
    text-align: left;
    font-weight: bold;
}

.products-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    vertical-align: top;
}

.products-table tbody tr:hover {
    background: #f9f9f9;
}

.ingredient-preview {
    color: #666;
    font-size: 13px;
    max-width: 400px;
}

.edit-row {
    display: none;
}

.edit-row.active {
    display: table-row;
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

.btn:hover { opacity: 0.8; }

.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #0073aa;
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
}

/* ============================================================
   MOBILE — max-width: 430px
   Same technique as page-allergen-profiles.php: the products table
   becomes stacked cards, one field per line via data-label. The
   Actions column (Edit/Delete) is a required control, not optional
   summary info, so it's restacked rather than hidden.
   ============================================================ */
@media (max-width: 430px) {

    .allergen-product-manager {
        padding: 0 12px;
        margin: 20px auto;
    }

    .allergen-product-manager h1 {
        font-size: 26px;
    }

    .scan-controls input[type="file"] {
        width: 100%;
    }

    .add-product-form input[type="text"],
    .add-product-form textarea {
        font-size: 16px; /* prevents iOS auto-zoom on focus */
    }

    .products-table,
    .products-table tbody {
        display: block;
    }

    .products-table thead {
        display: none;
    }

    .products-table tr {
        display: block;
        margin-bottom: 15px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 12px;
        background: white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }

    .products-table td {
        display: block;
        padding: 6px 0;
        border-bottom: none;
        max-width: none;
    }

    .products-table td[data-label]::before {
        content: attr(data-label);
        display: block;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #999;
        margin-bottom: 2px;
    }

    .products-table td[data-label="Actions"] {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .products-table td[data-label="Actions"] form {
        display: block !important;
        width: 100%;
    }

    .products-table td[data-label="Actions"] .btn {
        width: 100%;
        box-sizing: border-box;
        text-align: center;
        padding: 10px 12px;
        font-size: 14px;
    }

    /* Edit row: the table above is now display:block, so an active
       toggle needs to resolve to block too, not table-row. */
    .products-table tr.edit-row.active {
        display: block;
    }
}
</style>

<div class="allergen-product-manager">
    <a href="<?php echo home_url('/recipe-manager/'); ?>" class="back-link">← Back to Recipe Manager</a>
    <a href="<?php echo home_url('/allergen-profiles/'); ?>" class="back-link" style="margin-left: 15px;">🩺 Manage your Profiles</a>

    <h1>Allergen Product Library</h1>
    <p class="allergen-product-manager-subtitle">Scan packaged product labels to check them alongside your recipes.</p>

    <div class="allergen-disclaimer">
        This tool assists in reading ingredient labels and recipes. It does not replace reading the label yourself. Always verify against the physical product before consuming.
    </div>

    <?php if ($message): ?>
    <div class="message <?php echo esc_attr($message_type); ?>">
        <?php echo esc_html($message); ?>
    </div>
    <?php endif; ?>

    <!-- Scan a Product Label -->
    <div class="scan-section">
        <h2>Scan a Product Label</h2>
        <p style="font-size: 13px; color: #666; margin-top: 0;">Take or upload a clear photo of the ingredient list. Review and correct the extracted text below before saving — this reads exactly what the photo shows and can misread text, just like the recipe scanner.</p>
        <div class="scan-controls">
            <input type="file" id="productImageFile" accept="image/jpeg,image/png,image/gif,image/webp">
            <button type="button" id="scanProductBtn" class="btn" style="background: #c84a31; color: white;">📷 Scan Label</button>
        </div>
        <div id="scanStatus" class="scan-status"></div>
    </div>

    <!-- Add / Review Product Form -->
    <div class="add-product-section">
        <h3>Add Product to Library</h3>
        <form method="post" class="add-product-form" id="addProductForm">
            <?php wp_nonce_field('add_product'); ?>
            <input type="hidden" name="source_image_attachment_id" id="source_image_attachment_id" value="">
            <input type="text" name="product_name" id="product_name" placeholder="Product name" required>
            <textarea name="ingredient_text" id="ingredient_text" placeholder="Ingredient list, one ingredient per line (fill in manually, or scan a label above)" required></textarea>
            <button type="submit" name="add_product">+ Save Product</button>
        </form>
    </div>

    <!-- Products List -->
    <table class="products-table">
        <thead>
            <tr>
                <th style="width: 220px;">Product</th>
                <th>Ingredients</th>
                <th style="width: 160px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                <!-- View Row -->
                <tr id="view-<?php echo $product->product_id; ?>">
                    <td data-label="Product"><strong><?php echo esc_html($product->product_name); ?></strong></td>
                    <td class="ingredient-preview" data-label="Ingredients"><?php echo esc_html(wp_trim_words($product->ingredient_text, 20)); ?></td>
                    <td data-label="Actions">
                        <button type="button" onclick="editProduct(<?php echo $product->product_id; ?>)" class="btn btn-edit">✏️ Edit</button>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('delete_product_' . $product->product_id); ?>
                            <input type="hidden" name="product_id" value="<?php echo $product->product_id; ?>">
                            <button
                                type="submit"
                                name="delete_product"
                                class="btn btn-delete"
                                onclick="return confirm('Delete product \'<?php echo esc_js($product->product_name); ?>\'?')"
                            >🗑️ Delete</button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Row -->
                <tr id="edit-<?php echo $product->product_id; ?>" class="edit-row">
                    <td colspan="3">
                        <form method="post">
                            <?php wp_nonce_field('edit_product_' . $product->product_id); ?>
                            <input type="hidden" name="product_id" value="<?php echo $product->product_id; ?>">
                            <input type="text" name="product_name" value="<?php echo esc_attr($product->product_name); ?>" style="width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                            <textarea name="ingredient_text" style="width: 100%; min-height: 120px; padding: 8px; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box; font-family: monospace;" required><?php echo esc_textarea($product->ingredient_text); ?></textarea>
                            <button type="submit" name="edit_product" class="btn btn-save">✓ Save</button>
                            <button type="button" onclick="cancelEditProduct(<?php echo $product->product_id; ?>)" class="btn btn-cancel">Cancel</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="3" style="text-align: center; padding: 40px;">
                    No products yet. Scan a label above or add one manually.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function editProduct(productId) {
    document.getElementById('view-' + productId).style.display = 'none';
    document.getElementById('edit-' + productId).classList.add('active');
}

function cancelEditProduct(productId) {
    document.getElementById('view-' + productId).style.display = 'table-row';
    document.getElementById('edit-' + productId).classList.remove('active');
}

let selectedProductImage = null;

document.getElementById('productImageFile').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!validTypes.includes(file.type)) {
        alert('Please upload a valid image file (JPG, PNG, GIF, or WebP)');
        this.value = '';
        return;
    }

    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        alert('Image is too large! Please use an image smaller than 5 MB.\n\nCurrent size: ' + (file.size / 1024 / 1024).toFixed(2) + ' MB');
        this.value = '';
        return;
    }

    selectedProductImage = file;
});

document.getElementById('scanProductBtn').addEventListener('click', function() {
    if (!selectedProductImage) {
        alert('Please choose a photo first');
        return;
    }

    const statusDiv = document.getElementById('scanStatus');
    statusDiv.className = 'scan-status processing';
    statusDiv.textContent = '🔄 Uploading image and reading label...';

    this.disabled = true;

    const formData = new FormData();
    formData.append('action', 'upload_allergen_product_image');
    formData.append('nonce', '<?php echo wp_create_nonce('allergen_product_image_upload'); ?>');
    formData.append('product_image', selectedProductImage);

    const scanBtn = this;

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        scanBtn.disabled = false;

        if (data.success) {
            if (data.data.extracted_data.found === false) {
                statusDiv.className = 'scan-status error';
                statusDiv.textContent = '⚠️ No ingredient list found in that image. Try a clearer photo, or enter it manually below.';
            } else {
                statusDiv.className = 'scan-status success';
                statusDiv.textContent = '✅ Label read! Review the fields below before saving — check carefully against the physical label.';

                if (data.data.extracted_data.product_name) {
                    document.getElementById('product_name').value = data.data.extracted_data.product_name;
                }
                if (data.data.extracted_data.ingredient_text) {
                    document.getElementById('ingredient_text').value = data.data.extracted_data.ingredient_text;
                }
                document.getElementById('source_image_attachment_id').value = data.data.attachment_id;
            }
        } else {
            statusDiv.className = 'scan-status error';
            const errorMsg = data.data && data.data.message ? data.data.message : 'Unknown error';
            statusDiv.textContent = '❌ Error: ' + errorMsg;
        }
    })
    .catch(error => {
        scanBtn.disabled = false;
        statusDiv.className = 'scan-status error';
        statusDiv.textContent = '❌ Upload failed: ' + error.message;
    });
});
</script>

<?php get_footer(); ?>
