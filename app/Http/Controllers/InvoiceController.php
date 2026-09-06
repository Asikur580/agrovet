<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Relation;
use Illuminate\Http\Request;
use App\Models\InvoiceProduct;
use App\Mail\InvoiceCreatedMail;
use Illuminate\Support\Facades\DB;
use App\Notifications\InvoiceNotification;
use Illuminate\Support\Facades\Mail;
use App\Services\SmsService;

class InvoiceController extends Controller
{
    private function roundPointValue($value, int $min = 0): int
    {
        return max($min, (int) round((float) ($value ?? 0)));
    }

    private function normalizeProductQuantities(array $products): array
    {
        return collect($products)->map(function ($product) {
            $product['quantity'] = $this->roundPointValue($product['quantity'] ?? 0);
            $product['bonus_qty'] = $this->roundPointValue($product['bonus_qty'] ?? 0);
            $product['due_quantity'] = $this->roundPointValue($product['due_quantity'] ?? 0);

            return $product;
        })->toArray();
    }
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Calculate grand total with custom rounding (.50 rounds down, .51 rounds up)
     */
    private function calculateGrandTotal($totalPrice, $discount, $less)
    {
        $rawGrandTotal = $totalPrice - $discount - $less;

        // Custom rounding: x.50 -> x, x.51 -> x + 1
        $formattedTotal = number_format($rawGrandTotal, 2, '.', '');
        $parts = explode('.', $formattedTotal);
        $integerPart = (int)$parts[0];
        $decimalPart = (int)$parts[1];

        if ($decimalPart > 50) {
            return $integerPart + 1;
        }
        return $integerPart;
    }

