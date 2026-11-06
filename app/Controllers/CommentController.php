<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;

class CommentController
{
    public function index(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $query = $userClient->from('comments')->select('*');

        if ($projectId = $request->getQuery('project_id')) {
            $query->eq('project_id', $projectId);
        }

        if ($deliverableId = $request->getQuery('deliverable_id')) {
            $query->eq('deliverable_id', $deliverableId);
        }

        $query->order('created_at', 'asc');
        $comments = $query->get();

        if (empty($comments)) {
            $adminClient = SupabaseClient::admin();
            $adminQuery = $adminClient->from('comments')->select('*');
            if ($projectId) $adminQuery->eq('project_id', $projectId);
            if ($deliverableId) $adminQuery->eq('deliverable_id', $deliverableId);
            $adminQuery->order('created_at', 'asc');
            $comments = $adminQuery->get();
        }

        // Hydrate profiles for user_id
        $userIds = array_values(array_unique(array_filter(array_column($comments, 'user_id'))));
        if (!empty($userIds)) {
            $adminClient = SupabaseClient::admin();
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url, role')
                ->in('id', $userIds)
                ->get();
            $profileMap = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $profileMap[$p['id']] = $p;
                }
            }
            foreach ($comments as &$c) {
                $c['profiles'] = $profileMap[$c['user_id']] ?? [
                    'id' => $c['user_id'],
                    'full_name' => 'Team Member',
                    'avatar_url' => '',
                    'role' => 'workspace_member'
                ];
            }
            unset($c);
        }

        Response::success($comments, 'Comments retrieved');
    }

    public function store(Request $request): void
    {
        $body = $request->getBody();
        $validation = RequestValidator::validate($body, [
            'project_id' => 'required|uuid',
            'content' => 'required|min:1|max:5000'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $userClient = SupabaseClient::forUser($request->token);

        $data = [
            'project_id' => $body['project_id'],
            'deliverable_id' => !empty($body['deliverable_id']) ? $body['deliverable_id'] : null,
            'user_id' => $request->user['id'],
            'content' => trim($body['content'])
        ];

        $res = $userClient->from('comments')->insert($data);

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to post comment', $res['status']);
        }

        $comment = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];
        $comment['profiles'] = [
            'id' => $request->user['id'],
            'full_name' => $request->user['full_name'] ?? 'You',
            'avatar_url' => $request->user['avatar_url'] ?? '',
            'role' => $request->user['role'] ?? 'workspace_member'
        ];

        Response::success($comment, 'Comment posted successfully', 201);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('comments')
            ->eq('id', $id)
            ->delete();

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to delete comment', $res['status']);
        }

        Response::success(null, 'Comment deleted successfully');
    }
}
