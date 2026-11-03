<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        if ($request->has('unread') && ($request->query('unread') == '1' || $request->query('unread') === 'true')) {
            $notifications = $query->whereNull('read_at')->latest()->get();
        } else {
            $notifications = $query->latest()->take(100)->get();
        }

        return response()->json([
            'status' => true,
            'data' => $notifications
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'status' => true,
            'message' => 'Notification marked as read'
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'status' => true,
            'message' => 'All notifications marked as read'
        ]);
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
