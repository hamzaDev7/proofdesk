<?php

namespace ProofDesk\Middleware;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\Env;

class RateLimitMiddleware
{
    private static string $storageDir = '';

    public static function limit(int $maxRequests = 60, int $decaySeconds = 60): \Closure
    {
        return function (Request $request) use ($maxRequests, $decaySeconds) {
            if (empty(self::$storageDir)) {
                self::$storageDir = dirname(__DIR__, 2) . '/storage/logs';
                if (!is_dir(self::$storageDir)) {
                    @mkdir(self::$storageDir, 0755, true);
                }
            }

            $key = md5(($request->user['id'] ?? $request->getIp()) . '_' . $request->getPath());
            $file = self::$storageDir . '/rate_' . $key . '.json';

            $now = time();
            $data = ['count' => 0, 'reset_at' => $now + $decaySeconds];

            if (file_exists($file)) {
                $content = @file_get_contents($file);
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['reset_at'])) {
                    if ($decoded['reset_at'] > $now) {
                        $data = $decoded;
                    }
                }
            }

            $data['count']++;

            if ($data['count'] > $maxRequests) {
                $retryAfter = $data['reset_at'] - $now;
                header('Retry-After: ' . $retryAfter);
                Response::error('Too many requests. Please slow down and try again.', 429, [
                    'retry_after_seconds' => $retryAfter
                ], 'TOO_MANY_REQUESTS');
            }

            @file_put_contents($file, json_encode($data));
        };
    }
}
