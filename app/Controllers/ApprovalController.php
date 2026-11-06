<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;
use ProofDesk\Services\NotificationService;

class ApprovalController
{
    public function index(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $query = $userClient->from('approval_requests')
            ->select('*, deliverables(*, projects(id, name, workspace_id, client_id)), clients(id, name, company), approval_events(*)');

        if ($status = $request->getQuery('status')) {
            $query->eq('status', $status);
        }

        $query->order('created_at', 'desc');
        $approvals = $query->get();

        if (empty($approvals)) {
            $adminClient = SupabaseClient::admin();
            $adminQuery = $adminClient->from('approval_requests')
                ->select('*, deliverables(*, projects(id, name, workspace_id, client_id)), clients(id, name, company), approval_events(*)');

            if ($status) {
                $adminQuery->eq('status', $status);
            }
            $adminQuery->order('created_at', 'desc');
            $allApprovals = $adminQuery->get();

            // Filter approvals for user's authorized workspaces
            $userWs = array_column($request->user['workspaces'] ?? [], 'workspace_id');
            if (!empty($request->user['active_workspace_id'])) {
                $userWs[] = $request->user['active_workspace_id'];
            }
            $userWs = array_unique(array_filter($userWs));
            $isSuperAdmin = ($request->user['role'] ?? '') === 'super_admin';

            $approvals = array_values(array_filter($allApprovals, function ($a) use ($userWs, $isSuperAdmin) {
                if ($isSuperAdmin) return true;
                $wsId = $a['deliverables']['projects']['workspace_id'] ?? null;
                return in_array($wsId, $userWs);
            }));
        }

        // Hydrate approval_events actors
        $adminClient = SupabaseClient::admin();
        $actorIds = [];
        foreach ($approvals as $a) {
            foreach ($a['approval_events'] ?? [] as $ev) {
                if (!empty($ev['actor_id'])) {
                    $actorIds[] = $ev['actor_id'];
                }
            }
        }
        $actorIds = array_values(array_unique($actorIds));
        if (!empty($actorIds)) {
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->in('id', $actorIds)
                ->get();
            $actorMap = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $actorMap[$p['id']] = $p;
                }
            }
            foreach ($approvals as &$a) {
                if (!empty($a['approval_events'])) {
                    foreach ($a['approval_events'] as &$ev) {
                        $ev['profiles'] = $actorMap[$ev['actor_id']] ?? null;
                    }
                    unset($ev);
                }
            }
            unset($a);
        }

        Response::success($approvals, 'Approval requests retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $approval = $userClient->from('approval_requests')
            ->select('*, deliverables(*, projects(id, name, workspace_id, client_id, clients(id, name, company))), clients(id, name, company, email), approval_events(*)')
            ->eq('id', $id)
            ->first();

        if (!$approval) {
            $adminClient = SupabaseClient::admin();
            $approval = $adminClient->from('approval_requests')
                ->select('*, deliverables(*, projects(id, name, workspace_id, client_id, clients(id, name, company))), clients(id, name, company, email), approval_events(*)')
                ->eq('id', $id)
                ->first();
        }

        if (!$approval) {
            Response::error('Approval request not found', 404);
        }

        $adminClient = SupabaseClient::admin();

        // Hydrate uploader profile
        $uploaderId = $approval['deliverables']['uploaded_by'] ?? null;
        if ($uploaderId) {
            $uploaderProfile = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->eq('id', $uploaderId)
                ->first();
            $approval['deliverables']['profiles'] = $uploaderProfile ?? [
                'id' => $uploaderId,
                'full_name' => 'Team Member',
                'avatar_url' => ''
            ];
        }

        // Hydrate approval_events actors
        if (!empty($approval['approval_events'])) {
            $actorIds = array_values(array_unique(array_filter(array_column($approval['approval_events'], 'actor_id'))));
            if (!empty($actorIds)) {
                $profiles = $adminClient->from('profiles')
                    ->select('id, full_name, avatar_url')
                    ->in('id', $actorIds)
                    ->get();
                $actorMap = [];
                if (is_array($profiles)) {
                    foreach ($profiles as $p) {
                        $actorMap[$p['id']] = $p;
                    }
                }
                foreach ($approval['approval_events'] as &$ev) {
                    $ev['profiles'] = $actorMap[$ev['actor_id']] ?? null;
                }
                unset($ev);
            }
        }

        // Generate signed URL for deliverable preview
        if (!empty($approval['deliverables']['file_path'])) {
            $downloadUrl = $userClient->createSignedUrl(
                'deliverables',
                $approval['deliverables']['file_path'],
                3600
            );
            if (!$downloadUrl) {
                $downloadUrl = $adminClient->createSignedUrl(
                    'deliverables',
                    $approval['deliverables']['file_path'],
                    3600
                );
            }
            $approval['deliverables']['download_url'] = $downloadUrl;
        }

        Response::success($approval, 'Approval details retrieved');
    }

    public function review(Request $request): void
    {
        $id = $request->getParam('id');
        $body = $request->getBody();

        $validation = RequestValidator::validate($body, [
            'action' => 'required|in:approved,revision_requested,rejected',
            'feedback' => 'max:2000'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $action = $body['action'];
        $feedback = trim($body['feedback'] ?? '');
        $userClient = SupabaseClient::forUser($request->token);

        // Fetch current approval request
        $approval = $userClient->from('approval_requests')
            ->select('*, deliverables(id, project_id, title, version, uploaded_by, projects(id, workspace_id, name))')
            ->eq('id', $id)
            ->first();

        if (!$approval) {
            Response::error('Approval request not found or unauthorized', 404);
        }

        $deliverable = $approval['deliverables'];
        $projectId = $deliverable['project_id'];
        $workspaceId = $deliverable['projects']['workspace_id'] ?? null;
        $uploaderId = $deliverable['uploaded_by'];

        // 1. Update approval request status and feedback
        $updateData = [
            'status' => $action,
            'feedback' => $feedback,
            'reviewed_at' => date('c')
        ];

        $userClient->from('approval_requests')
            ->eq('id', $id)
            ->update($updateData);

        // 2. Update deliverable status to match review decision
        $userClient->from('deliverables')
            ->eq('id', $deliverable['id'])
            ->update(['status' => $action]);

        // 3. Append immutable approval event to audit trail
        $eventRes = $userClient->from('approval_events')->insert([
            'approval_request_id' => $id,
            'actor_id' => $request->user['id'],
            'action' => $action,
            'comment' => $feedback ?: ('Marked as ' . str_replace('_', ' ', $action))
        ]);

        // 4. Log workspace activity
        if ($workspaceId) {
            ActivityLogger::log($userClient, $workspaceId, $projectId, $request->user['id'], 'approval_' . $action, [
                'deliverable_id' => $deliverable['id'],
                'deliverable_title' => $deliverable['title'],
                'version' => $deliverable['version'],
                'feedback' => $feedback
            ]);
        }

        // 5. Notify the member who uploaded the deliverable
        $actionFriendly = match ($action) {
            'approved' => 'Approved',
            'revision_requested' => 'Revisions Requested',
            'rejected' => 'Rejected',
            default => $action
        };

        NotificationService::notify(
            $userClient,
            $uploaderId,
            'Deliverable ' . $actionFriendly,
            sprintf('Deliverable "%s" (v%d) was %s by reviewer.', $deliverable['title'], $deliverable['version'], strtolower($actionFriendly)),
            'approvals.html?approval_id=' . $id
        );

        Response::success([
            'approval_id' => $id,
            'status' => $action,
            'feedback' => $feedback,
            'reviewed_at' => date('c')
        ], 'Review submitted successfully');
    }
}
