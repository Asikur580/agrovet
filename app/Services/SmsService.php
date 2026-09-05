<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\SmsHistory;

class SmsService
{
    protected $apiUrl;
    protected $username;
    protected $apiKey;
    protected $senderId;

    public function __construct()
    {
        $this->apiUrl = config('services.sms.api_url');
        $this->username = config('services.sms.username');
        $this->apiKey = config('services.sms.api_key');
        $this->senderId = config('services.sms.sender_id');
    }

    /**
     * Send SMS to a specific number using MiMSMS.
     *
     * @param string $to
     * @param string $message
     * @param int|null $customerId
     * @return array
     */
    public function sendSms($to, $message, $customerId = null)
    {
        if (empty($this->apiUrl) || empty($this->apiKey) || empty($this->username)) {
            Log::error("SMS Gateway credentials not set.");

            // Log failed SMS
            $this->logSmsHistory($customerId, $to, $message, 'failed');

            return ['status' => false, 'message' => 'SMS Gateway credentials not set.'];
        }

        try {
            $formattedNumber = $this->formatPhoneNumber($to);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->apiUrl, [
                'UserName' => $this->username,
                'Apikey' => $this->apiKey,
                'MobileNumber' => $formattedNumber,
                'CampaignId' => 'null',
                'SenderName' => $this->senderId,
                'TransactionType' => 'T',
                'Message' => $message,
            ]);

            $result = $response->json();

            if (isset($result['statusCode']) && $result['statusCode'] == "200") {
                Log::info("SMS sent to $formattedNumber. Transaction ID: " . ($result['trxnId'] ?? 'N/A'));

                // Log successful SMS
                $this->logSmsHistory($customerId, $formattedNumber, $message, 'sent');

                return ['status' => true, 'response' => $result];
            }

            Log::error("Failed to send SMS to $formattedNumber. Response: ", (array)$result);

            // Log failed SMS
            $this->logSmsHistory($customerId, $formattedNumber, $message, 'failed');

            return ['status' => false, 'message' => 'SMS API request failed.'];
        } catch (\Exception $e) {
            Log::error("SMS Sending Error: " . $e->getMessage());

            // Log failed SMS
            $this->logSmsHistory($customerId, $to, $message, 'failed');

            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Log SMS to sms_histories table.
     *
     * @param int|null $customerId
     * @param string $phone
     * @param string $message
     * @param string $status
     */
    protected function logSmsHistory($customerId, $phone, $message, $status)
    {
        try {
            SmsHistory::create([
                'customer_id' => $customerId,
                'phone' => $phone,
                'message' => $message,
                'status' => $status,
                'sent_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to log SMS history: " . $e->getMessage());
        }
    }

    /**
     * Format phone number to standard BD format if necessary.
     *
     * @param string $phone
     * @return string
     */
    protected function formatPhoneNumber($phone)
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Ensure it starts with 880 (common for BD gateways)
        if (substr($phone, 0, 2) !== '88') {
            $phone = '88' . $phone;
        }

        return $phone;
    }

    /**
     * Check SMS balance from MiMSMS API.
     *
     * @return array
     */
    public function checkBalance()
    {
        try {
            $baseUrl = rtrim(str_replace('/api/V2/SendSMS', '', $this->apiUrl), '/');
            $balanceUrl = $baseUrl . '/api/V2/BalanceCheck';

            $response = Http::get($balanceUrl, [
                'userName' => $this->username,
                'apiKey' => $this->apiKey,
            ]);

            $result = $response->json();

            return [
                'status' => true,
                'data' => $result,
            ];
        } catch (\Exception $e) {
            Log::error("SMS Balance Check Error: " . $e->getMessage());
            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}

