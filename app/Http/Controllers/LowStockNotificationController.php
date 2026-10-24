<?php

namespace App\Http\Controllers;

use App\Services\LowStockNotificationService;
use Illuminate\Http\Request;

class LowStockNotificationController extends Controller
{
    public function markRead(Request $request, $id)
    {
        $ok = LowStockNotificationService::markRead((int) $id, \Auth::user());
        if (! $ok) {
            return response()->json(['error' => __('Notification not found.')], 404);
        }

        return response()->json([
            'success' => true,
            'unread_count' => LowStockNotificationService::unreadCountForUser(\Auth::user()),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $count = LowStockNotificationService::markAllRead(\Auth::user());

        return response()->json([
            'success' => true,
            'marked' => $count,
            'unread_count' => 0,
        ]);
    }
}
