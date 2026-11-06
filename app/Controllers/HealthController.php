<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Core\Database;
use ProofDesk\Core\Env;

class HealthController
{
    public function check(Request $request): void
    {
        $supabase = new SupabaseClient();
        $isConfigured = $supabase->isConfigured();

        $supabaseStatus = 'unconfigured';
        $supabaseLatencyMs = null;

        if ($isConfigured) {
            $start = microtime(true);
            // Quick ping to Supabase Auth health/settings
            $res = $supabase->request('GET', '/auth/v1/settings');
            $supabaseLatencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($res['status'] >= 200 && $res['status'] < 400) {
                $supabaseStatus = 'connected';
            } elseif ($res['status'] === 401 || $res['status'] === 403) {
                // Key format reached Supabase service
                $supabaseStatus = 'reachable_auth_required';
            } else {
                $supabaseStatus = 'unreachable';
            }
        }

        $pdoStatus = Database::isAvailable() ? 'connected' : 'not_configured';

        $data = [
            'status' => 'healthy',
            'app' => 'ProofDesk SaaS API',
            'version' => '1.0.0',
            'php_version' => PHP_VERSION,
            'timestamp' => date('c'),
            'environment' => Env::get('APP_ENV', 'development'),
            'services' => [
                'supabase_rest' => [
                    'configured' => $isConfigured,
                    'status' => $supabaseStatus,
                    'latency_ms' => $supabaseLatencyMs
                ],
                'pdo_database' => [
                    'status' => $pdoStatus
                ]
            ]
        ];

        Response::success($data, 'ProofDesk API health check successful');
    }
}
