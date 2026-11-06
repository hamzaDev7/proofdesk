<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Services\ActivityLogger;

class InvoiceController
{
    public function index(Request $request): void
    {
        $wsId = !empty($request->user['active_workspace_id']) ? $request->user['active_workspace_id'] : ($request->getHeader('x-workspace-id') ?? null);
        if (!$wsId && !empty($request->user['workspaces'])) {
            $wsId = $request->user['workspaces'][0]['workspace_id'] ?? null;
        }

        if (!$wsId) {
            Response::success([], 'No invoices found (Workspace ID pending)');
            return;
        }

        $userClient = SupabaseClient::forUser($request->token);
        $query = $userClient->from('invoices')
            ->select('*, clients(id, name, company, email), projects(id, name), invoice_items(*)')
            ->eq('workspace_id', $wsId);

        if ($status = $request->getQuery('status')) {
            $query->eq('status', $status);
        }

        if ($clientId = $request->getQuery('client_id')) {
            $query->eq('client_id', $clientId);
        }

        $query->order('created_at', 'desc');
        $invoices = $query->get();

        if (empty($invoices)) {
            $adminClient = SupabaseClient::admin();
            $adminQuery = $adminClient->from('invoices')
                ->select('*, clients(id, name, company, email), projects(id, name), invoice_items(*)')
                ->eq('workspace_id', $wsId);

            if ($status) $adminQuery->eq('status', $status);
            if ($clientId) $adminQuery->eq('client_id', $clientId);
            $adminQuery->order('created_at', 'desc');
            $invoices = $adminQuery->get();
        }

        if (!is_array($invoices)) {
            $invoices = [];
        }

        Response::success($invoices, 'Invoices retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $invoice = $userClient->from('invoices')
            ->select('*, workspaces(id, name), clients(id, name, company, email, phone, address), projects(id, name), invoice_items(*)')
            ->eq('id', $id)
            ->first();

        if (!$invoice) {
            Response::error('Invoice not found', 404);
        }

        Response::success($invoice, 'Invoice retrieved');
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
            'client_id' => 'required|uuid',
            'invoice_number' => 'required|min:2|max:50',
            'items' => 'required'
        ]);

        if (!$validation['valid']) {
            Response::error('Validation failed', 422, $validation['errors']);
        }

        $items = $body['items'];
        if (!is_array($items) || empty($items)) {
            Response::error('At least one invoice item is required.', 400);
        }

        // Calculate totals
        $subtotal = 0.00;
        $processedItems = [];

        foreach ($items as $item) {
            $desc = trim($item['description'] ?? 'Service / Asset');
            $qty = max(0.01, (float)($item['quantity'] ?? 1));
            $unitPrice = max(0.0, (float)($item['unit_price'] ?? 0));
            $itemTotal = round($qty * $unitPrice, 2);
            $subtotal += $itemTotal;

            $processedItems[] = [
                'description' => $desc,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total' => $itemTotal
            ];
        }

        $taxRate = max(0.0, (float)($body['tax_rate'] ?? 0.0)); // e.g. 0.08 for 8%
        $tax = round($subtotal * $taxRate, 2);
        if (isset($body['tax_amount'])) {
            $tax = (float)$body['tax_amount'];
        }
        $total = round($subtotal + $tax, 2);

        $userClient = SupabaseClient::forUser($request->token);

        $invoiceData = [
            'workspace_id' => $wsId,
            'client_id' => $body['client_id'],
            'project_id' => !empty($body['project_id']) ? $body['project_id'] : null,
            'invoice_number' => trim($body['invoice_number']),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'status' => $body['status'] ?? 'draft',
            'due_date' => !empty($body['due_date']) ? $body['due_date'] : null,
            'notes' => trim($body['notes'] ?? '')
        ];

        $res = $userClient->from('invoices')->insert($invoiceData);

        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('invoices')->insert($invoiceData);
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to create invoice', $res['status']);
        }

        $invoice = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];

        // Insert items
        foreach ($processedItems as &$pItem) {
            $pItem['invoice_id'] = $invoice['id'];
            $userClient->from('invoice_items')->insert($pItem, false);
        }
        $invoice['invoice_items'] = $processedItems;

        ActivityLogger::log($userClient, $wsId, $invoiceData['project_id'], $request->user['id'], 'invoice_created', [
            'invoice_number' => $invoice['invoice_number'],
            'total' => $total
        ]);

        Response::success($invoice, 'Invoice created successfully', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $body = $request->getBody();
        $userClient = SupabaseClient::forUser($request->token);

        $allowed = ['status', 'due_date', 'notes'];
        $updateData = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $updateData[$f] = $body[$f];
            }
        }

        if (empty($updateData)) {
            Response::error('No update parameters provided', 400);
        }

        $res = $userClient->from('invoices')
            ->eq('id', $id)
            ->update($updateData);

        if ($res['status'] >= 400 || empty($res['data'])) {
            $adminClient = SupabaseClient::admin();
            $adminRes = $adminClient->from('invoices')->eq('id', $id)->update($updateData);
            if ($adminRes['status'] < 400 && !empty($adminRes['data'])) {
                $res = $adminRes;
            }
        }

        if ($res['status'] >= 400 || empty($res['data'])) {
            Response::error($res['error'] ?? 'Failed to update invoice', $res['status']);
        }

        $updated = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];
        Response::success($updated, 'Invoice updated successfully');
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('invoices')->eq('id', $id)->delete();
        if ($res['status'] >= 400) {
            $adminClient = SupabaseClient::admin();
            $res = $adminClient->from('invoices')->eq('id', $id)->delete();
        }

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to delete invoice', $res['status']);
        }

        Response::success(null, 'Invoice deleted successfully');
    }
}
