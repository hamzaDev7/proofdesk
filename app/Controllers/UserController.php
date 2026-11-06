<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;

class UserController
{
    public function getSettings(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $settings = $userClient->from('user_settings')
            ->eq('user_id', $request->user['id'])
            ->first();

        if (!$settings) {
            // Self-heal default settings
            $userClient->from('user_settings')->insert([
                'user_id' => $request->user['id'],
                'theme' => 'light',
                'email_notifications' => true,
                'in_app_notifications' => true
            ], false);

            $settings = [
                'user_id' => $request->user['id'],
                'theme' => 'light',
                'email_notifications' => true,
                'in_app_notifications' => true
            ];
        }

        Response::success($settings, 'User settings retrieved');
    }

    public function updateSettings(Request $request): void
    {
        $body = $request->getBody();
        $userClient = SupabaseClient::forUser($request->token);

        $allowed = ['theme', 'email_notifications', 'in_app_notifications', 'preferences'];
        $updateData = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $updateData[$f] = $body[$f];
            }
        }

        if (empty($updateData)) {
            Response::error('No settings provided to update', 400);
        }

        $res = $userClient->from('user_settings')
            ->eq('user_id', $request->user['id'])
            ->update($updateData);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to update settings', $res['status']);
        }

        Response::success($res['data'] ?? $updateData, 'Settings updated successfully');
    }

    public function getWorkspaceMembers(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::error('Workspace ID required', 400);
        }

        $adminClient = SupabaseClient::admin();
        $members = $adminClient->from('workspace_members')
            ->select('id, workspace_id, user_id, role, created_at')
            ->eq('workspace_id', $wsId)
            ->get();

        if (!is_array($members)) {
            $members = [];
        }

        $userIds = array_values(array_unique(array_filter(array_column($members, 'user_id'))));
        $profileMap = [];
        if (!empty($userIds)) {
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url, phone')
                ->in('id', $userIds)
                ->get();
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $profileMap[$p['id']] = $p;
                }
            }
        }

        // Also fetch user emails from Supabase Auth admin API so owner can see them
        $usersRes = $adminClient->request('GET', '/auth/v1/admin/users');
        $emailMap = [];
        if (isset($usersRes['data']['users'])) {
            foreach ($usersRes['data']['users'] as $u) {
                $emailMap[$u['id']] = $u['email'];
            }
        }

        foreach ($members as &$m) {
            $uid = $m['user_id'] ?? null;
            $prof = $profileMap[$uid] ?? [
                'id' => $uid,
                'full_name' => '',
                'avatar_url' => '',
                'phone' => ''
            ];
            $prof['email'] = $emailMap[$uid] ?? '';
            $m['profiles'] = $prof;
        }
        unset($m);

        Response::success($members, 'Workspace members retrieved');
    }

    public function addWorkspaceMember(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::error('Workspace ID required', 400);
        }

        $body = $request->getBody();
        $email = trim($body['email'] ?? '');
        $userId = $body['user_id'] ?? null;
        $role = $body['role'] ?? 'workspace_member';

        if (!in_array($role, ['workspace_owner', 'workspace_member', 'client'])) {
            $role = 'workspace_member';
        }

        $adminClient = SupabaseClient::admin();
        $fullName = trim($body['full_name'] ?? '');
        $tempPassword = null;

        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Valid email address is required.', 422);
            }

            // Find user by email in auth
            $usersRes = $adminClient->request('GET', '/auth/v1/admin/users');
            $existingUser = null;
            if (isset($usersRes['data']['users'])) {
                foreach ($usersRes['data']['users'] as $u) {
                    if (strtolower($u['email']) === strtolower($email)) {
                        $existingUser = $u;
                        break;
                    }
                }
            }

            if ($existingUser) {
                $userId = $existingUser['id'];
                // If user doesn't have a full_name in profile, update it
                if (!empty($fullName)) {
                    $adminClient->from('profiles')->eq('id', $userId)->update(['full_name' => $fullName]);
                }
            } else {
                // Auto-provision user account with a temporary password
                $tempPassword = 'Member' . substr(bin2hex(random_bytes(3)), 0, 4) . '!';
                $displayName = !empty($fullName) ? $fullName : explode('@', $email)[0];
                $createRes = $adminClient->request('POST', '/auth/v1/admin/users', [
                    'email' => $email,
                    'password' => $tempPassword,
                    'email_confirm' => true,
                    'user_metadata' => [
                        'full_name' => $displayName,
                        'role' => $role
                    ]
                ]);

                if ($createRes['status'] >= 400 || empty($createRes['data']['id'])) {
                    Response::error($createRes['error'] ?? 'Failed to provision user for this email.', 400);
                }

                $userId = $createRes['data']['id'];

                // Ensure profile is saved
                $adminClient->from('profiles')->eq('id', $userId)->update([
                    'full_name' => $displayName,
                    'role' => $role
                ]);
            }
        }

        if (empty($userId)) {
            Response::error('Please provide a valid user email or user ID.', 422);
        }

        // Check if user is already a member of this workspace
        $existing = $adminClient->from('workspace_members')
            ->select('id')
            ->eq('workspace_id', $wsId)
            ->eq('user_id', $userId)
            ->first();

        if ($existing) {
            Response::error('This user is already a member of this workspace.', 400);
        }

        $res = $adminClient->from('workspace_members')->insert([
            'workspace_id' => $wsId,
            'user_id' => $userId,
            'role' => $role
        ]);

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to add member to workspace', 400);
        }

        $memberData = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];
        if ($tempPassword) {
            $memberData['temporary_password'] = $tempPassword;
            $memberData['is_new_user'] = true;
        }

        ActivityLogger::log($adminClient, $wsId, null, $request->user['id'], 'workspace_member_added', [
            'member_id' => $userId,
            'role' => $role
        ]);

        Response::success($memberData, 'Member added to workspace successfully', 201);
    }

    public function removeWorkspaceMember(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::error('Workspace ID required', 400);
        }

        $memberId = $request->getParam('id');
        $adminClient = SupabaseClient::admin();

        $member = $adminClient->from('workspace_members')
            ->select('id, user_id, role, workspace_id')
            ->eq('id', $memberId)
            ->eq('workspace_id', $wsId)
            ->first();

        if (!$member) {
            Response::error('Workspace member record not found', 404);
        }

        // Prevent owner removing themselves
        if ($member['user_id'] === $request->user['id']) {
            Response::error('You cannot remove yourself from your own workspace.', 400);
        }

        $res = $adminClient->from('workspace_members')->eq('id', $memberId)->delete();
        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to remove member from workspace', 400);
        }

        ActivityLogger::log($adminClient, $wsId, null, $request->user['id'], 'workspace_member_removed', [
            'removed_user_id' => $member['user_id']
        ]);

        Response::success(null, 'Member removed from workspace successfully');
    }
}
