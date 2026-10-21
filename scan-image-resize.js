/**
 * Scan Image Resize
 *
 * Shared browser-side preparation for images that are sent to the Claude API
 * for reading: recipe scan import (page-recipe-editor.php) and allergen
 * product-label OCR (page-allergen-products.php). The photo gallery does not
 * use this and keeps its own 5 MB limit.
 *
 * Images over 2000 px on the long edge are downsized to 2000 px and
 * re-encoded as JPEG (quality 0.85); smaller images upload unchanged. HEIC is
 * always re-encoded to JPEG, since neither the server nor the API can read it.
 * Server-side, prepare_scan_image_for_api() (scan-image-functions.php) is the
 * fallback for anything the browser can't decode.
 */

const SCAN_UPLOAD_MAX_BYTES = 20 * 1024 * 1024;
const SCAN_RESIZE_MAX_EDGE = 2000;
const SCAN_JPEG_QUALITY = 0.85;
const SCAN_VALID_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
const SCAN_HEIC_ERROR = "This photo is in HEIC format, which this browser can't read. Please use a JPEG or PNG. On iPhone: Settings > Camera > Formats > Most Compatible.";

function isHeicScanFile(file) {
    const type = (file.type || '').toLowerCase();
    if (type === 'image/heic' || type === 'image/heif' || type === 'image/heic-sequence' || type === 'image/heif-sequence') {
        return true;
    }
    // Some browsers (e.g. Chrome on Windows) report an empty type for .heic files
    return type === '' && /\.(heic|heif)$/i.test(file.name || '');
}

/**
 * Check the ORIGINAL file's type and size before any resizing.
 * Returns an error message, or null if the file can go ahead.
 */
function validateScanFile(file) {
    if (!SCAN_VALID_TYPES.includes(file.type) && !isHeicScanFile(file)) {
        return 'Please upload a valid image file (JPG, PNG, GIF, or WebP)';
    }

    if (file.size > SCAN_UPLOAD_MAX_BYTES) {
        return 'Image is too large! Please use an image smaller than 20 MB.\n\nCurrent size: ' + (file.size / 1024 / 1024).toFixed(2) + ' MB';
    }

    return null;
}

// Decode with EXIF orientation applied. Resolves to a drawable source, or null if the browser can't decode it.
async function decodeScanImage(file) {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch (e) {
            // Fall through to <img>, which can decode some formats createImageBitmap can't
        }
    }

    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        return img;
    } catch (e) {
        return null;
    } finally {
        URL.revokeObjectURL(url);
    }
}

/**
 * Resolve to the file to upload: a resized JPEG, or the original file.
 * Rejects with an Error (message suitable to show the user) if a HEIC
 * file can't be decoded by this browser.
 */
async function prepareScanFile(file) {
    const heic = isHeicScanFile(file);
    const image = await decodeScanImage(file);

    if (!image) {
        if (heic) {
            throw new Error(SCAN_HEIC_ERROR);
        }
        // Still under 20 MB; the server resizes it if it's too large for the API
        return file;
    }

    const width = image.naturalWidth || image.width;
    const height = image.naturalHeight || image.height;
    const longEdge = Math.max(width, height);

    if (!width || !height) {
        if (image.close) image.close();
        if (heic) throw new Error(SCAN_HEIC_ERROR);
        return file;
    }

    // At or under the limit: upload unchanged (HEIC still needs converting to JPEG)
    if (longEdge <= SCAN_RESIZE_MAX_EDGE && !heic) {
        if (image.close) image.close();
        return file;
    }

    // Never upscale
    const scale = Math.min(1, SCAN_RESIZE_MAX_EDGE / longEdge);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);

    const ctx = canvas.getContext('2d');
    // JPEG has no transparency; fill white so transparent PNG areas don't turn black
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
    if (image.close) image.close();

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', SCAN_JPEG_QUALITY));

    if (!blob) {
        if (heic) throw new Error(SCAN_HEIC_ERROR);
        return file;
    }

    const baseName = (file.name || 'scan').replace(/\.[^.]*$/, '') || 'scan';
    return new File([blob], baseName + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
}
