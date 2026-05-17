<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Exception;

class SmsController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send custom SMS to one or multiple customers.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendCustomSms(Request $request)
    {
        try {
            $request->validate([
                'customer_ids' => 'nullable|array',
                'customer_ids.*' => 'exists:customers,id',
                'message' => 'required|string',
            ]);

            $message = $request->message;
            $customerIds = $request->customer_ids;

            $query = Customer::whereNotNull('phone');

            if (!empty($customerIds)) {
                $query->whereIn('id', $customerIds);
            }

            $customers = $query->get();

            if ($customers->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No customers found with valid phone numbers.',
                ], 404);
            }

            $successCount = 0;
            $failCount = 0;

            foreach ($customers as $customer) {
                $response = $this->smsService->sendSms($customer->phone, $message);
                if ($response['status']) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            return response()->json([
                'status' => true,
                'message' => "SMS sending process completed.",
                'data' => [
                    'total_customers' => $customers->count(),
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send SMS: ' . $e->getMessage(),
            ], 500);
        }
    }
}
