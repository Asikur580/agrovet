<?php

namespace App\Http\Controllers;

use App\Models\SmsHistory;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Exception;

class SmsHistoryController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Get all SMS history (paginated, filterable).
     */
    public function index(Request $request)
    {
        try {
            $query = SmsHistory::with('customer:id,customer_name,phone')
                ->orderBy('sent_at', 'desc');

            // Filter by customer
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by date range
            if ($request->has('from_date') && $request->from_date) {
                $query->whereDate('sent_at', '>=', $request->from_date);
            }
            if ($request->has('to_date') && $request->to_date) {
                $query->whereDate('sent_at', '<=', $request->to_date);
            }

            $smsHistories = $query->paginate($request->per_page ?? 20);

            return response()->json([
                'status' => true,
                'message' => 'SMS history fetched successfully.',
                'data' => $smsHistories,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch SMS history: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get SMS summary (total count, sent count, failed count).
     */
    public function summary()
    {
        try {
            $totalSms = SmsHistory::count();
            $sentCount = SmsHistory::where('status', 'sent')->count();
            $failedCount = SmsHistory::where('status', 'failed')->count();

            return response()->json([
                'status' => true,
                'message' => 'SMS summary fetched successfully.',
                'data' => [
                    'total_sms' => $totalSms,
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch SMS summary: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check SMS balance from the gateway.
     */
    public function balance()
    {
        try {
            $result = $this->smsService->checkBalance();

            return response()->json([
                'status' => true,
                'message' => 'SMS balance fetched successfully.',
                'data' => $result['data'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch SMS balance: ' . $e->getMessage(),
            ], 500);
        }
    }
}

