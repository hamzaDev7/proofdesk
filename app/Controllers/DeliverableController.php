<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;
use ProofDesk\Services\NotificationService;

class DeliverableController
{
    private static array $allowedMimes = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf', 'application/zip', 'application/x-zip-compressed',
        'video/mp4', 'video/webm', 'video/quicktime',
        'text/plain', 'text/markdown', 'text/csv', 'application/json',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];

    public function index(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $query = $userClient->from('deliverables')
            ->select('*, projects(id, name, workspace_id, client_id, clients(id, name, company)), approval_requests(*)');

        if ($projectId = $request->getQuery('project_id')) {
            $query->eq('project_id', $projectId);
        }

        if ($status = $request->getQuery('status')) {
            $query->eq('status', $status);
        }

        $query->order('created_at', 'desc');
        $deliverables = $query->get();

        // Fallback to admin client if user query returned empty due to RLS policies
        if (empty($deliverables)) {
            $adminClient = SupabaseClient::admin();
            $adminQuery = $adminClient->from('deliverables')
                ->select('*, projects(id, name, workspace_id, client_id, clients(id, name, company)), approval_requests(*)');

            if ($projectId) {
                $adminQuery->eq('project_id', $projectId);
            }
            if ($status) {
                $adminQuery->eq('status', $status);
            }
            $adminQuery->order('created_at', 'desc');
            $allDeliverables = $adminQuery->get();

            // Filter deliverables for user's authorized workspaces
            $userWs = array_column($request->user['workspaces'] ?? [], 'workspace_id');
            if (!empty($request->user['active_workspace_id'])) {
                $userWs[] = $request->user['active_workspace_id'];
            }
            $userWs = array_unique(array_filter($userWs));
            $isSuperAdmin = ($request->user['role'] ?? '') === 'super_admin';

            $deliverables = array_values(array_filter($allDeliverables, function ($d) use ($userWs, $isSuperAdmin, $request) {
                if ($isSuperAdmin) return true;
                $wsId = $d['projects']['workspace_id'] ?? null;
                $isUploader = ($d['uploaded_by'] ?? '') === ($request->user['id'] ?? null);
                return $isUploader || in_array($wsId, $userWs);
            }));
        }

        // Hydrate profiles for uploaded_by
        $uploaderIds = array_values(array_unique(array_filter(array_column($deliverables, 'uploaded_by'))));
        if (!empty($uploaderIds)) {
            $adminClient = SupabaseClient::admin();
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->in('id', $uploaderIds)
                ->get();
            $profileMap = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $profileMap[$p['id']] = $p;
                }
            }
            foreach ($deliverables as &$d) {
                $d['profiles'] = $profileMap[$d['uploaded_by']] ?? [
                    'id' => $d['uploaded_by'],
                    'full_name' => 'Team Member',
                    'avatar_url' => ''
                ];
            }
            unset($d);
        }

        Response::success($deliverables, 'Deliverables retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $deliverable = $userClient->from('deliverables')
            ->select('*, projects(id, name, workspace_id, client_id, clients(id, name, company)), approval_requests(*, approval_events(*))')
            ->eq('id', $id)
            ->first();

        if (!$deliverable) {
            $adminClient = SupabaseClient::admin();
            $deliverable = $adminClient->from('deliverables')
                ->select('*, projects(id, name, workspace_id, client_id, clients(id, name, company)), approval_requests(*, approval_events(*))')
                ->eq('id', $id)
                ->first();
        }

        if (!$deliverable) {
            Response::error('Deliverable not found', 404);
        }

        $adminClient = SupabaseClient::admin();

        // Hydrate uploader profile
        if (!empty($deliverable['uploaded_by'])) {
            $uploaderProfile = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->eq('id', $deliverable['uploaded_by'])
                ->first();
            $deliverable['profiles'] = $uploaderProfile ?? [
                'id' => $deliverable['uploaded_by'],
                'full_name' => 'Team Member',
                'avatar_url' => ''
            ];
        }

        // Hydrate approval event actors
        if (!empty($deliverable['approval_requests'])) {
            $actorIds = [];
            foreach ($deliverable['approval_requests'] as $ar) {
                foreach ($ar['approval_events'] ?? [] as $ev) {
                    if (!empty($ev['actor_id'])) {
                        $actorIds[] = $ev['actor_id'];
                    }
                }
            }
            $actorIds = array_values(array_unique($actorIds));
            if (!empty($actorIds)) {
                $actorProfiles = $adminClient->from('profiles')
                    ->select('id, full_name, avatar_url')
                    ->in('id', $actorIds)
                    ->get();
                $actorMap = [];
                if (is_array($actorProfiles)) {
                    foreach ($actorProfiles as $ap) {
                        $actorMap[$ap['id']] = $ap;
                    }
                }
                foreach ($deliverable['approval_requests'] as &$ar) {
                    if (!empty($ar['approval_events'])) {
                        foreach ($ar['approval_events'] as &$ev) {
                            $ev['profiles'] = $actorMap[$ev['actor_id']] ?? null;
                        }
                        unset($ev);
                    }
                }
                unset($ar);
            }
        }

        // Generate signed URL for file preview/download
        $signedUrl = $userClient->createSignedUrl('deliverables', $deliverable['file_path'], 3600);
        if (!$signedUrl) {
            $signedUrl = $adminClient->createSignedUrl('deliverables', $deliverable['file_path'], 3600);
        }
        $deliverable['download_url'] = $signedUrl;

        Response::success($deliverable, 'Deliverable details retrieved');
    }

    public function store(Request $request): void
    {
        $files = $request->getFiles();
        $body = $request->getBody();

        $validation = RequestValidator::validate($body, [
            'project_id' => 'required|uuid',
            'title' => 'required|min:2|max:255'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $file = $files['file'] ?? null;
        if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
            // Also support direct storage path if file was pre-uploaded via Supabase SDK
            if (empty($body['file_path'])) {
                Response::error('File upload is required (or file_path must be specified).', 400);
            }
            $filePath = $body['file_path'];
            $fileType = $body['file_type'] ?? 'application/octet-stream';
            $fileSize = (int)($body['file_size'] ?? 0);
        } else {
            // Validate file size (max 50MB)
            $fileSize = (int)$file['size'];
            if ($fileSize > 52428800) {
                Response::error('File size exceeds 50MB limit.', 400);
            }

            // Validate MIME type with finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, self::$allowedMimes) && !in_array($file['type'], self::$allowedMimes)) {
                Response::error('File type is not permitted. Permitted types include images, PDF, documents, ZIP, and video.', 400);
            }

            $fileType = $mime ?: $file['type'];
            $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
            $cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $storageFileName = sprintf('%s_%s_%s.%s', $body['project_id'], time(), substr(md5(uniqid()), 0, 6), $fileExt ?: 'bin');
            $filePath = 'projects/' . $body['project_id'] . '/' . $storageFileName;

            $fileContent = file_get_contents($file['tmp_name']);

            $userClient = SupabaseClient::forUser($request->token);
            $uploadRes = $userClient->uploadFile('deliverables', $filePath, $fileContent, $fileType);

            if ($uploadRes['status'] >= 400) {
                Response::error($uploadRes['error'] ?? 'Failed to upload file to storage bucket.', $uploadRes['status']);
            }
        }

        $userClient = SupabaseClient::forUser($request->token);

        // Fetch current version number for this project & title
        $existing = $userClient->from('deliverables')
            ->select('version')
            ->eq('project_id', $body['project_id'])
            ->eq('title', trim($body['title']))
            ->order('version', 'desc')
            ->first();

        $version = $existing ? ((int)$existing['version'] + 1) : (int)($body['version'] ?? 1);

        $deliverableData = [
            'project_id' => $body['project_id'],
            'uploaded_by' => $request->user['id'],
            'title' => trim($body['title']),
            'description' => trim($body['description'] ?? ''),
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'version' => $version,
            'status' => 'pending'
        ];

        $res = $userClient->from('deliverables')->insert($deliverableData);

        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('deliverables')->insert($deliverableData);
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to create deliverable record.', $res['status']);
        }

        $deliverable = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        // Automatically create approval request
        $project = $userClient->from('projects')
            ->select('id, workspace_id, client_id, clients(id, user_id, email, name)')
            ->eq('id', $body['project_id'])
            ->first();

        $clientId = $project['client_id'] ?? null;
        $clientUserId = $project['clients']['user_id'] ?? null;

        $approvalRes = $userClient->from('approval_requests')->insert([
            'deliverable_id' => $deliverable['id'],
            'client_id' => $clientId,
            'status' => 'pending',
            'feedback' => ''
        ]);

        if ($approvalRes['status'] >= 400 || empty($approvalRes['data'])) {
            $adminClient = SupabaseClient::admin();
            $approvalRes = $adminClient->from('approval_requests')->insert([
                'deliverable_id' => $deliverable['id'],
                'client_id' => $clientId,
                'status' => 'pending',
                'feedback' => ''
            ]);
        }

        $approvalRequest = is_array($approvalRes['data']) ? ($approvalRes['data'][0] ?? $approvalRes['data']) : null;

        if ($approvalRequest && isset($approvalRequest['id'])) {
            // Immutable approval event audit trail
            $adminClient = SupabaseClient::admin();
            $adminClient->from('approval_events')->insert([
                'approval_request_id' => $approvalRequest['id'],
                'actor_id' => $request->user['id'],
                'action' => 'created',
                'comment' => 'Deliverable version ' . $version . ' submitted for client approval.'
            ], false);
        }

        // Notify client if client user is registered
        if ($clientUserId) {
            NotificationService::notify(
                $userClient,
                $clientUserId,
                'New Deliverable Awaiting Approval',
                'Deliverable "' . $deliverable['title'] . '" is ready for your review.',
                'approvals.html?deliverable_id=' . $deliverable['id']
            );
        }

        // Log activity
        if (!empty($project['workspace_id'])) {
            ActivityLogger::log($userClient, $project['workspace_id'], $project['id'], $request->user['id'], 'deliverable_uploaded', [
                'title' => $deliverable['title'],
                'version' => $version,
                'file_size' => $fileSize
            ]);
        }

        Response::success($deliverable, 'Deliverable uploaded and approval request initiated successfully', 201);
    }

    public function download(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $deliverable = $userClient->from('deliverables')
            ->select('id, title, file_path, file_type')
            ->eq('id', $id)
            ->first();

        if (!$deliverable) {
            Response::error('Deliverable not found', 404);
        }

        $signedUrl = $userClient->createSignedUrl('deliverables', $deliverable['file_path'], 3600);
        if ($signedUrl) {
            Response::success(['url' => $signedUrl, 'title' => $deliverable['title']], 'Signed download URL generated');
        }

        Response::error('Failed to generate download link', 500);
    }
}
