<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;

class AdminController
{
    public function overview(Request $request): void
    {
        $adminClient = SupabaseClient::admin();

        $users = $adminClient->from('profiles')->select('id, full_name, role, created_at')->get();
        $workspaces = $adminClient->from('workspaces')->select('id, name, slug, created_at')->get();
        $projects = $adminClient->from('projects')->select('id, name, status, budget, created_at')->get();
        $deliverables = $adminClient->from('deliverables')->select('id, status, file_size')->get();
        $approvals = $adminClient->from('approval_requests')->select('id, status')->get();

        $totalStorageBytes = 0;
        foreach ($deliverables as $d) {
            $totalStorageBytes += (int)($d['file_size'] ?? 0);
        }

        Response::success([
            'stats' => [
                'total_users' => count($users),
                'total_workspaces' => count($workspaces),
                'total_projects' => count($projects),
                'total_deliverables' => count($deliverables),
                'total_approvals' => count($approvals),
                'storage_used_mb' => round($totalStorageBytes / (1024 * 1024), 2)
            ],
            'recent_workspaces' => array_slice($workspaces, 0, 5),
            'recent_users' => array_slice($users, 0, 5)
        ], 'Platform overview retrieved');
    }

    public function users(Request $request): void
    {
        $adminClient = SupabaseClient::admin();
        $users = $adminClient->from('profiles')
            ->select('id, full_name, avatar_url, role, phone, created_at')
            ->order('created_at', 'desc')
            ->get();

        Response::success($users, 'Platform users retrieved');
    }

    public function updateUserRole(Request $request): void
    {
        $userId = $request->getParam('id');
        $body = $request->getBody();

        if (empty($body['role']) || !in_array($body['role'], ['super_admin', 'workspace_owner', 'workspace_member', 'client'])) {
            Response::error('Invalid role specified', 400);
        }

        $adminClient = SupabaseClient::admin();
        $res = $adminClient->from('profiles')
            ->eq('id', $userId)
            ->update(['role' => $body['role']]);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to update user role', $res['status']);
        }

        Response::success(null, 'User role updated successfully');
    }

    public function workspaces(Request $request): void
    {
        $adminClient = SupabaseClient::admin();
        $workspaces = $adminClient->from('workspaces')
            ->select('*, projects(id), workspace_members(id)')
            ->order('created_at', 'desc')
            ->get();

        if (!is_array($workspaces)) {
            $workspaces = [];
        }

        $ownerIds = array_values(array_unique(array_filter(array_column($workspaces, 'owner_id'))));
        if (!empty($ownerIds)) {
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->in('id', $ownerIds)
                ->get();
            $profileMap = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $profileMap[$p['id']] = $p;
                }
            }
            foreach ($workspaces as &$ws) {
                $ws['profiles'] = $profileMap[$ws['owner_id']] ?? null;
            }
            unset($ws);
        }

        Response::success($workspaces, 'Platform workspaces retrieved');
    }
}
