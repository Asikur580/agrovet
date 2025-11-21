<?php

namespace App\Http\Controllers;

use Exception;
use Throwable;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Product;
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
            $user = $request->user(); // logged-in user

            $ordersQuery = Order::with(['customer', 'employee', 'orderProducts.product'])->latest();;

            if ($user->employee->designation->slug == 'admin') {
                // Admin sob order dekhe
                $orders = $ordersQuery->get();
            } elseif ($user->employee->designation->slug == 'officer') {
                // Officer tar nijer order

                $orders = $ordersQuery->where('employee_id', $user->employee->id)->get();
            } elseif ($user->employee->designation->slug == 'manager') {
                // Manager er under e thaka officer der order
                $officerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id'); // assume manager_id field ache
                $orders = $ordersQuery->whereIn('employee_id', $officerIds)->get();
            } elseif ($user->employee->designation->slug == 'rsm') {
                // RS এর under thaka manager এর employee_id
                $managerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');

                // Manager এর under officer এর employee_id
                $officerIds = Relation::whereIn('relation_id', $managerIds)->pluck('employee_id');

                // সব employee id = manager + officer
                $allEmployeeIds = $managerIds->merge($officerIds);

                // orders filter
                $orders = $ordersQuery->whereIn('employee_id', $allEmployeeIds)->get();
            } else {
                // Default: kichu na dekhao
                $orders = collect();
            }

            $orders = $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'customer_name' => $order->customer->customer_name ?? 'N/A',
                    'employee_name' => $order->employee->name ?? 'N/A',
                    'products' => $order->orderProducts->map(function ($orderProduct) {
                        return [
                            'product_name' => $orderProduct->product->name ?? 'N/A',
                            'pack_size' => $orderProduct->product->pack_size ?? 'N/A',
                            'quantity' => $orderProduct->quantity ?? 'N/A',
                            'unit_price' => $orderProduct->unit_price ?? 'N/A',
                            'bonus_qty' => $orderProduct->bonus_qty ?? 'N/A',
                            'due_quantity' => $orderProduct->due_quantity ?? 'N/A',
                            'price_type' => $orderProduct->price_type ?? 'N/A',
                        ];
                    }),
                    'order_date' => $order->order_date,
                    'order_type' => $order->order_type,
                    'status' => $order->status,
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

        //dd('ok');
        DB::beginTransaction();

        try {
            // Credit calculation
            $totalDue = Invoice::where('employee_id', $employee->id)->sum('due');
            $totalOrderAmount = OrderProduct::whereHas(
                'order',
                fn($q) =>
                $q->where('employee_id', $employee->id)
            )->join('products', 'order_products.product_id', '=', 'products.id')
                ->sum(DB::raw('order_products.quantity * products.sell_price'));

            $newTotalOrderAmount = collect($validated['products'])
                ->sum(fn($p) => $p['unit_price'] * $p['quantity']);

            $creditLimitUsage = $totalDue + $totalOrderAmount + $newTotalOrderAmount;

            if ($creditLimitUsage > $employee->credit_limit) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order exceeds your credit limit.',
                    'data' => null
                ], 422);
            }

            // Create order
            $order = Order::create([
                'cust_id'     => $validated['cust_id'],
                'employee_id' => $employee->id,
                'discount'    => $validated['discount'] ?? 0,
               'order_date' => $validated['order_date'],
                'order_type'  => $validated['order_type'],
            ]);

            // Bulk insert products
            $orderProducts = collect($validated['products'])->map(fn($p) => [
                'order_id'   => $order->id,
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'unit_price' => $p['unit_price'],
                'bonus_qty'  => $p['bonus_qty'] ?? 0,
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
                'products' => 'required|array',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.quantity' => 'required|integer|min:1',
                'products.*.unit_price' => 'required|numeric|min:0',
                'products.*.bonus_qty' => 'nullable|numeric',
                'products.*.price_type' => 'required|in:tp,flat',
            ]);

            $employeeId = $request->user()->employee_id; // Retrieve the authenticated employee
            $employee = Employee::find($employeeId);
            $employeeCreditLimit = $employee->credit_limit;

            $totalDue = DB::table('invoices')
                ->where('employee_id', $employeeId)
                ->sum('due');

            $totalOrderAmount = DB::table('orders')
                ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                ->join('products', 'order_products.product_id', '=', 'products.id')
                ->where('orders.employee_id', $employeeId)
                ->sum(DB::raw('order_products.quantity * products.sell_price'));

            $order = Order::findOrFail($id);


            // Calculate the total amount for the updated order
            $updatedOrderAmount = 0;
            foreach ($request->products as $product) {
                $updatedOrderAmount += $product['unit_price'] * $product['quantity'];
            }

            // Calculate the adjusted credit limit after the update
            $credit_limit = $totalDue + $totalOrderAmount + $updatedOrderAmount;

            if ($credit_limit > $employeeCreditLimit) {
                return response()->json([
                    'status' => false,
                    'message' => 'Updated order exceeds your credit limit.',
                    'data' => null
                ]);
            }
            
            

            // Update order info
            $order->update([
                'cust_id' => $validated['cust_id'],
                'discount' => $validated['discount'] ?? 0,
                'order_date' => $validated['order_date'],
                'order_type' => $validated['order_type'],
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
            'status'  => true,
            'message' => 'Order status changed from ' . $old . ' to ' . $order->status . ' successfully.',
            'data'    => null,
        ]);
    }
}
