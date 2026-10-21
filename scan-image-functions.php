<?php
/**
 * Scan Image Functions
 *
 * Shared upload-size limit and API-preparation step for the two photo-to-text
 * features that send an uploaded image to the Claude API: recipe scan import
 * (recipe-image-upload-handler.php) and allergen product-label OCR
 * (allergen-image-upload-handler.php). The photo gallery does not go through
 * here — it never calls the API and keeps its own 5 MB limit.
 *
 * Browser-side, scan-image-resize.js already downsizes most photos to 2000 px
 * before upload. prepare_scan_image_for_api() is the server-side fallback for
 * files the browser couldn't decode or that are still too big for the API.
 */

// Security check
if (!defined('ABSPATH')) exit;

// Largest original file accepted for a scan upload (checked client- and server-side)
define('SCAN_UPLOAD_MAX_BYTES', 20 * 1024 * 1024);

// The API rejects images over 10 MB base64-encoded; stay safely under it
define('SCAN_API_MAX_BASE64_BYTES', (int) (9.5 * 1024 * 1024));

// The API rejects images over 8000 px on either side
define('SCAN_API_MAX_DIMENSION', 8000);

// Long edge used when a temporary copy has to be resized for the API
define('SCAN_API_RESIZE_EDGE', 2000);

/**
 * Check an entry of $_FILES against the scan upload limit, before it is stored.
 *
 * @param array $file An entry of $_FILES
 * @return string|null Error message, or null if the file is within the limit
 */
function scan_upload_size_error($file) {
    $too_large = (isset($file['error']) && in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true))
        || (isset($file['size']) && $file['size'] > SCAN_UPLOAD_MAX_BYTES);

    if (!$too_large) {
        return null;
    }

    $message = 'Image is too large. Please use an image smaller than 20 MB.';
    if (!empty($file['size'])) {
        $message .= ' Current size: ' . number_format($file['size'] / 1024 / 1024, 2) . ' MB';
    }
    return $message;
}

/**
 * Read an uploaded scan image and return it base64-encoded for the Claude API.
 *
 * If the image is too large for the API (base64 payload over ~9.5 MB, either
 * side over 8000 px) or in a format the API doesn't accept, a TEMPORARY JPEG
 * copy (long edge down to 2000 px, quality 85) is made with
 * wp_get_image_editor(), sent instead, and deleted. The stored attachment is
 * never modified.
 *
 * @param string $image_path Path to the attachment file (get_attached_file())
 * @return array ['success' => true, 'base64' => ..., 'mime_type' => ...]
 *               or ['success' => false, 'error' => ...]
 */
function prepare_scan_image_for_api($image_path) {
    $api_mime_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');

    $file_size = @filesize($image_path);
    if (!$file_size) {
        return array('success' => false, 'error' => 'Could not read the uploaded image.');
    }

    $image_type = wp_check_filetype($image_path);
    $mime_type = $image_type['type'];
    $dimensions = @getimagesize($image_path);

    $base64_size = 4 * (int) ceil($file_size / 3);
    $too_big = $base64_size > SCAN_API_MAX_BASE64_BYTES
        || ($dimensions && max($dimensions[0], $dimensions[1]) > SCAN_API_MAX_DIMENSION);

    if (!$too_big && in_array($mime_type, $api_mime_types, true)) {
        return array(
            'success' => true,
            'base64' => base64_encode(file_get_contents($image_path)),
            'mime_type' => $mime_type,
        );
    }

    // Resize/convert a temporary copy
    $error = array(
        'success' => false,
        'error' => 'This image is too large or in a format that could not be processed for reading. Please try a smaller JPEG or PNG.',
    );

    $editor = wp_get_image_editor($image_path);
    if (is_wp_error($editor)) {
        return $error;
    }

    $size = $editor->get_size();
    if (max($size['width'], $size['height']) > SCAN_API_RESIZE_EDGE) {
        $resized = $editor->resize(SCAN_API_RESIZE_EDGE, SCAN_API_RESIZE_EDGE);
        if (is_wp_error($resized)) {
            return $error;
        }
    }
    $editor->maybe_exif_rotate();
    $editor->set_quality(85);

    $temp_dir = get_temp_dir();
    $temp_file = $temp_dir . wp_unique_filename($temp_dir, 'scan-api-' . wp_generate_password(12, false) . '.jpg');
    $saved = $editor->save($temp_file, 'image/jpeg');

    if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) {
        return $error;
    }

    $image_data = file_get_contents($saved['path']);
    @unlink($saved['path']);

    if ($image_data === false || 4 * (int) ceil(strlen($image_data) / 3) > SCAN_API_MAX_BASE64_BYTES) {
        return $error;
    }

    return array(
        'success' => true,
        'base64' => base64_encode($image_data),
        'mime_type' => 'image/jpeg',
    );
}
