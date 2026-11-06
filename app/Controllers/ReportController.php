<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;

class ReportController
{
    public function dashboard(Request $request): void
    {
        $wsId = $request->getHeader('x-workspace-id') ?? ($request->user['active_workspace_id'] ?? null);
        if (!$wsId) {
            Response::success([
                'metrics' => [
                    'total_projects' => 0,
                    'active_projects' => 0,
                    'completed_projects' => 0,
                    'pending_approvals' => 0,
                    'total_invoiced' => 0,
                    'total_paid' => 0,
                    'total_outstanding' => 0
                ],
                'projects_breakdown' => ['planning' => 0, 'in_progress' => 0, 'in_review' => 0, 'completed' => 0],
                'tasks_breakdown' => ['todo' => 0, 'in_progress' => 0, 'in_review' => 0, 'done' => 0, 'total' => 0],
                'recent_activity' => []
            ], 'Workspace initialized with zero metrics');
            return;
        }

        $userClient = SupabaseClient::forUser($request->token);

        // 1. Projects statistics
        $projects = $userClient->from('projects')
            ->select('id, name, status, priority, budget, deadline')
            ->eq('workspace_id', $wsId)
            ->get();

        $totalProjects = count($projects);
        $activeProjects = 0;
        $completedProjects = 0;
        $planningProjects = 0;
        $inReviewProjects = 0;

        foreach ($projects as $p) {
            match ($p['status'] ?? '') {
                'in_progress' => $activeProjects++,
                'completed' => $completedProjects++,
                'planning' => $planningProjects++,
                'in_review' => $inReviewProjects++,
                default => null
            };
        }

        // 2. Pending Approvals
        $pendingApprovals = $userClient->from('approval_requests')
            ->select('id, status, deliverable_id, deliverables(id, title, version, project_id, projects(id, workspace_id, name))')
            ->eq('status', 'pending')
            ->get();

        // Filter approvals by workspace
        $wsPendingApprovals = array_filter($pendingApprovals, function ($a) use ($wsId) {
            return ($a['deliverables']['projects']['workspace_id'] ?? '') === $wsId;
        });

        // 3. Invoices metrics
        $invoices = $userClient->from('invoices')
            ->select('id, status, subtotal, tax, total')
            ->eq('workspace_id', $wsId)
            ->get();

        $totalInvoiced = 0.00;
        $totalPaid = 0.00;
        $totalOutstanding = 0.00;

        foreach ($invoices as $inv) {
            $amt = (float)($inv['total'] ?? 0);
            $totalInvoiced += $amt;
            if ($inv['status'] === 'paid') {
                $totalPaid += $amt;
            } elseif (in_array($inv['status'], ['sent', 'overdue', 'draft'])) {
                $totalOutstanding += $amt;
            }
        }

        // 4. Tasks distribution
        $tasks = $userClient->from('tasks')
            ->select('id, status, priority, projects(workspace_id)')
            ->get();

        $wsTasks = array_filter($tasks, function ($t) use ($wsId) {
            return ($t['projects']['workspace_id'] ?? '') === $wsId;
        });

        $taskCounts = [
            'todo' => 0,
            'in_progress' => 0,
            'in_review' => 0,
            'done' => 0,
            'total' => count($wsTasks)
        ];

        foreach ($wsTasks as $t) {
            $st = $t['status'] ?? 'todo';
            if (isset($taskCounts[$st])) {
                $taskCounts[$st]++;
            }
        }

        // 5. Recent Activity Logs
        $recentActivity = $userClient->from('activity_logs')
            ->select('*, projects(id, name)')
            ->eq('workspace_id', $wsId)
            ->order('created_at', 'desc')
            ->limit(10)
            ->get();

        if (empty($recentActivity)) {
            $adminClient = SupabaseClient::admin();
            $recentActivity = $adminClient->from('activity_logs')
                ->select('*, projects(id, name)')
                ->eq('workspace_id', $wsId)
                ->order('created_at', 'desc')
                ->limit(10)
                ->get();
        }

        if (!is_array($recentActivity)) {
            $recentActivity = [];
        }

        $userIds = array_values(array_unique(array_filter(array_column($recentActivity, 'user_id'))));
        if (!empty($userIds)) {
            $adminClient = SupabaseClient::admin();
            $profiles = $adminClient->from('profiles')
                ->select('id, full_name, avatar_url')
                ->in('id', $userIds)
                ->get();
            $profileMap = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $profileMap[$p['id']] = $p;
                }
            }
            foreach ($recentActivity as &$a) {
                $a['profiles'] = $profileMap[$a['user_id']] ?? null;
            }
            unset($a);
        }

        Response::success([
            'metrics' => [
                'total_projects' => $totalProjects,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'pending_approvals' => count($wsPendingApprovals),
                'total_invoiced' => round($totalInvoiced, 2),
                'total_paid' => round($totalPaid, 2),
                'total_outstanding' => round($totalOutstanding, 2)
            ],
            'projects_breakdown' => [
                'planning' => $planningProjects,
                'in_progress' => $activeProjects,
                'in_review' => $inReviewProjects,
                'completed' => $completedProjects
            ],
            'tasks_breakdown' => $taskCounts,
            'recent_activity' => $recentActivity
        ], 'Dashboard metrics retrieved');
    }
}