    /**
     * Display a listing of the invoices.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $customerId = $request->customer_id;      // Customer Filter
            $filterEmployeeId = $request->employee_id; // Employee Filter

            $invoicesQuery = Invoice::with(['customer', 'employee', 'products.product'])->latest();

            // Role based filtering
            if ($user->employee->designation->slug == 'admin') {

                // Admin সব দেখতে পারে — no restriction

            } elseif ($user->employee->designation->slug == 'officer') {

                $invoicesQuery->where('employee_id', $user->employee->id);
            } elseif ($user->employee->designation->slug == 'manager') {

                $officerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');
                $invoicesQuery->whereIn('employee_id', $officerIds);
            } elseif ($user->employee->designation->slug == 'rsm') {

                $managerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');
                $officerIds = Relation::whereIn('relation_id', $managerIds)->pluck('employee_id');
                $allEmployeeIds = $managerIds->merge($officerIds);

                $invoicesQuery->whereIn('employee_id', $allEmployeeIds);
            } else {
                return response()->json([
                    'status' => true,
                    'message' => 'No invoices available.',
                    'data' => [],
                ]);
            }

            // 🔥 Apply Customer Filter
            if ($customerId) {
                $invoicesQuery->where('cust_id', $customerId);
            }

            // 🔥 Apply Employee Filter (Allowed only within permission)
            if ($filterEmployeeId) {
                $invoicesQuery->where('employee_id', $filterEmployeeId);
            }

            $invoices = $invoicesQuery->get();

            // Format
            $invoices = $invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'customer_name' => $invoice->customer->customer_name ?? 'N/A',
                    'employee_name' => $invoice->employee->name ?? 'N/A',
                    'employee_id' => $invoice->employee->employee_id ?? 'N/A',
                    'products' => $invoice->products->map(function ($item) {
                        return [
                            'product_name' => $item->product->name ?? 'N/A',
                            'pack_size' => $item->product->pack_size ?? 'N/A',
                            'quantity' => $item->quantity ?? 'N/A',
                            'unit_price' => $item->unit_price ?? 'N/A',
                            'bonus_qty' => $item->bonus_qty ?? 'N/A',
                            'due_quantity' => $item->due_quantity ?? 'N/A',
                            'price_type' => $item->price_type ?? 'N/A',
                        ];
                    }),
                    'invoice_date' => $invoice->sale_date,
                    'grand_total' => $invoice->grand_total,
                    'sale_type' => $invoice->sale_type,
                    'offer' => $invoice->offer,
                    'is_printed' => $invoice->is_printed,
                    'printed_at' => $invoice->printed_at,
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Invoices retrieved successfully',
                'data' => $invoices,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve invoices: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }



    public function salesByEmployee($id)
    {
        try {
            $invoices = Invoice::with(['customer', 'employee', 'products.product'])->where('employee_id', $id)->get();

            return response()->json([
                'status' => true,
                'message' => 'Sales by employee retrieved successfully',
                'data' => $invoices,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Display the specified invoice.
     */
    public function show($id)
    {
        try {
            $invoice = Invoice::with(['customer', 'employee', 'products.product'])->find($id);

            if (!$invoice) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invoice not found',
                    'data' => null,
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Invoice retrieved successfully',
                'data' => $invoice,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve invoice' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }


    /**
     * Store a newly created invoice in storage.
     */
    public function store(Request $request, $orderId = null)
    {
        DB::beginTransaction();
        try {
            // Validate the invoice data
            $validatedInvoice = $request->validate([
                'cust_id' => 'required|exists:customers,id',
                'total_item' => 'required|integer',
                'total_price' => 'required|numeric',
                'discount' => 'nullable|numeric',
                'less' => 'nullable|numeric',
                'grand_total' => 'required|numeric',
                'paid' => 'required|numeric',
                'due' => 'required|numeric',
                'sale_date' => 'required|string',
                'sale_type' => 'required|in:cash,credit',
                'offer' => 'nullable|string|max:255',
                'products' => 'required|array', // Products data is required
                'products.*.product_id' => 'required|exists:products,id', // Each product must exist
                'products.*.quantity' => 'required|numeric|min:0',
                'products.*.unit_price' => 'required|numeric|min:0',
                'products.*.bonus_qty' => 'nullable|numeric',
                'products.*.price_type' => 'required|in:tp,flat',
            ]);

            $validatedInvoice['products'] = $this->normalizeProductQuantities($validatedInvoice['products']);

            // Recalculate grand_total and due using custom rounding logic
            $calculatedGrandTotal = $this->calculateGrandTotal(
                $validatedInvoice['total_price'],
                $validatedInvoice['discount'] ?? 0,
                $validatedInvoice['less'] ?? 0
            );
            $validatedInvoice['grand_total'] = $calculatedGrandTotal;
            $validatedInvoice['due'] = $calculatedGrandTotal - $validatedInvoice['paid'];

            if ($validatedInvoice['paid'] > $validatedInvoice['grand_total']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Paid amount cannot be greater than the grand total.',
                ]);
            }

            // Add the employee ID from the authenticated user
            $validatedInvoice['employee_id'] = $request->user()->employee_id;

            // Check credit limit for the order (before creating the invoice)
            $employee = Employee::find($validatedInvoice['employee_id']);
            $employeeCreditLimit = $employee->credit_limit;

            $totalDue = Invoice::where('employee_id', $employee->id)->sum('due');
            $totalOrderAmount = Order::where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

            $credit_limit = $totalDue + $totalOrderAmount + $validatedInvoice['grand_total'];

            if ($validatedInvoice['sale_type'] == 'credit') {
                if ($credit_limit > $employeeCreditLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invoice exceeds employee credit limit.',
                        'data' => null
                    ], 422);
                }
            }


            // Customer Credit Limit check
            $customer = Customer::findOrFail($validatedInvoice['cust_id']);
            $customerCreditLimit = $customer->credit_limit;

            $custTotalPurchase = Invoice::where('cust_id', $customer->id)->sum('grand_total');
            $custTotalPayment = Payment::where('cust_id', $customer->id)->sum('amount');

