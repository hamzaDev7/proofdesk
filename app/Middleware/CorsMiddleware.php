<?php

namespace ProofDesk\Middleware;

use ProofDesk\Core\Request;
use ProofDesk\Core\Env;

class CorsMiddleware
{
    public function handle(Request $request): void
    {
        $origin = $request->getHeader('origin', '');

        if (!empty($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, apikey, prefer, x-workspace-id');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
