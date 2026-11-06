<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;
use ProofDesk\Services\NotificationService;

class TaskController
{
    public function index(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $query = $userClient->from('tasks')
            ->select('*, projects(id, name, workspace_id)');

        if ($projectId = $request->getQuery('project_id')) {
            $query->eq('project_id', $projectId);
        }

        if ($status = $request->getQuery('status')) {
            $query->eq('status', $status);
        }

        if ($priority = $request->getQuery('priority')) {
            $query->eq('priority', $priority);
        }

        if ($assignedTo = $request->getQuery('assigned_to')) {
            $query->eq('assigned_to', $assignedTo);
        }

        $query->order('due_date', 'asc');
        $tasks = $query->get();

        if (empty($tasks)) {
            $adminClient = SupabaseClient::admin();
            $adminQuery = $adminClient->from('tasks')->select('*, projects(id, name, workspace_id)');
            if ($projectId) $adminQuery->eq('project_id', $projectId);
            if ($status) $adminQuery->eq('status', $status);
            if ($priority) $adminQuery->eq('priority', $priority);
            if ($assignedTo) $adminQuery->eq('assigned_to', $assignedTo);
            $adminQuery->order('due_date', 'asc');
            $allTasks = $adminQuery->get();

            $userWs = array_column($request->user['workspaces'] ?? [], 'workspace_id');
            if (!empty($request->user['active_workspace_id'])) {
                $userWs[] = $request->user['active_workspace_id'];
            }
            $userWs = array_unique(array_filter($userWs));
            $isSuperAdmin = ($request->user['role'] ?? '') === 'super_admin';

            $tasks = array_values(array_filter($allTasks, function ($t) use ($userWs, $isSuperAdmin) {
                if ($isSuperAdmin) return true;
                $wsId = $t['projects']['workspace_id'] ?? null;
                return in_array($wsId, $userWs);
            }));
        }

        // Hydrate assigned_to profiles
        $assignedIds = array_values(array_unique(array_filter(array_column($tasks, 'assigned_to'))));
        if (!empty($assignedIds)) {
            $adminClient = SupabaseClient::admin();
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->in('id', $assignedIds)
                ->get();
            $profileMap = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $profileMap[$p['id']] = $p;
                }
            }
            foreach ($tasks as &$t) {
                $t['profiles'] = $profileMap[$t['assigned_to']] ?? null;
            }
            unset($t);
        }

        Response::success($tasks, 'Tasks retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $task = $userClient->from('tasks')
            ->select('*, projects(id, name, workspace_id)')
            ->eq('id', $id)
            ->first();

        if (!$task) {
            $adminClient = SupabaseClient::admin();
            $task = $adminClient->from('tasks')
                ->select('*, projects(id, name, workspace_id)')
                ->eq('id', $id)
                ->first();
        }

        if (!$task) {
            Response::error('Task not found', 404);
        }

        if (!empty($task['assigned_to'])) {
            $adminClient = SupabaseClient::admin();
            $task['profiles'] = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->eq('id', $task['assigned_to'])
                ->first();
        }

        Response::success($task, 'Task retrieved');
    }

    public function store(Request $request): void
    {
        $body = $request->getBody();
        $validation = RequestValidator::validate($body, [
            'project_id' => 'required|uuid',
            'title' => 'required|min:2|max:255',
            'status' => 'in:todo,in_progress,in_review,done',
            'priority' => 'in:low,medium,high,urgent'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $userClient = SupabaseClient::forUser($request->token);

        $data = [
            'project_id' => $body['project_id'],
            'title' => trim($body['title']),
            'description' => trim($body['description'] ?? ''),
            'assigned_to' => !empty($body['assigned_to']) ? $body['assigned_to'] : null,
            'status' => $body['status'] ?? 'todo',
            'priority' => $body['priority'] ?? 'medium',
            'due_date' => !empty($body['due_date']) ? $body['due_date'] : null
        ];

        $res = $userClient->from('tasks')->insert($data);

        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('tasks')->insert($data);
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to create task', $res['status']);
        }

        $task = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        // Notify assigned member if applicable
        if (!empty($task['assigned_to']) && $task['assigned_to'] !== $request->user['id']) {
            NotificationService::notify(
                $userClient,
                $task['assigned_to'],
                'New Task Assigned',
                'You have been assigned to task: ' . $task['title'],
                'tasks.html?task_id=' . $task['id']
            );
        }

        Response::success($task, 'Task created successfully', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $body = $request->getBody();
        $userClient = SupabaseClient::forUser($request->token);

        $allowedFields = ['title', 'description', 'assigned_to', 'status', 'priority', 'due_date'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $updateData[$field] = $body[$field];
            }
        }

        if (empty($updateData)) {
            Response::error('No update parameters provided', 400);
        }

        $res = $userClient->from('tasks')
            ->eq('id', $id)
            ->update($updateData);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to update task', $res['status']);
        }

        $updated = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        Response::success($updated, 'Task updated successfully');
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('tasks')->eq('id', $id)->delete();
        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to delete task', $res['status']);
        }

        Response::success(null, 'Task deleted successfully');
    }
}
