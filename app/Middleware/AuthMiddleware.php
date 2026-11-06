<?php

namespace ProofDesk\Middleware;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;

class AuthMiddleware
{
    public function handle(Request $request): void
    {
        $token = $request->getBearerToken();

        if (empty($token)) {
            Response::error('Authentication required. Missing Bearer token.', 401, null, 'UNAUTHORIZED');
        }

        $supabase = SupabaseClient::forUser($token);
        if (!$supabase->isConfigured()) {
            Response::error('Backend database configuration missing. Please configure SUPABASE_URL and SUPABASE_ANON_KEY.', 500, null, 'CONFIG_ERROR');
        }

        $authUser = $supabase->getAuthUser($token);
        if (!$authUser || !isset($authUser['id'])) {
            Response::error('Invalid or expired authentication session.', 401, null, 'INVALID_TOKEN');
        }

        // Fetch user profile
        $profile = $supabase->from('profiles')
            ->eq('id', $authUser['id'])
            ->first();

        // Fetch user's workspaces
        $workspaces = $supabase->from('workspace_members')
            ->select('workspace_id, role, workspaces(id, name, slug, owner_id)')
            ->eq('user_id', $authUser['id'])
            ->get();

        // Self-heal: If user has no workspace membership yet, auto-provision or link one
        if (empty($workspaces)) {
            $adminClient = SupabaseClient::admin();
            $ownedWs = $adminClient->from('workspaces')->eq('owner_id', $authUser['id'])->first();

            if ($ownedWs) {
                $adminClient->from('workspace_members')->insert([
                    'workspace_id' => $ownedWs['id'],
                    'user_id' => $authUser['id'],
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
                $userName = $profile['full_name'] ?? ($authUser['user_metadata']['full_name'] ?? 'My');
                $wsName = trim($userName) . "'s Workspace";
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $wsName), '-')) . '-' . substr(md5(uniqid()), 0, 6);

                $newWsRes = $adminClient->from('workspaces')->insert([
                    'name' => $wsName,
                    'slug' => $slug,
                    'owner_id' => $authUser['id']
                ]);

                if (!empty($newWsRes['data'])) {
                    $newWs = is_array($newWsRes['data']) ? ($newWsRes['data'][0] ?? $newWsRes['data']) : $newWsRes['data'];
                    $adminClient->from('workspace_members')->insert([
                        'workspace_id' => $newWs['id'],
                        'user_id' => $authUser['id'],
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

        $activeWorkspaceId = $request->getHeader('x-workspace-id');
        $activeWorkspace = null;

        if ($activeWorkspaceId && !empty($workspaces)) {
            foreach ($workspaces as $wm) {
                if ($wm['workspace_id'] === $activeWorkspaceId) {
                    $activeWorkspace = $wm;
                    break;
                }
            }
        }

        if (!$activeWorkspace && !empty($workspaces)) {
            $activeWorkspace = $workspaces[0];
        }

        $request->token = $token;
        $request->user = [
            'id' => $authUser['id'],
            'email' => $authUser['email'] ?? '',
            'role' => $profile['role'] ?? 'workspace_member',
            'full_name' => $profile['full_name'] ?? ($authUser['user_metadata']['full_name'] ?? ''),
            'avatar_url' => $profile['avatar_url'] ?? '',
            'workspaces' => $workspaces,
            'active_workspace' => $activeWorkspace,
            'active_workspace_id' => $activeWorkspace['workspace_id'] ?? null,
            'active_workspace_role' => $activeWorkspace['role'] ?? ($profile['role'] ?? 'workspace_member'),
            'raw_auth' => $authUser
        ];
    }
}
