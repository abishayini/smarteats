<?php
/**
 * Smart Eats - image uploads
 *
 * Handles menu item photos and restaurant logos. An upload is the most
 * exposed surface in the panel, so the file is checked by its actual
 * contents rather than its name, and it is stored under a name this
 * application chooses.
 *
 * The two entry points share one implementation because the checks are
 * identical; only the destination folder and the filename prefix differ.
 */

require_once __DIR__ . '/functions.php';

/**
 * Validate and store an uploaded image.
 *
 * @param array  $file    one entry from $_FILES
 * @param string $folder  subfolder of /uploads, e.g. 'menu' or 'logos'
 * @param string $prefix  filename prefix, e.g. 'dish' or 'logo'
 *
 * @return array{ok:bool, filename:string, error:string}
 */
function store_uploaded_image(array $file, string $folder, string $prefix): array
{
    $fail = fn(string $message) => ['ok' => false, 'filename' => '', 'error' => $message];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $fail('No file was selected.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $fail(match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Try again.',
            default                                   => 'The file could not be uploaded.',
        });
    }

    // Confirms the file really came through PHP's upload handling and is
    // not a path someone supplied to reach elsewhere on the disk.
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('That file was not uploaded properly.');
    }

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return $fail('Images must be under ' . round(MAX_UPLOAD_BYTES / 1048576, 1) . ' MB.');
    }

    // The declared type comes from the browser and is trivially faked,
    // so the real type is read from the file contents.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        return $fail('Use a JPG, PNG or WebP image.');
    }

    // A second check: a file can carry an image MIME type and still not
    // be a usable image.
    $dimensions = @getimagesize($file['tmp_name']);
    if ($dimensions === false) {
        return $fail('That file is not a readable image.');
    }

    $target = UPLOADS_PATH . '/' . $folder;

    if (!is_dir($target) && !mkdir($target, 0775, true)) {
        return $fail('The uploads folder could not be created.');
    }

    // The application names the file. Nothing from the original name is
    // kept, so a crafted filename cannot influence where it lands.
    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4))
              . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], $target . '/' . $filename)) {
        return $fail('The image could not be saved. Check folder permissions.');
    }

    return ['ok' => true, 'filename' => $filename, 'error' => ''];
}

/** Delete a stored image, ignoring anything that looks like a path. */
function delete_uploaded_image(?string $filename, string $folder): void
{
    if (!$filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
        return;
    }

    $path = UPLOADS_PATH . '/' . $folder . '/' . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

/* ------------------------------------------------------------------ */
/* Named wrappers, so call sites read clearly                          */
/* ------------------------------------------------------------------ */

function store_menu_image(array $file): array
{
    return store_uploaded_image($file, 'menu', 'dish');
}

function delete_menu_image(?string $filename): void
{
    delete_uploaded_image($filename, 'menu');
}

function store_restaurant_logo(array $file): array
{
    return store_uploaded_image($file, 'logos', 'logo');
}

function delete_restaurant_logo(?string $filename): void
{
    delete_uploaded_image($filename, 'logos');
}
