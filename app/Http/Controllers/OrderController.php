<?php

namespace App\Http\Controllers;

use Exception;
use Throwable;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Employee;
use App\Models\Relation;
use App\Models\OrderProduct;
use Illuminate\Http\Request;
use App\Mail\OrderCreatedMail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Notifications\OrderNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OrderController extends Controller
{
    /**
     * Display a listing of the orders.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $customerId = $request->customer_id; // Filter
            $employeeId = $request->employee_id; // Filter

            $ordersQuery = Order::with(['customer', 'employee', 'orderProducts.product'])->latest();

            // Role based filtering
            if ($user->employee->designation->slug == 'admin') {
                // Admin all
            } elseif ($user->employee->designation->slug == 'officer') {
                $ordersQuery->where('employee_id', $user->employee->id);
            } elseif ($user->employee->designation->slug == 'manager') {

                $officerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');
                $ordersQuery->whereIn('employee_id', $officerIds);
            } elseif ($user->employee->designation->slug == 'rsm') {

                $managerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');
                $officerIds = Relation::whereIn('relation_id', $managerIds)->pluck('employee_id');
                $allEmployeeIds = $managerIds->merge($officerIds);

                $ordersQuery->whereIn('employee_id', $allEmployeeIds);
            } else {
                return response()->json([
                    'status' => true,
                    'message' => 'No orders available.',
                    'data' => []
                ]);
            }

            // 🔥 Apply customer filter
            if ($customerId) {
                $ordersQuery->where('cust_id', $customerId);
            }

            // 🔥 Apply employee filter
            if ($employeeId) {
                $ordersQuery->where('employee_id', $employeeId);
            }

            $orders = $ordersQuery->get();

            // Format Data
            $orders = $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'customer_name' => $order->customer->customer_name ?? 'N/A',
                    'employee_name' => $order->employee->name ?? 'N/A',
                    'employee_id' => $order->employee->employee_id ?? 'N/A',
                    'products' => $order->orderProducts->map(function ($orderProduct) {
                        return [
                            'product_name' => $orderProduct->product->name,
                            'pack_size' => $orderProduct->product->pack_size,
                            'quantity' => $orderProduct->quantity,
                            'unit_price' => $orderProduct->unit_price,
                            'bonus_qty' => $orderProduct->bonus_qty,
                            'due_quantity' => $orderProduct->due_quantity,
                            'price_type' => $orderProduct->price_type,
                        ];
                    }),
                    'order_date' => $order->order_date,
                    'order_type' => $order->order_type,
                    'status' => $order->status,
                    'offer' => $order->offer,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Orders retrieved successfully.',
                'data' => $orders
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch orders.',
                'data' => null
            ]);
        }
    }



    /**
     * Store a newly created order in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cust_id' => 'required|exists:customers,id',
            'discount' => 'nullable|numeric',
            'order_date' => 'required|string',
            'order_type' => 'required|in:cash,credit',
            'offer' => 'nullable|string|max:255',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.unit_price' => 'required|numeric|min:0',
            'products.*.bonus_qty' => 'nullable|numeric',
            'products.*.price_type' => 'required|in:tp,flat',
        ]);

        $employee = $request->user()->employee;

        // Manager Email (Relation table → manager)
        $managerId = Relation::where('employee_id', $employee->id)->value('relation_id');
        $managerEmail = optional(Employee::find($managerId)?->user)->email;

        DB::beginTransaction();

        try {
            // Credit calculation
            // $totalDue = Invoice::where('employee_id', $employee->id)->sum('due');
            // $totalOrderAmount = OrderProduct::whereHas(
            //     'order',
            //     fn($q) =>
            //     $q->where('employee_id', $employee->id)
            // )->join('products', 'order_products.product_id', '=', 'products.id')
            //     ->sum(DB::raw('order_products.quantity * products.sell_price'));

            $newTotalOrderAmount = collect($validated['products'])
                ->sum(fn($p) => $p['unit_price'] * $p['quantity']);

            // $creditLimitUsage = $totalDue + $totalOrderAmount + $newTotalOrderAmount;

            // if ($creditLimitUsage > $employee->credit_limit) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Order exceeds employee credit limit.',
            //         'data' => null
            //     ], 422);
            // }

            // Customer Credit Limit check
            $customer = Customer::findOrFail($validated['cust_id']);
            $customerCreditLimit = $customer->credit_limit;

            $custTotalPurchase = Invoice::where('cust_id', $customer->id)->sum('grand_total');
            $custTotalPayment = Payment::where('cust_id', $customer->id)->sum('amount');

            $custPendingOrderAmount = OrderProduct::whereHas(
                'order',
                fn($q) => $q->where('cust_id', $customer->id)->where('status', 'pending')
            )->join('products', 'order_products.product_id', '=', 'products.id')
                ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

            $custCurrentDue = ($customer->old_due + $custTotalPurchase) - $custTotalPayment;
            $custCreditUsage = $custCurrentDue + $custPendingOrderAmount + $newTotalOrderAmount;

            if($validated['order_type'] == 'credit'){
                if ($custCreditUsage > $customerCreditLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Order exceeds customer credit limit.',
                        'data' => null
                    ], 422);
                }
            }
            // Create order
            $order = Order::create([
                'cust_id' => $validated['cust_id'],
                'employee_id' => $employee->id,
                'discount' => $validated['discount'] ?? 0,
                'order_date' => $validated['order_date'],
                'order_type' => $validated['order_type'],
                'offer' => $validated['offer'] ?? null,
            ]);

            // Bulk insert products
            $orderProducts = collect($validated['products'])->map(fn($p) => [
                'order_id' => $order->id,
                'product_id' => $p['product_id'],
                'quantity' => $p['quantity'],
                'unit_price' => $p['unit_price'],
                'bonus_qty' => $p['bonus_qty'] ?? 0,
                'price_type' => $p['price_type'],
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            OrderProduct::insert($orderProducts);

            DB::commit();

            // 1. Order তৈরি করা employee কে notify করো
            $employeeUser = $employee->user;
            if ($employeeUser) {
                $employeeUser->notify(new OrderNotification(
                    "Your order has been created successfully.",
                    $order->id
                ));
            }

            // 2. Manager notify করো
            if ($managerId) {
                $manager = Employee::find($managerId);
                if ($manager && $manager->user) {
                    $manager->user->notify(new OrderNotification(
                        "Employee {$employee->name} created a new order.",
                        $order->id
                    ));
                }
            }

            // 3. Admin notify করো
            $admins = Employee::whereHas('designation', function ($q) {
                $q->where('slug', 'admin');
            })->with('user')->get();

            foreach ($admins as $admin) {
                if ($admin->user) {
                    $admin->user->notify(new OrderNotification(
                        "A new order has been created by {$employee->name}.",
                        $order->id
                    ));
                }
            }
            // Mail পাঠানো (DB safe হওয়ার পর)
            if ($managerEmail) {
                Mail::to($managerEmail)->send(new OrderCreatedMail($order));
            }

            return response()->json([
                'status' => true,
                'message' => 'Order created successfully.',
                'data' => $order->load('orderProducts.product')
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to create order. ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }


    /**
     * Display the specified order.
     */
    public function show($id)
    {
        try {
            $order = Order::with(['customer', 'employee', 'orderProducts.product'])->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Order retrieved successfully.',
                'data' => $order
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found.',
                'data' => null
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve order.',
                'data' => null
            ]);
        }
    }


    /**
     * Update the specified order in storage.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'cust_id' => 'required|exists:customers,id',
                'discount' => 'nullable|numeric',
                'order_date' => 'required|string',
                'order_type' => 'required|in:cash,credit',
                'offer' => 'nullable|string|max:255',
                'products' => 'required|array',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.quantity' => 'required|integer|min:1',
                'products.*.unit_price' => 'required|numeric|min:0',
                'products.*.bonus_qty' => 'nullable|numeric',
                'products.*.price_type' => 'required|in:tp,flat',
            ]);

            $employeeId = $request->user()->employee_id; // Retrieve the authenticated employee
            $employee = Employee::find($employeeId);
            // $employeeCreditLimit = $employee->credit_limit;

            // $totalDue = DB::table('invoices')
            //     ->where('employee_id', $employeeId)
            //     ->sum('due');

            // $totalOrderAmount = DB::table('orders')
            //     ->join('order_products', 'orders.id', '=', 'order_products.order_id')
            //     ->join('products', 'order_products.product_id', '=', 'products.id')
            //     ->where('orders.employee_id', $employeeId)
            //     ->sum(DB::raw('order_products.quantity * products.sell_price'));

            $order = Order::findOrFail($id);


            // Calculate the total amount for the updated order
            $updatedOrderAmount = 0;
            foreach ($request->products as $product) {
                $updatedOrderAmount += $product['unit_price'] * $product['quantity'];
            }

            // // Calculate the adjusted credit limit after the update
            // $credit_limit = $totalDue + $totalOrderAmount + $updatedOrderAmount;

            // if ($credit_limit > $employeeCreditLimit) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Updated order exceeds employee credit limit.',
            //         'data' => null
            //     ], 422);
            // }

            // Customer Credit Limit check
            $customer = Customer::findOrFail($validated['cust_id']);
            $customerCreditLimit = $customer->credit_limit;

            $custTotalPurchase = Invoice::where('cust_id', $customer->id)->sum('grand_total');
            $custTotalPayment = Payment::where('cust_id', $customer->id)->sum('amount');

            $custPendingOrderAmount = OrderProduct::whereHas(
                'order',
                fn($q) => $q->where('cust_id', $customer->id)
                    ->where('status', 'pending')
                    ->where('id', '!=', $id) // Exclude current order
            )->join('products', 'order_products.product_id', '=', 'products.id')
                ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

            $custCurrentDue = ($customer->old_due + $custTotalPurchase) - $custTotalPayment;
            $custCreditUsage = $custCurrentDue + $custPendingOrderAmount + $updatedOrderAmount;

            if($validated['order_type'] == 'credit'){
                if ($custCreditUsage > $customerCreditLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Updated order exceeds customer credit limit.',
                    'data' => null
                ], 422);            
                }
            }

            // Update order info
            $order->update([
                'cust_id' => $validated['cust_id'],
                'discount' => $validated['discount'] ?? 0,
                'order_date' => $validated['order_date'],
                'order_type' => $validated['order_type'],
                'offer' => $validated['offer'] ?? null,
            ]);

            // Remove old products and add updated products
            $order->products()->detach(); // Remove existing products
            foreach ($request->products as $product) {
                $order->products()->attach($product['product_id'], [
                    'quantity' => $product['quantity'],
                    'unit_price' => $product['unit_price'],
                    'bonus_qty' => $product['bonus_qty'],
                    'price_type' => $product['price_type'],
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Order updated successfully.',
                'data' => $order // Load updated products
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to update order. ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * Remove the specified order from storage along with its associated products.
     */
    public function destroy($id)
    {
        try {
            // Find the order or throw a ModelNotFoundException
            $order = Order::findOrFail($id);

            // Delete associated order products
            $order->orderProducts()->delete();

            // Delete the order itself
            $order->delete();

            return response()->json([
                'status' => true,
                'message' => 'Order and its associated products deleted successfully.',
                'data' => null
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found.',
                'data' => null
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete order.',
                'data' => null
            ]);
        }
    }

    public function statusChange(Request $request, Order $order)
    {
        // Validation
        $request->validate([
            'status' => 'required|in:active,inactive,pending',
        ]);

        $old = $order->status;
        $order->status = $request->input('status');
        $order->save();

        return response()->json([
            'status' => true,
            'message' => 'Order status changed from ' . $old . ' to ' . $order->status . ' successfully.',
            'data' => null,
        ]);
    }
}
