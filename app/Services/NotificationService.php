<?php

namespace ProofDesk\Services;

use ProofDesk\Core\SupabaseClient;

class NotificationService
{
    public static function notify(SupabaseClient $client, string $userId, string $title, string $message, string $link = ''): void
    {
        try {
            $client->from('notifications')->insert([
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'link' => $link,
                'is_read' => false
            ], false);
        } catch (\Throwable $e) {
            error_log('Failed to create notification: ' . $e->getMessage());
        }
    }
}
