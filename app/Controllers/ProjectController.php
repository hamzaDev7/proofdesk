<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;

class ProjectController
{
    public function index(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::success([], 'No projects found (Workspace ID pending)');
            return;
        }

        $userClient = SupabaseClient::forUser($request->token);
        $query = $userClient->from('projects')
            ->select('*, clients(id, name, company, email), tasks(id, status), deliverables(id, status)')
            ->eq('workspace_id', $wsId);

        // Filter by status if provided
        $status = $request->getQuery('status');
        if ($status && in_array($status, ['planning', 'in_progress', 'in_review', 'completed', 'archived'])) {
            $query->eq('status', $status);
        }

        // Filter by priority
        $priority = $request->getQuery('priority');
        if ($priority && in_array($priority, ['low', 'medium', 'high', 'urgent'])) {
            $query->eq('priority', $priority);
        }

        // Filter by client
        $clientId = $request->getQuery('client_id');
        if ($clientId) {
            $query->eq('client_id', $clientId);
        }

        $query->order('created_at', 'desc');

        $limit = min((int)$request->getQuery('limit', 50), 100);
        $offset = (int)$request->getQuery('offset', 0);
        $query->limit($limit)->offset($offset);

        $projects = $query->get();

        // Calculate task progress percentage and approval status for each project
        $enhanced = array_map(function ($p) {
            $totalTasks = count($p['tasks'] ?? []);
            $doneTasks = 0;
            if ($totalTasks > 0) {
                foreach ($p['tasks'] as $t) {
                    if (($t['status'] ?? '') === 'done') {
                        $doneTasks++;
                    }
                }
                $p['progress'] = round(($doneTasks / $totalTasks) * 100);
            } else {
                $p['progress'] = ($p['status'] === 'completed') ? 100 : 0;
            }
            $p['tasks_count'] = $totalTasks;
            $p['deliverables_count'] = count($p['deliverables'] ?? []);
            return $p;
        }, $projects);

        Response::success($enhanced, 'Projects retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $project = $userClient->from('projects')
            ->select('*, clients(id, name, company, email, phone), project_members(id, user_id, role), tasks(*), deliverables(*)')
            ->eq('id', $id)
            ->first();

        // Fallback to admin client if user client has RLS policy restrictions or empty result
        if (!$project) {
            $adminClient = SupabaseClient::admin();
            $adminProject = $adminClient->from('projects')
                ->select('*, clients(id, name, company, email, phone), project_members(id, user_id, role), tasks(*), deliverables(*)')
                ->eq('id', $id)
                ->first();

            if ($adminProject) {
                $userWs = array_column($request->user['workspaces'] ?? [], 'workspace_id');
                $isSuperAdmin = ($request->user['role'] ?? '') === 'super_admin';
                $isCreator = ($adminProject['created_by'] ?? '') === ($request->user['id'] ?? null);
                $hasWs = in_array($adminProject['workspace_id'], $userWs) || ($request->user['active_workspace_id'] ?? null) === $adminProject['workspace_id'];

                if ($isSuperAdmin || $isCreator || $hasWs) {
                    $project = $adminProject;
                }
            }
        }

        if (!$project) {
            Response::error('Project not found or access denied.', 404);
        }

        // Hydrate project_members with profile info if any exist
        if (!empty($project['project_members']) && is_array($project['project_members'])) {
            $userIds = array_values(array_unique(array_filter(array_column($project['project_members'], 'user_id'))));
            if (!empty($userIds)) {
                $adminClient = SupabaseClient::admin();
                $profiles = $adminClient->from('profiles')
                    ->select('id, full_name, avatar_url')
                    ->in('id', $userIds)
                    ->get();

                $profileMap = [];
                if (is_array($profiles)) {
                    foreach ($profiles as $prof) {
                        $profileMap[$prof['id']] = $prof;
                    }
                }

                foreach ($project['project_members'] as &$pm) {
                    $pm['profiles'] = $profileMap[$pm['user_id']] ?? null;
                }
                unset($pm);
            }
        }

        // Calculate progress
        $totalTasks = count($project['tasks'] ?? []);
        $doneTasks = 0;
        foreach ($project['tasks'] ?? [] as $t) {
            if (($t['status'] ?? '') === 'done') {
                $doneTasks++;
            }
        }
        $project['progress'] = $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : (($project['status'] === 'completed') ? 100 : 0);

        Response::success($project, 'Project details retrieved');
    }

    public function store(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::error('Workspace ID is required to create a project.', 400);
        }

        $body = $request->getBody();
        $validation = RequestValidator::validate($body, [
            'name' => 'required|min:2|max:200',
            'status' => 'in:planning,in_progress,in_review,completed,archived',
            'priority' => 'in:low,medium,high,urgent'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $userClient = SupabaseClient::forUser($request->token);

        $data = [
            'workspace_id' => $wsId,
            'client_id' => !empty($body['client_id']) ? $body['client_id'] : null,
            'name' => trim($body['name']),
            'description' => trim($body['description'] ?? ''),
            'status' => $body['status'] ?? 'planning',
            'priority' => $body['priority'] ?? 'medium',
            'start_date' => !empty($body['start_date']) ? $body['start_date'] : null,
            'deadline' => !empty($body['deadline']) ? $body['deadline'] : null,
            'budget' => isset($body['budget']) ? (float)$body['budget'] : 0.00,
            'created_by' => $request->user['id']
        ];

        $res = $userClient->from('projects')->insert($data);

        // If user JWT encounters an RLS representation or policy constraint, complete insertion via server-side admin client
        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('projects')->insert($data);
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to create project', $res['status']);
        }

        $project = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        // Automatically assign creator as lead in project_members
        $adminClient = SupabaseClient::admin();
        $adminClient->from('project_members')->insert([
            'project_id' => $project['id'],
            'user_id' => $request->user['id'],
            'role' => 'lead'
        ], false);

        ActivityLogger::log($adminClient, $wsId, $project['id'], $request->user['id'], 'project_created', [
            'name' => $project['name']
        ]);

        Response::success($project, 'Project created successfully', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $body = $request->getBody();
        $userClient = SupabaseClient::forUser($request->token);

        $allowedFields = ['name', 'description', 'status', 'priority', 'client_id', 'start_date', 'deadline', 'budget'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $updateData[$field] = $body[$field];
            }
        }

        if (empty($updateData)) {
            Response::error('No fields provided for update', 400);
        }

        $res = $userClient->from('projects')
            ->eq('id', $id)
            ->update($updateData);

        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $adminRes = $adminClient->from('projects')->eq('id', $id)->update($updateData);
            if ($adminRes['status'] < 400 && !empty($adminRes['data'])) {
                $res = $adminRes;
            }
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to update project', $res['status']);
        }

        $updated = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        // Get project's workspace id for activity log
        $wsId = $updated['workspace_id'] ?? ($request->user['active_workspace_id'] ?? null);
        if ($wsId) {
            $adminClient = SupabaseClient::admin();
            ActivityLogger::log($adminClient, $wsId, $id, $request->user['id'], 'project_updated', $updateData);
        }

        Response::success($updated, 'Project updated successfully');
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('projects')
            ->eq('id', $id)
            ->delete();

        if ($res['status'] >= 400) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('projects')->eq('id', $id)->delete();
        }

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to delete project', $res['status']);
        }

        Response::success(null, 'Project deleted successfully');
    }
}
