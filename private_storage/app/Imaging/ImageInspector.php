<?php

declare(strict_types=1);

namespace FieldPulse\Imaging;

use FieldPulse\Config\Config;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * Server-side validation of an uploaded image (§10).
 *
 * The client asserts the MIME type and the file size; both are treated as
 * claims. This class establishes the truth:
 *
 *   1. $_FILES error code must be UPLOAD_ERR_OK
 *   2. is_uploaded_file() must pass — the only way to distinguish a real upload
 *      from a path an attacker wrote into $_FILES
 *   3. finfo must report image/jpeg or image/png
 *   4. getimagesize() must parse a real header and report sane dimensions
 *   5. width * height must be under the pixel cap, checked BEFORE any decode,
 *      so a decompression bomb costs nothing
 *   6. the bytes must actually decode
 *
 * Step 5 is the one that is usually missing. A 200x200 JPEG that decompresses
 * to 2 GB of bitmap will pass a naive "is it an image" test and then take the
 * shared-hosting PHP process out of memory — taking every other request on the
 * account with it.
 */
final class ImageInspector
{
    public const MIME_JPEG = 'image/jpeg';
    public const MIME_PNG  = 'image/png';

    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed> $file A $_FILES entry.
     * @return array{
     *     mime:string,width:int,height:int,size:int,
     *     extension:string,tmp_path:string
     * }
     * @throws ApiException
     */
    public static function inspectUploaded(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw self::uploadError($error);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            // Not an upload we can trust, whatever the client claimed.
            throw new ApiException(
                422,
                ErrorCode::NOT_UPLOADED_FILE,
                'The submitted file is not a valid upload.'
            );
        }

        return self::inspectPath($tmp);
    }

    /**
     * Inspect a file already on disk. Used for the upload path and by the queue
     * worker, which re-validates a quarantined file before trusting it.
     *
     * @return array{mime:string,width:int,height:int,size:int,extension:string,tmp_path:string}
     * @throws ApiException
     */
    public static function inspectPath(string $path): array
    {
        $c = Config::instance();

        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException(422, ErrorCode::FILE_MISSING, 'Uploaded file is not readable.');
        }

        $size = (int) filesize($path);

        if ($size <= 0) {
            throw new ApiException(422, ErrorCode::IMAGE_INVALID, 'Uploaded file is empty.');
        }

        if ($size > $c->int('storage.max_upload_bytes')) {
            throw new ApiException(
                413,
                ErrorCode::FILE_TOO_LARGE,
                'File exceeds the maximum allowed size.',
                ['max_bytes' => $c->int('storage.max_upload_bytes')]
            );
        }

        $detected = self::detectMime($path);

        if (!in_array($detected, $c->arr('storage.allowed_mimes'), true)) {
            throw new ApiException(
                415,
                ErrorCode::UNSUPPORTED_MEDIA_TYPE_FILE,
                'Only JPEG and PNG images are accepted.',
                ['detected' => $detected]
            );
        }

        $info = @getimagesize($path);

        if ($info === false || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            throw new ApiException(
                422,
                ErrorCode::IMAGE_INVALID,
                'File does not contain a readable image header.'
            );
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        if ($width < $c->int('upload.min_dimension') || $height < $c->int('upload.min_dimension')) {
            throw new ApiException(
                422,
                ErrorCode::IMAGE_INVALID,
                'Image dimensions are too small to be field evidence.',
                ['min_dimension' => $c->int('upload.min_dimension')]
            );
        }

        if ($width > $c->int('upload.max_dimension') || $height > $c->int('upload.max_dimension')) {
            throw new ApiException(
                422,
                ErrorCode::IMAGE_INVALID,
                'Image dimensions are implausibly large.',
                ['max_dimension' => $c->int('upload.max_dimension')]
            );
        }

        $pixels = $width * $height;

        if ($pixels > $c->int('upload.max_pixels')) {
            throw new ApiException(
                422,
                ErrorCode::IMAGE_INVALID,
                'Image pixel count exceeds the allowed limit.',
                ['max_pixels' => $c->int('upload.max_pixels')]
            );
        }

        if (!self::decodes($path, $detected)) {
            throw new ApiException(
                422,
                ErrorCode::IMAGE_INVALID,
                'Image data is corrupt and cannot be decoded.'
            );
        }

        return [
            'mime'      => $detected,
            'width'     => $width,
            'height'    => $height,
            'size'      => $size,
            'extension' => $detected === self::MIME_PNG ? 'png' : 'jpg',
            'tmp_path'  => $path,
        ];
    }

    /**
     * finfo over the file contents, never the client-declared type or the file
     * extension. `mime_content_type` reads the whole file; finfo with
     * FILEINFO_MIME_TYPE reads only the header it needs.
     */
    public static function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            throw new ApiException(500, ErrorCode::INTERNAL_ERROR, 'fileinfo extension is not available.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new ApiException(500, ErrorCode::INTERNAL_ERROR, 'Unable to initialise fileinfo.');
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return is_string($mime) ? strtolower(trim($mime)) : 'application/octet-stream';
    }

    /**
     * Prove the pixels actually decode, then free them immediately.
     *
     * A file can carry a valid header and truncated body — a JPEG cut
     * mid-stream still reports its dimensions from the SOF marker. Only a real
     * decode catches that, and it must happen before the file is admitted to
     * quarantine, otherwise the queue worker is the thing that fails.
     */
    public static function decodes(string $path, string $mime): bool
    {
        $image = match ($mime) {
            self::MIME_JPEG => @imagecreatefromjpeg($path),
            self::MIME_PNG  => @imagecreatefrompng($path),
            default         => false,
        };

        if ($image === false) {
            return false;
        }

        imagedestroy($image);

        return true;
    }

    public static function sha256(string $path): string
    {
        $digest = hash_file('sha256', $path);

        if ($digest === false) {
            throw new ApiException(500, ErrorCode::INTERNAL_ERROR, 'Unable to hash the uploaded file.');
        }

        return $digest;
    }

    /**
     * Translate a PHP upload error into an honest, non-enumerating response.
     */
    public static function uploadError(int $code): ApiException
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => new ApiException(
                413,
                ErrorCode::FILE_TOO_LARGE,
                'File exceeds the maximum upload size allowed by the server.'
            ),
            UPLOAD_ERR_PARTIAL => new ApiException(
                422,
                ErrorCode::UPLOAD_ERROR,
                'Upload was interrupted. Please retry.'
            ),
            UPLOAD_ERR_NO_FILE => new ApiException(
                422,
                ErrorCode::FILE_MISSING,
                'No file was uploaded.'
            ),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => new ApiException(
                500,
                ErrorCode::STORAGE_UNAVAILABLE,
                'The server could not store the upload. Please retry.'
            ),
            UPLOAD_ERR_EXTENSION => new ApiException(
                500,
                ErrorCode::STORAGE_UNAVAILABLE,
                'A server extension blocked the upload.'
            ),
            default => new ApiException(422, ErrorCode::UPLOAD_ERROR, 'Upload failed.'),
        };
    }
}
