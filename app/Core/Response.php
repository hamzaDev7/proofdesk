<?php

namespace ProofDesk\Core;

class Response
{
    private static array $defaultHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin'
    ];

    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);

        $mergedHeaders = array_merge(self::$defaultHeaders, $headers);
        foreach ($mergedHeaders as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $status = 200, array $meta = []): void
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        self::json($response, $status);
    }

    public static function error(string $message, int $status = 400, mixed $errors = null, ?string $code = null): void
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($code !== null) {
            $response['code'] = $code;
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        self::json($response, $status);
    }
}
