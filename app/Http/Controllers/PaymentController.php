<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\SmsService;
use App\Services\InvoiceDueService;

class PaymentController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function index()
    {
        try {
            $payments = Payment::all();
            return response()->json([
                'status' => true,
                'message' => 'All payments retrieved successfully',
                'data' => $payments,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction(); // ট্রানজেকশন শুরু করা

            $validatedData = $request->validate([
                'employee_id' => 'nullable|exists:employees,id',
                'cust_id' => 'nullable|exists:customers,id',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'amount' => 'required|numeric',
                'payment_method' => 'required|in:cash,check',
                'payment_date' => 'required|string',
            ]);

            // পেমেন্ট তৈরি করা
            $payment = Payment::create($validatedData);

            // কাস্টমার পেমেন্ট হলে FIFO পদ্ধতিতে ইনভয়েসের due ও paid অ্যাডজাস্ট করা
            if (!empty($payment->cust_id)) {
                InvoiceDueService::syncCustomerInvoiceDues((int)$payment->cust_id);
            }

            DB::commit(); // সব ঠিক থাকলে ট্রানজেকশন কমিট করা

            // Customer SMS notification
            if ($payment->cust_id) {
                $customer = Customer::find($payment->cust_id);
                if ($customer && $customer->phone && $customer->sms_enabled) {
                    // Calculate current due
                    $custTotalPurchase = Invoice::where('cust_id', $customer->id)->sum('grand_total');
                    $custTotalPayment = Payment::where('cust_id', $customer->id)->sum('amount');
                    $currentDue = ($customer->old_due + $custTotalPurchase) - $custTotalPayment;

                    $message = "Dear Customer,\n{$customer->customer_name}\nYour payment of {$payment->amount} TK has been successfully received. Your current due: {$currentDue} TK. Thank you. - Radian Agrovet";
                    $this->smsService->sendSms($customer->phone, $message, $customer->id);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment created and due adjusted successfully',
                'data' => $payment,
            ]);
        } catch (Exception $e) {
            DB::rollBack(); // কোনো সমস্যা হলে ট্রানজেকশন রোলব্যাক করা
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function show($id)
    {
        try {
            $payment = Payment::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Payment retrieved successfully',
                'data' => $payment,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function custPaymentHistory($custId)
    {
        try {
            // Validate custId
            if (empty($custId)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer ID is required.',
                    'data' => null,
                ]);
            }

            // Check if customer exists
            $customerExists = Customer::where('id', $custId)->exists();
            if (!$customerExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer not found.',
                    'data' => null,
                ]);
            }

            // Retrieve payment history with customer and employee names
            $custPaymentHistory = Payment::select(
                'payments.id',
                'customers.customer_name as customer_name',
                'employees.name as employee_name',
                'payments.amount',
                'payments.payment_method',
                'payments.payment_date'
            )
                ->join('customers', 'payments.cust_id', '=', 'customers.id') // Join with customers table
                ->join('employees', 'payments.employee_id', '=', 'employees.id') // Join with employees table
                ->where('payments.cust_id', $custId)
                ->orderBy('payments.payment_date', 'desc')
                ->get();

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'Customer Payment History retrieved successfully.',
                'data' => $custPaymentHistory,
            ]);
        } catch (Exception $e) {
            // Catch and return error
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function supplierPaymentHistory($supplierId)
    {
        try {
            // Validate supplierId
            if (empty($supplierId)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier ID is required.',
                    'data' => null,
                ]);
            }

            // Check if supplier exists (assuming a Supplier model)
            $supplierExists = Supplier::where('id', $supplierId)->exists();
            if (!$supplierExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found.',
                    'data' => null,
                ]);
            }

            // Retrieve supplier payment history with supplier name
            $supplierPaymentHistory = Payment::select(
                'payments.id',
                'suppliers.proprietor_name as supplier_name',
                'payments.amount',
                'payments.payment_method',
                'payments.payment_date'
            )
                ->join('suppliers', 'payments.supplier_id', '=', 'suppliers.id') // Join with suppliers table
                ->where('payments.supplier_id', $supplierId)
                ->orderBy('payments.payment_date', 'desc')
                ->get();

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'Supplier Payment History retrieved successfully.',
                'data' => $supplierPaymentHistory,
            ]);
        } catch (Exception $e) {
            // Catch and return error
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'employee_id' => 'nullable|exists:employees,id',
                'cust_id' => 'nullable|exists:customers,id',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'amount' => 'required|numeric',
                'payment_method' => 'required|in:cash,check',
                'payment_date' => 'required|string',
            ]);

            $payment = Payment::findOrFail($id);
            $previousCustId = $payment->cust_id;
            $payment->update($validatedData);

            // Re-sync invoice dues for previous and current customer
            if ($previousCustId) {
                InvoiceDueService::syncCustomerInvoiceDues((int)$previousCustId);
            }
            if (!empty($payment->cust_id) && (int)$payment->cust_id !== (int)$previousCustId) {
                InvoiceDueService::syncCustomerInvoiceDues((int)$payment->cust_id);
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment updated successfully',
                'data' => $payment,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function destroy($id)
    {
        try {
            $payment = Payment::findOrFail($id);
            $custId = $payment->cust_id;
            $payment->delete();

            if ($custId) {
                InvoiceDueService::syncCustomerInvoiceDues((int)$custId);
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment deleted successfully',
                'data' => null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }
}
