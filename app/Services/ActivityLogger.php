<?php

namespace ProofDesk\Services;

use ProofDesk\Core\SupabaseClient;

class ActivityLogger
{
    public static function log(SupabaseClient $client, string $workspaceId, ?string $projectId, ?string $userId, string $action, array $metadata = []): void
    {
        try {
            $client->from('activity_logs')->insert([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'user_id' => $userId,
                'action' => $action,
                'metadata' => $metadata
            ], false);
        } catch (\Throwable $e) {
            error_log('Failed to write activity log: ' . $e->getMessage());
        }
    }
}
