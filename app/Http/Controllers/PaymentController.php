<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
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

            // যদি কাস্টমার থাকে তাহলে ইনভয়েসের due অ্যাডজাস্ট করবো
            if ($validatedData['cust_id']) {
                $customerId = $validatedData['cust_id'];
                $paymentAmount = $validatedData['amount'];

                // ওই কাস্টমারের due থাকা ইনভয়েসগুলো খুঁজে বের করা
                $invoices = Invoice::where('cust_id', $customerId)
                    ->where('due', '>', 0)
                    ->orderBy('created_at', 'asc') // পুরনো ইনভয়েস আগে পেমেন্ট হবে
                    ->get();

                foreach ($invoices as $invoice) {
                    if ($paymentAmount <= 0) {
                        break; // পেমেন্ট শেষ হলে লুপ বন্ধ
                    }

                    if ($invoice->due <= $paymentAmount) {
                        // যদি পুরো ইনভয়েস পরিশোধ করা সম্ভব হয়
                        $paymentAmount -= $invoice->due;
                        $invoice->due = 0; // ইনভয়েস পুরো পরিশোধ হয়ে গেছে
                    } else {
                        // ইনভয়েস আংশিক পরিশোধ হবে
                        $invoice->due -= $paymentAmount;
                        $paymentAmount = 0;
                    }

                    $invoice->save(); // ইনভয়েস আপডেট সেভ করা
                }
                
            }

            DB::commit(); // সব ঠিক থাকলে ট্রানজেকশন কমিট করা

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
            $payment->update($validatedData);

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
            $payment->delete();

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