            $custPendingOrderAmount = Order::where('cust_id', $customer->id)
                ->where('status', 'pending')
                ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

            $custCurrentDue = ($customer->old_due + $custTotalPurchase) - $custTotalPayment;
            $custCreditUsage = $custCurrentDue + $custPendingOrderAmount + $validatedInvoice['grand_total'];

            // Get latest invoice for the specific customer
            $latestInvoice = Invoice::where('cust_id', $validatedInvoice['cust_id'])
                ->latest('id')
                ->first();

            $customerPrefix = 'RAINVO-'; // no trailing zero here
            $startingNumber = 1; // default starting number
            $paddingLength = 2;  // will create RAINVO-001, RAINVO-002, etc.

            if ($latestInvoice) {
                // Extract numeric part safely
                $latestNumber = (int) preg_replace('/[^0-9]/', '', $latestInvoice->invoiceId);
                $startingNumber = $latestNumber + 1;
            }

            // Format with zero padding
            $customInvoiceId = $customerPrefix . str_pad($startingNumber, $paddingLength, '0', STR_PAD_LEFT);

            // Set the new invoice ID
            $validatedInvoice['invoiceId'] = $customInvoiceId;


            if ($orderId != null) {
                // Find the order or throw a ModelNotFoundException
                $order = Order::findOrFail($orderId);

                $custCreditUsage = $custCurrentDue + $custPendingOrderAmount;

                // Add the employee ID from the order table
                $validatedInvoice['employee_id'] = $order->employee_id;

                // Copy offer from the order if not explicitly set in the request
                if (empty($validatedInvoice['offer']) && !empty($order->offer)) {
                    $validatedInvoice['offer'] = $order->offer;
                }

                // Delete associated order products
                $order->orderProducts()->delete();

                // Delete the order itself
                $order->delete();
            }


