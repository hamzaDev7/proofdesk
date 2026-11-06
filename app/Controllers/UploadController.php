<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;

class UploadController
{
    private static array $allowedMimes = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/svg+xml'
    ];

    public function uploadImage(Request $request): void
    {
        $files = $request->getFiles();
        $file = $files['image'] ?? ($files['file'] ?? ($files['avatar'] ?? null));

        if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
            Response::error('No image file uploaded.', 400);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload error code: ' . $file['error'], 400);
        }

        // Validate max 10MB
        if ((int)$file['size'] > 10485760) {
            Response::error('Image file exceeds the 10MB limit.', 400);
        }

        // Validate MIME type with finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::$allowedMimes) && !in_array($file['type'], self::$allowedMimes)) {
            Response::error('Only image files (PNG, JPG, JPEG, WEBP, GIF, SVG) are allowed.', 400);
        }

        $type = $request->input('type') ?? 'image';
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'png';
        if ($ext === 'jpeg') $ext = 'jpg';

        $filename = sprintf('%s_%s_%s.%s', $type, time(), substr(bin2hex(random_bytes(4)), 0, 8), $ext);

        $uploadDir = dirname(__DIR__, 2) . '/public/storage/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            // Fallback to copy if move_uploaded_file fails in CLI/simulated env
            if (!copy($file['tmp_name'], $destination)) {
                Response::error('Failed to save uploaded image.', 500);
            }
        }

        $relativeUrl = 'storage/uploads/' . $filename;

        // If uploading avatar, update profile automatically
        if ($type === 'avatar' && !empty($request->user['id'])) {
            $adminClient = SupabaseClient::admin();
            $adminClient->from('profiles')
                ->eq('id', $request->user['id'])
                ->update(['avatar_url' => $relativeUrl]);
        }

        // If uploading system/workspace logo
        if ($type === 'logo') {
            // Also copy to assets/images/logo.png if desired
            $systemLogoPath = dirname(__DIR__, 2) . '/public/assets/images/logo.png';
            copy($destination, $systemLogoPath);
        }

        Response::success([
            'url' => $relativeUrl,
            'filename' => $filename,
            'type' => $type
        ], 'Image uploaded successfully');
    }
}
