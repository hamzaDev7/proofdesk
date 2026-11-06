<?php

namespace ProofDesk\Controllers;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\SupabaseClient;

class NotificationController
{
    public function index(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $notifications = $userClient->from('notifications')
            ->select('*')
            ->eq('user_id', $request->user['id'])
            ->order('created_at', 'desc')
            ->limit(30)
            ->get();

        $unreadCount = 0;
        foreach ($notifications as $n) {
            if (!$n['is_read']) {
                $unreadCount++;
            }
        }

        Response::success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ], 'Notifications retrieved');
    }

    public function markAsRead(Request $request): void
    {
        $id = $request->getParam('id');
        $userClient = SupabaseClient::forUser($request->token);

        $res = $userClient->from('notifications')
            ->eq('id', $id)
            ->eq('user_id', $request->user['id'])
            ->update(['is_read' => true]);

        if ($res['status'] >= 400) {
            Response::error($res['error'] ?? 'Failed to update notification', $res['status']);
        }

        Response::success(null, 'Notification marked as read');
    }

    public function markAllAsRead(Request $request): void
    {
        $userClient = SupabaseClient::forUser($request->token);
        $res = $userClient->from('notifications')
            ->eq('user_id', $request->user['id'])
            ->eq('is_read', false)
            ->update(['is_read' => true]);

        Response::success(null, 'All notifications marked as read');
    }
}