             if ($validatedInvoice['sale_type'] == 'credit') {
                if ($custCreditUsage > $customerCreditLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invoice exceeds customer credit limit.',
                        'data' => null
                    ], 422);
                }
            }

            // Create the invoice
            $invoice = Invoice::create($validatedInvoice);

            // Collect all invoice product records
            $invoiceProducts = [];
            foreach ($validatedInvoice['products'] as $product) {

                $availableProduct = Product::find($product['product_id']);

                // Check if the product exists and has sufficient stock
                // if (!$availableProduct || $availableProduct->quantity < $product['quantity']) {
                //     throw new Exception("Insufficient stock for Product ID {$product['product_id']}. Available: {$availableProduct->quantity}, Requested: {$product['quantity']}.");
                // }

                $availableProduct->decrement('quantity', $product['quantity'] + ($product['bonus_qty'] ?? 0));

                $invoiceProducts[] = InvoiceProduct::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'unit_price' => $product['unit_price'],
                    'bonus_qty' => $product['bonus_qty'],
                    'price_type' => $product['price_type'],
                ]);
            }

            // Commit the transaction
            DB::commit();

            // Invoice তৈরি করা employee কে notify করো
            $employeeUser = $invoice->employee->user;

            if ($employeeUser) {
                $employeeUser->notify(new InvoiceNotification(
                    "Your invoice {$invoice->invoiceId} has been created successfully.",
                    $invoice->id
                ));
            }

            // Manager notify
            $managerId = Relation::where('employee_id', $employee->id)->value('relation_id');
            $manager = null;

            if ($managerId) {
                $manager = Employee::find($managerId);
                if ($manager && $manager->user) {
                    $manager->user->notify(new InvoiceNotification(
                        "Employee {$employee->name} created invoice {$invoice->invoiceId}.",
                        $invoice->id
                    ));
                }
            }

            // Admin notify করো
            $admins = Employee::whereHas('designation', function ($q) {
                $q->where('slug', 'admin');
            })->with('user')->get();

            foreach ($admins as $admin) {
                if ($admin->user) {
                    $admin->user->notify(new InvoiceNotification(
                        "A new invoice {$invoice->invoiceId} has been created by {$employee->name}.",
                        $invoice->id
                    ));
                }
            }

            // Manager email
            $managerEmail = optional(optional($manager)->user)->email;

            if ($managerEmail) {
                Mail::to($managerEmail)->send(new InvoiceCreatedMail($invoice));
            }

            // Customer SMS notification
            if ($customer && $customer->phone && $customer->sms_enabled) {
                $message = "Dear Customer,\n{$customer->customer_name}\nYour new invoice (ID: {$invoice->invoiceId}) has been created. Total Bill: {$invoice->grand_total} TK. Thank you for staying with us. - Radian Agrovet";
                $this->smsService->sendSms($customer->phone, $message, $customer->id);
            }


            return response()->json([
                'status' => true,
                'message' => 'Invoice and products created successfully',
                'data' => [
                    'invoice' => $invoice,
                    'products' => $invoiceProducts,
                ],
            ]);
        } catch (Exception $e) {
            // Rollback the transaction in case of an error
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create invoice: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function update(Request $request, $Id)
    {
        DB::beginTransaction();
        try {
            // Validate invoice data
            $validatedInvoice = $request->validate([
                'cust_id' => 'required|exists:customers,id',
                'total_item' => 'required|integer',
                'total_price' => 'required|numeric',
                'discount' => 'nullable|numeric',
                'less' => 'nullable|numeric',
                'grand_total' => 'required|numeric',
                'paid' => 'required|numeric',
                'due' => 'required|numeric',
                'sale_date' => 'required|string',
                'sale_type' => 'required|in:cash,credit',
                'offer' => 'nullable|string|max:255',
                'products' => 'required|array',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.quantity' => 'required|numeric|min:0',
                'products.*.unit_price' => 'required|numeric|min:0',
                'products.*.due_quantity' => 'nullable|numeric',
                'products.*.bonus_qty' => 'nullable|numeric',
                'products.*.price_type' => 'required|in:tp,flat',
            ]);

            $validatedInvoice['products'] = $this->normalizeProductQuantities($validatedInvoice['products']);

            // Recalculate grand_total and due using custom rounding logic
            $calculatedGrandTotal = $this->calculateGrandTotal(
                $validatedInvoice['total_price'],
                $validatedInvoice['discount'] ?? 0,
                $validatedInvoice['less'] ?? 0
            );
            $validatedInvoice['grand_total'] = $calculatedGrandTotal;
            $validatedInvoice['due'] = $calculatedGrandTotal - $validatedInvoice['paid'];

            if ($validatedInvoice['paid'] > $validatedInvoice['grand_total']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Paid amount cannot be greater than the grand total.',
                ]);
            }

            // Find existing invoice
            $invoice = Invoice::findOrFail($Id);
            $validatedInvoice['employee_id'] = $invoice->employee_id;

            // Get employee credit limit
            $employee = Employee::find($validatedInvoice['employee_id']);
            $employeeCreditLimit = $employee->credit_limit;
            $totalDue = Invoice::where('employee_id', $employee->id)
                ->where('id', '!=', $Id) // Exclude current invoice
                ->sum('due');
            $totalOrderAmount = Order::where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

            $credit_limit = $totalDue + $totalOrderAmount + $validatedInvoice['grand_total'];
            
            if ($validatedInvoice['sale_type'] == 'credit') {
                if ($credit_limit > $employeeCreditLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invoice exceeds employee credit limit.',
                        'data' => null
                    ], 422);
                }
            }

            // Customer Credit Limit check
            $customer = Customer::findOrFail($validatedInvoice['cust_id']);
            $customerCreditLimit = $customer->credit_limit;

            $custTotalPurchase = Invoice::where('cust_id', $customer->id)->sum('grand_total');
            $custTotalPayment = Payment::where('cust_id', $customer->id)->sum('amount');

            $custPendingOrderAmount = Order::where('cust_id', $customer->id)
                ->where('status', 'pending')
                ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

            // Adjust total purchase by removing old grand total and adding new grand total
            $newCustTotalPurchase = $custTotalPurchase - $invoice->grand_total + $validatedInvoice['grand_total'];
            $custCurrentDue = ($customer->old_due + $newCustTotalPurchase) - $custTotalPayment;
            $custCreditUsage = $custCurrentDue + $custPendingOrderAmount;

            if ($validatedInvoice['sale_type'] == 'credit') {
                if ($custCreditUsage > $customerCreditLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Updated invoice exceeds customer credit limit.',
                        'data' => null
                    ], 422);
                }
            }

            // Restore stock for removed products
            $updatedProductIds = collect($validatedInvoice['products'])->pluck('product_id')->toArray();
            foreach ($invoice->products as $invoiceProduct) {
                if (!in_array($invoiceProduct->product_id, $updatedProductIds)) {
                    Product::where('id', $invoiceProduct->product_id)
                        ->increment('quantity', $invoiceProduct->quantity + ($invoiceProduct->bonus_qty ?? 0));
                    $invoiceProduct->delete();
                }
            }

            // Update invoice
            $invoice->update($validatedInvoice);

            // Fetch all necessary product data at once
            $productIds = collect($validatedInvoice['products'])->pluck('product_id')->toArray();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            // Update or create invoice products
            foreach ($validatedInvoice['products'] as $productData) {
                $product = $products[$productData['product_id']];
                $existingProduct = $invoice->products->where('product_id', $productData['product_id'])->first();

                // Restore old stock before updating
                if ($existingProduct) {
                    $product->increment('quantity', $existingProduct->quantity + ($existingProduct->bonus_qty ?? 0));
                }

                // Decrement stock based on new quantity
                $product->decrement('quantity', $productData['quantity'] + ($productData['bonus_qty'] ?? 0));

                // Update or create invoice product
                InvoiceProduct::updateOrCreate(
                    ['invoice_id' => $invoice->id, 'product_id' => $productData['product_id']],
                    [
                        'quantity' => $productData['quantity'],
                        'unit_price' => $productData['unit_price'],
                        'due_quantity' => $productData['due_quantity'] ?? 0,
                        'bonus_qty' => $productData['bonus_qty'] ?? 0,
                        'price_type' => $productData['price_type'],
                    ]
                );
            }

            // Commit transaction
            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Invoice and products updated successfully',
                'data' => ['invoice' => $invoice],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to update invoice: ' . $e->getMessage(),
            ]);
        }
    }


    public function destroy($invoiceId)
    {
        DB::beginTransaction();
        try {
            // Find the existing invoice
            $invoice = Invoice::findOrFail($invoiceId);

            // Revert stock for each product in the invoice
            foreach ($invoice->products as $invoiceProduct) {
                $product = Product::find($invoiceProduct->product_id);

                // Restore the stock by incrementing the quantity
                $product->increment('quantity', $invoiceProduct->quantity + $invoiceProduct->bonus_qty);
            }

            // Delete all related invoice products
            $invoice->products()->delete();

            // Delete the invoice
            $invoice->delete();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Invoice and products deleted successfully',
                'data' => null,
            ]);
        } catch (Exception $e) {
            // Rollback the transaction in case of an error
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete invoice: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    public function markPrinted($id)
    {
        Invoice::where('id', $id)->update([
            'is_printed' => true,
            'printed_at' => now()
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Invoice marked as printed',
            'data' => null,
        ]);
    }

    public function updateOffer(Request $request, $id)
    {
        try {
            $request->validate([
                'offer' => 'nullable|string|max:255',
            ]);

            $invoice = Invoice::findOrFail($id);
            $invoice->update([
                'offer' => $request->offer,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Offer updated successfully',
                'data' => $invoice,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update offer: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }
}
