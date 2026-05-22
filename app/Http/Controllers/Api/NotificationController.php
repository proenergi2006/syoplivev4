<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $limit = (int) ($request->limit ?? 5);

        $notifications = Notification::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'module' => $notification->module,
                    'reference_type' => $notification->reference_type,
                    'reference_id' => $notification->reference_id,
                    'reference_public_id' => $notification->reference_public_id,
                    'url' => $notification->url,
                    'is_read' => $notification->is_read,
                    'read_at' => $notification->read_at,
                    'created_at' => $notification->created_at,
                ];
            });

        $unreadCount = Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi berhasil dimuat.',
            'unread_count' => $unreadCount,
            'data' => $notifications,
        ]);
    }

    public function readAll(Request $request)
    {
        $user = $request->user();

        Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Semua notifikasi berhasil dibaca.',
        ]);
    }

    public function read(Request $request, $id)
    {
        $user = $request->user();

        $notification = Notification::where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        if (!$notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi berhasil dibaca.',
        ]);
    }

    public function deleteRead(Request $request)
    {
        $user = $request->user();

        Notification::where('user_id', $user->id)
            ->where('is_read', true)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi yang sudah dibaca berhasil dihapus.',
        ]);
    }
}
