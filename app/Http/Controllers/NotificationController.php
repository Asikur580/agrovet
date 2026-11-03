<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $query = DB::table('notifications')->where('notifiable_id', $userId);

        if ($request->has('unread') && ($request->query('unread') == '1' || $request->query('unread') === 'true')) {
            $rawNotifications = $query->whereNull('read_at')->orderBy('created_at', 'desc')->take(50)->get();
        } else {
            $rawNotifications = $query->orderBy('created_at', 'desc')->take(50)->get();
        }

        // Format to match Laravel notification payload structure
        $notifications = $rawNotifications->map(function ($item) {
            return [
                'id' => $item->id,
                'type' => $item->type,
                'notifiable_type' => $item->notifiable_type,
                'notifiable_id' => $item->notifiable_id,
                'data' => is_string($item->data) ? json_decode($item->data, true) : $item->data,
                'read_at' => $item->read_at,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        $unreadCount = DB::table('notifications')
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'status' => true,
            'data' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        try {
            DB::table('notifications')
                ->where('id', $id)
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function markAllAsRead(Request $request)
    {
        try {
            $user = $request->user();
            if ($user) {
                // Direct bulk SQL update in 1 single fast query (<20ms)
                DB::table('notifications')
                    ->where('notifiable_id', $user->id)
                    ->whereNull('read_at')
                    ->update([
                        'read_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to mark all as read: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark unread notifications for a specific order as read.
     * If $userId is provided, only that user's notifications are marked.
     * Otherwise, marks for all notifiables who received notifications for this order.
     */
    public static function markOrderNotificationsAsRead($orderId, $userId = null)
    {
        if (!$orderId) {
            return;
        }

        try {
            $query = DB::table('notifications')
                ->whereNull('read_at')
                ->where(function ($q) use ($orderId) {
                    $q->where('data->order_id', $orderId)
                      ->orWhere('data', 'like', '%"order_id":' . $orderId . '%')
                      ->orWhere('data', 'like', '%"order_id":"' . $orderId . '"%');
                });

            if ($userId) {
                $query->where('notifiable_id', $userId);
            }

            $query->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silently catch so business logic is never blocked
        }
    }

    /**
     * Mark unread notifications for a specific invoice as read.
     */
    public static function markInvoiceNotificationsAsRead($invoiceId, $userId = null)
    {
        if (!$invoiceId) {
            return;
        }

        try {
            $query = DB::table('notifications')
                ->whereNull('read_at')
                ->where(function ($q) use ($invoiceId) {
                    $q->where('data->invoice_id', $invoiceId)
                      ->orWhere('data', 'like', '%"invoice_id":' . $invoiceId . '%')
                      ->orWhere('data', 'like', '%"invoice_id":"' . $invoiceId . '"%');
                });

            if ($userId) {
                $query->where('notifiable_id', $userId);
            }

            $query->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silently catch so business logic is never blocked
        }
    }

    /**
     * Endpoint for frontend to mark notifications read when viewing an order or invoice.
     */
    public function markAsReadByEntity(Request $request)
    {
        $userId = $request->user()->id;
        $orderId = $request->input('order_id');
        $invoiceId = $request->input('invoice_id');

        if ($orderId) {
            self::markOrderNotificationsAsRead($orderId, $userId);
        }

        if ($invoiceId) {
            self::markInvoiceNotificationsAsRead($invoiceId, $userId);
        }

        return response()->json([
            'status' => true,
            'message' => 'Notifications marked as read'
        ]);
    }
}
