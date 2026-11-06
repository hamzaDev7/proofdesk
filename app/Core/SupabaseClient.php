<?php

namespace ProofDesk\Core;

class SupabaseClient
{
    private string $url;
    private string $anonKey;
    private ?string $serviceRoleKey;
    private ?string $userToken = null;

    public function __construct(?string $userToken = null)
    {
        $this->url = rtrim((string)Env::get('SUPABASE_URL', ''), '/');
        $this->anonKey = (string)Env::get('SUPABASE_ANON_KEY', '');
        $this->serviceRoleKey = Env::get('SUPABASE_SERVICE_ROLE_KEY', null);
        $this->userToken = $userToken;
    }

    public static function forUser(?string $jwt): self
    {
        return new self($jwt);
    }

    public static function admin(): self
    {
        $client = new self();
        $client->userToken = $client->serviceRoleKey;
        return $client;
    }

    public function isConfigured(): bool
    {
        return !empty($this->url) && !empty($this->anonKey);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAnonKey(): string
    {
        return $this->anonKey;
    }

    /**
     * Verify user JWT with Supabase Auth endpoint /auth/v1/user
     */
    public function getAuthUser(?string $jwt = null): ?array
    {
        $token = $jwt ?? $this->userToken;
        if (empty($token)) {
            return null;
        }

        $res = $this->request('GET', '/auth/v1/user', null, [], $token);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($res['data']['id'])) {
            return $res['data'];
        }

        return null;
    }

    /**
     * Sign up a new user via Supabase Auth
     */
    public function signUp(string $email, string $password, array $metadata = []): array
    {
        $payload = [
            'email' => $email,
            'password' => $password,
            'data' => $metadata
        ];

        return $this->request('POST', '/auth/v1/signup', $payload);
    }

    /**
     * Sign in user with email & password via Supabase Auth
     */
    public function signIn(string $email, string $password): array
    {
        $payload = [
            'email' => $email,
            'password' => $password
        ];

        return $this->request('POST', '/auth/v1/token?grant_type=password', $payload);
    }

    /**
     * Alias for signIn
     */
    public function signInWithPassword(string $email, string $password): array
    {
        return $this->signIn($email, $password);
    }

    /**
     * Request password reset email via Supabase Auth
     */
    public function resetPassword(string $email, ?string $redirectTo = null): array
    {
        $payload = ['email' => $email];
        $endpoint = '/auth/v1/recover';
        if ($redirectTo) {
            $endpoint .= '?redirect_to=' . urlencode($redirectTo);
        }

        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * Execute PostgREST query on table
     * e.g. table('projects')->select('*')->eq('status', 'in_progress')->get()
     */
    public function from(string $table): SupabaseQueryBuilder
    {
        return new SupabaseQueryBuilder($this, $table);
    }

    /**
     * Execute RPC (Stored Procedure)
     */
    public function rpc(string $functionName, array $params = []): array
    {
        return $this->request('POST', '/rest/v1/rpc/' . $functionName, $params);
    }

    /**
     * Storage: Upload file
     */
    public function uploadFile(string $bucket, string $path, string $fileContent, string $mimeType = 'application/octet-stream', bool $upsert = false): array
    {
        $headers = [
            'Content-Type: ' . $mimeType,
            'x-upsert: ' . ($upsert ? 'true' : 'false')
        ];

        return $this->rawRequest('POST', sprintf('/storage/v1/object/%s/%s', $bucket, ltrim($path, '/')), $fileContent, $headers);
    }

    /**
     * Storage: Create signed URL
     */
    public function createSignedUrl(string $bucket, string $path, int $expiresInSeconds = 3600): ?string
    {
        $payload = ['expiresIn' => $expiresInSeconds];
        $res = $this->request('POST', sprintf('/storage/v1/object/sign/%s/%s', $bucket, ltrim($path, '/')), $payload);

        if ($res['status'] >= 200 && $res['status'] < 300 && isset($res['data']['signedURL'])) {
            return $this->url . '/storage/v1' . $res['data']['signedURL'];
        }

        return null;
    }

    /**
     * Storage: Download file binary
     */
    public function downloadFile(string $bucket, string $path): ?string
    {
        $res = $this->rawRequest('GET', sprintf('/storage/v1/object/authenticated/%s/%s', $bucket, ltrim($path, '/')));
        if ($res['status'] === 200) {
            return $res['raw'];
        }
        return null;
    }

    /**
     * Main cURL request wrapper for Supabase REST API
     */
    public function request(string $method, string $endpoint, mixed $body = null, array $extraHeaders = [], ?string $overrideToken = null): array
    {
        $token = $overrideToken ?? $this->userToken ?? $this->anonKey;
        $headers = [
            'apikey: ' . $this->anonKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        foreach ($extraHeaders as $h) {
            $headers[] = $h;
        }

        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $url = $this->url . $endpoint;
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseRaw === false || !empty($error)) {
            return [
                'status' => $httpCode ?: 500,
                'data' => null,
                'error' => 'cURL connection error: ' . $error
            ];
        }

        $decoded = json_decode($responseRaw, true);

        return [
            'status' => $httpCode,
            'data' => $decoded !== null ? $decoded : $responseRaw,
            'error' => ($httpCode >= 400) ? ($decoded['message'] ?? $decoded['msg'] ?? $decoded['error_description'] ?? 'Supabase API Error') : null
        ];
    }

    /**
     * Raw cURL request wrapper for binary or non-JSON payloads
     */
    public function rawRequest(string $method, string $endpoint, ?string $body = null, array $customHeaders = []): array
    {
        $token = $this->userToken ?? $this->anonKey;
        $headers = [
            'apikey: ' . $this->anonKey,
            'Authorization: Bearer ' . $token
        ];
        foreach ($customHeaders as $h) {
            $headers[] = $h;
        }

        $url = $this->url . $endpoint;
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'raw' => $responseRaw,
            'error' => $error ?: null
        ];
    }
}
