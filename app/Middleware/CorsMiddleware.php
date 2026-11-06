<?php

namespace ProofDesk\Middleware;

use ProofDesk\Core\Request;
use ProofDesk\Core\Env;

class CorsMiddleware
{
    public function handle(Request $request): void
    {
        $origin = $request->getHeader('origin', '*');
        $allowed = Env::get('CORS_ALLOWED_ORIGINS', '*');

        if ($allowed !== '*') {
            $allowedList = array_map('trim', explode(',', $allowed));
            if (in_array($origin, $allowedList)) {
                header('Access-Control-Allow-Origin: ' . $origin);
            } else {
                // If localhost development, be permissive
                if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                }
            }
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, apikey, prefer');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
