<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;

class AuthController
{
    public function register(Request $request): void
    {
        $body = $request->getBody();

        $validation = RequestValidator::validate($body, [
            'email' => 'required|email',
            'password' => 'required|min:8',
            'full_name' => 'required|min:2|max:100',
            'workspace_name' => 'min:2|max:100',
            'role' => 'in:workspace_owner,workspace_member,client'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors'], 'VALIDATION_ERROR');
        }

        $email = trim($body['email']);
        $password = $body['password'];
        $fullName = trim($body['full_name']);
        $role = $body['role'] ?? 'workspace_owner';
        $workspaceName = trim($body['workspace_name'] ?? ($fullName . "'s Workspace"));

        $supabase = new SupabaseClient();
        if (!$supabase->isConfigured()) {
            Response::error('Supabase is not configured yet. Please configure .env file.', 500, null, 'CONFIG_ERROR');
        }

        $signUpRes = $supabase->signUp($email, $password, [
            'full_name' => $fullName,
            'role' => $role
        ]);

        if ($signUpRes['status'] >= 400 || empty($signUpRes['data']['user'] ?? $signUpRes['data']['id'])) {
            $msg = $signUpRes['error'] ?? 'Registration failed with Supabase Auth.';
            Response::error($msg, $signUpRes['status'] ?: 400, null, 'AUTH_FAILED');
        }

        $user = $signUpRes['data']['user'] ?? $signUpRes['data'];
        $userId = $user['id'];
        $token = $signUpRes['data']['access_token'] ?? null;
        $session = $signUpRes['data']['session'] ?? $signUpRes['data'];

        // If access token was not directly returned by signup, attempt immediate signin
        if (empty($token)) {
            $loginRes = $supabase->signIn($email, $password);
            if (!empty($loginRes['data']['access_token'])) {
                $token = $loginRes['data']['access_token'];
                $session = $loginRes['data'];
            }
        }

        // Use user token client if available, or service role fallback
        $userClient = !empty($token) ? SupabaseClient::forUser($token) : SupabaseClient::serviceRole();
        
        // Ensure profile exists
        $userClient->from('profiles')->insert([
            'id' => $userId,
            'full_name' => $fullName,
            'role' => $role
        ], false);

        // If registering as workspace owner, create initial workspace
        $workspace = null;
        if ($role === 'workspace_owner') {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $workspaceName), '-')) . '-' . substr(md5(uniqid()), 0, 6);
            $wsRes = $userClient->from('workspaces')->insert([
                'name' => $workspaceName,
                'slug' => $slug,
                'owner_id' => $userId
            ]);

