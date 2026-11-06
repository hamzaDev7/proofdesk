<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;

class ClientController
{
    public function index(Request $request): void
    {
        $wsId = $request->getHeader('x-workspace-id') ?? ($request->user['active_workspace_id'] ?? null);
        if (!$wsId) {
            Response::success([], 'No clients found (Workspace ID pending)');
            return;
        }

        $userClient = SupabaseClient::forUser($request->token);
        $clients = $userClient->from('clients')
            ->select('*, projects(id, name, status, budget)')
            ->eq('workspace_id', $wsId)
            ->order('created_at', 'desc')
            ->get();

        Response::success($clients, 'Clients retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $client = $userClient->from('clients')
            ->select('*, projects(*), invoices(*)')
            ->eq('id', $id)
            ->first();

        if (!$client) {
            Response::error('Client not found', 404);
        }

        Response::success($client, 'Client details retrieved');
    }

    public function store(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::error('Workspace ID is required.', 400);
        }

        $body = $request->getBody();
        $validation = RequestValidator::validate($body, [
            'name' => 'required|min:2|max:150',
            'email' => 'required|email'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $userClient = SupabaseClient::forUser($request->token);

        $data = [
            'workspace_id' => $wsId,
            'name' => trim($body['name']),
            'email' => trim($body['email']),
            'company' => trim($body['company'] ?? ''),
            'phone' => trim($body['phone'] ?? ''),
            'address' => trim($body['address'] ?? ''),
            'user_id' => !empty($body['user_id']) ? $body['user_id'] : null
        ];

        $res = $userClient->from('clients')->insert($data);

        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('clients')->insert($data);
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to create client', $res['status']);
        }

        $client = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];
        Response::success($client, 'Client created successfully', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $body = $request->getBody();
        $userClient = SupabaseClient::forUser($request->token);

        $allowedFields = ['name', 'email', 'company', 'phone', 'address', 'user_id'];
        $updateData = [];

        foreach ($allowedFields as $f) {
            if (array_key_exists($f, $body)) {
                $updateData[$f] = $body[$f];
            }
        }

        if (empty($updateData)) {
            Response::error('No fields provided to update', 400);
        }

        $res = $userClient->from('clients')
            ->eq('id', $id)
            ->update($updateData);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to update client', $res['status']);
        }

        $updated = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];
        Response::success($updated, 'Client updated successfully');
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('clients')->eq('id', $id)->delete();
        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to delete client', $res['status']);
        }

        Response::success(null, 'Client deleted successfully');
    }
}