            if ($wsRes['status'] < 300 && !empty($wsRes['data'])) {
                $workspace = is_array($wsRes['data']) ? ($wsRes['data'][0] ?? $wsRes['data']) : null;
                if ($workspace && isset($workspace['id'])) {
                    $userClient->from('workspace_members')->insert([
                        'workspace_id' => $workspace['id'],
                        'user_id' => $userId,
                        'role' => 'workspace_owner'
                    ], false);

                    ActivityLogger::log($userClient, $workspace['id'], null, $userId, 'workspace_created', ['name' => $workspaceName]);
                }
            }
        }

        Response::success([
            'user' => [
                'id' => $userId,
                'email' => $email,
                'full_name' => $fullName,
                'role' => $role
            ],
            'session' => [
                'access_token' => $token,
                'refresh_token' => $session['refresh_token'] ?? null,
                'expires_at' => $session['expires_at'] ?? null
            ],
            'workspace' => $workspace,
            'requires_email_confirmation' => empty($token)
        ], 'Registration successful', 201);
    }

    public function login(Request $request): void
    {
        $body = $request->getBody();

        $validation = RequestValidator::validate($body, [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors'], 'VALIDATION_ERROR');
        }

        $email = trim($body['email']);
        $password = $body['password'];

        $supabase = new SupabaseClient();
        if (!$supabase->isConfigured()) {
            Response::error('Supabase is not configured yet. Please configure .env file.', 500, null, 'CONFIG_ERROR');
        }

        $loginRes = $supabase->signIn($email, $password);

        if ($loginRes['status'] >= 400 || empty($loginRes['data']['access_token'])) {
            $msg = $loginRes['error'] ?? 'Invalid email or password.';
            Response::error($msg, 401, null, 'INVALID_CREDENTIALS');
        }

        $token = $loginRes['data']['access_token'];
        $user = $loginRes['data']['user'];
        $userId = $user['id'];

        $userClient = SupabaseClient::forUser($token);

        // Fetch profile
        $profile = $userClient->from('profiles')->eq('id', $userId)->first();
        if (!$profile) {
            // Self-heal profile if missing
            $userClient->from('profiles')->insert([
                'id' => $userId,
                'full_name' => $user['user_metadata']['full_name'] ?? split_part_email($email),
                'role' => $user['user_metadata']['role'] ?? 'workspace_member'
            ], false);
            $profile = $userClient->from('profiles')->eq('id', $userId)->first();
        }

        // Fetch user's workspaces
        $workspaces = $userClient->from('workspace_members')
            ->select('workspace_id, role, workspaces(id, name, slug, owner_id)')
            ->eq('user_id', $userId)
            ->get();

        // Self-heal: If user has no workspace membership yet, auto-provision or link one
        if (empty($workspaces)) {
            $adminClient = SupabaseClient::admin();
            $ownedWs = $adminClient->from('workspaces')->eq('owner_id', $userId)->first();
            if ($ownedWs) {
                $adminClient->from('workspace_members')->insert([
                    'workspace_id' => $ownedWs['id'],
                    'user_id' => $userId,
                    'role' => 'workspace_owner'
                ], false);
                $workspaces = [
                    [
                        'workspace_id' => $ownedWs['id'],
                        'role' => 'workspace_owner',
                        'workspaces' => $ownedWs
                    ]
                ];
            } else {
                $userName = $profile['full_name'] ?? ($user['user_metadata']['full_name'] ?? 'My');
                $wsName = trim($userName) . "'s Workspace";
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $wsName), '-')) . '-' . substr(md5(uniqid()), 0, 6);
                $newWsRes = $adminClient->from('workspaces')->insert([
                    'name' => $wsName,
                    'slug' => $slug,
                    'owner_id' => $userId
                ]);
                if (!empty($newWsRes['data'])) {
                    $newWs = is_array($newWsRes['data']) ? ($newWsRes['data'][0] ?? $newWsRes['data']) : $newWsRes['data'];
                    $adminClient->from('workspace_members')->insert([
                        'workspace_id' => $newWs['id'],
                        'user_id' => $userId,
                        'role' => 'workspace_owner'
                    ], false);
                    $workspaces = [
                        [
                            'workspace_id' => $newWs['id'],
                            'role' => 'workspace_owner',
                            'workspaces' => $newWs
                        ]
                    ];
                }
            }
        }

        $activeWorkspace = !empty($workspaces) ? $workspaces[0] : null;

        Response::success([
            'session' => [
                'access_token' => $token,
                'refresh_token' => $loginRes['data']['refresh_token'] ?? null,
                'expires_at' => $loginRes['data']['expires_at'] ?? null,
                'token_type' => $loginRes['data']['token_type'] ?? 'bearer'
            ],
            'user' => [
                'id' => $userId,
                'email' => $email,
                'full_name' => $profile['full_name'] ?? '',
                'avatar_url' => $profile['avatar_url'] ?? '',
                'role' => $profile['role'] ?? 'workspace_member',
                'phone' => $profile['phone'] ?? ''
            ],
            'workspaces' => $workspaces,
            'active_workspace' => $activeWorkspace
        ], 'Login successful');
    }

    public function profile(Request $request): void
    {
        Response::success($request->user, 'User profile retrieved');
    }

    public function updateProfile(Request $request): void
    {
        $body = $request->getBody();
        $userClient = SupabaseClient::forUser($request->token);

        $updateData = [];
        if (isset($body['full_name'])) {
            $updateData['full_name'] = trim($body['full_name']);
        }
        if (isset($body['avatar_url'])) {
            $updateData['avatar_url'] = trim($body['avatar_url']);
        }
        if (isset($body['phone'])) {
            $updateData['phone'] = trim($body['phone']);
        }

        if (empty($updateData)) {
            Response::error('No fields provided to update.', 400);
        }

        $res = $userClient->from('profiles')
            ->eq('id', $request->user['id'])
            ->update($updateData);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to update profile.', $res['status']);
        }

        Response::success($res['data'] ?? $updateData, 'Profile updated successfully');
    }

    public function forgotPassword(Request $request): void
    {
        $body = $request->getBody();
        $validation = RequestValidator::validate($body, [
            'email' => 'required|email'
        ]);

        if (!$validation['valid']) {
            Response::error('Valid email is required', 422, $validation['errors']);
        }

        // Dynamically detect the hosted frontend URL
        $clientOrigin = $request->getHeader('origin');
        if (empty($clientOrigin)) {
            $referer = $request->getHeader('referer');
            if (!empty($referer)) {
                $parsed = parse_url($referer);
                $clientOrigin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                if (!empty($parsed['port']) && !in_array($parsed['port'], [80, 443])) {
                    $clientOrigin .= ':' . $parsed['port'];
                }
            }
        }

        $host = $request->getHeader('x-forwarded-host') ?? $request->getHeader('host') ?? '';
        $proto = $request->getHeader('x-forwarded-proto') ?? 'https';
        $detectedUrl = ($host !== '') ? ($proto . '://' . $host) : null;

        $targetUrl = $body['redirect_url']
            ?? $clientOrigin
            ?? $detectedUrl
            ?? Env::get('APP_URL', 'https://proofdesk-rosy.vercel.app');

        // Target should point to login.html
        if (!str_ends_with($targetUrl, '.html')) {
            $redirectUrl = rtrim($targetUrl, '/') . '/login.html';
        } else {
            $redirectUrl = $targetUrl;
        }

        $supabase = new SupabaseClient();
        $res = $supabase->resetPassword(trim($body['email']), $redirectUrl);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to send password reset request.', $res['status']);
        }

        Response::success(null, 'Password reset instructions have been sent to your email.');
    }

    public function getWorkspaces(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $workspaces = $userClient->from('workspace_members')
            ->select('workspace_id, role, created_at, workspaces(id, name, slug, owner_id, created_at)')
            ->eq('user_id', $request->user['id'])
            ->get();

        Response::success($workspaces, 'Workspaces retrieved');
    }

    public function createWorkspace(Request $request): void
    {
        $body = $request->getBody();
        $validation = RequestValidator::validate($body, [
            'name' => 'required|min:2|max:100'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $name = trim($body['name']);
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-')) . '-' . substr(md5(uniqid()), 0, 6);
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('workspaces')->insert([
            'name' => $name,
            'slug' => $slug,
            'owner_id' => $request->user['id']
        ]);

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to create workspace', $res['status']);
        }

        $ws = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        // Add member record
        $userClient->from('workspace_members')->insert([
            'workspace_id' => $ws['id'],
            'user_id' => $request->user['id'],
            'role' => 'workspace_owner'
        ], false);

        ActivityLogger::log($userClient, $ws['id'], null, $request->user['id'], 'workspace_created', ['name' => $name]);

        Response::success($ws, 'Workspace created successfully', 201);
    }
}

function split_part_email(string $email): string {
    $parts = explode('@', $email);
    return ucfirst($parts[0] ?? 'User');
}
