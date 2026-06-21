<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\User;
use App\Models\Employee;
use App\Models\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Show the profile details of the authenticated user.
     */
    public function show(Request $request)
    {
        try {
            $user = $request->user();
            
            // Load user with employee details and their designation
            $user = User::with(['employee.designation'])->findOrFail($user->id);

            // Fetch the user's permissions
            $permissions = $user->getDirectPermissions()->pluck('name');

            // Default performance and business metrics
            $metrics = [
                'customers_count' => 0,
                'subordinates_count' => 0,
                'sales_count' => 0,
                'total_sales' => '0.00',
                'credit_limit' => '0.00',
                'credit_use' => '0.00',
                'credit_available' => '0.00',
            ];

            if ($user->employee) {
                $employee = $user->employee;

                // 1. Customers count
                $metrics['customers_count'] = $employee->customers()->count();

                // 2. Subordinates count
                $metrics['subordinates_count'] = Relation::where('relation_id', $employee->id)->count();

                // 3. Sales/Invoices count
                $metrics['sales_count'] = $employee->invoices()->count();

                // 4. Total sales amount (grand_total of invoices)
                $totalSales = $employee->invoices()->sum('grand_total');
                $metrics['total_sales'] = number_format($totalSales, 2, '.', '');

                // 5. Credit Metrics
                $creditLimit = $employee->credit_limit ?? 0;

                // Total credit purchase from invoices
                $totalCreditPurchase = DB::table('invoices')
                    ->where('employee_id', $employee->id)
                    ->sum('grand_total');

                // Total payment
                $totalPayment = DB::table('payments')
                    ->where('employee_id', $employee->id)
                    ->sum('amount');

                $creditUseFromInvoices = $totalCreditPurchase - $totalPayment;

                // Total amount from pending orders (not yet invoiced)
                $creditUseFromOrders = DB::table('orders')
                    ->where('employee_id', $employee->id)
                    ->where('orders.order_type', 'credit')
                    ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                    ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

                $creditUse = $creditUseFromInvoices + $creditUseFromOrders;
                $creditAvailable = $creditLimit - $creditUse;

                $metrics['credit_limit'] = number_format($creditLimit, 2, '.', '');
                $metrics['credit_use'] = number_format($creditUse, 2, '.', '');
                $metrics['credit_available'] = number_format($creditAvailable, 2, '.', '');
            }

            return response()->json([
                'status' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => $user,
                    'permissions' => $permissions,
                    'metrics' => $metrics,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the profile of the authenticated user.
     */
    public function update(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = $request->user();
            $employee = $user->employee;

            if (!$employee) {
                return response()->json([
                    'status' => false,
                    'message' => 'No employee profile associated with this user account.',
                ], 404);
            }

            // Validate details
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|unique:employees,phone,' . $employee->id,
                'email' => 'required|email|unique:users,email,' . $user->id,
                'territory' => 'required|string|max:255',
                'district' => 'required|string|max:255',
                'national_id' => 'nullable|string',
                'blood_group' => 'nullable|string|max:10',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate unique image name
                $imageName = 'employee_' . $employee->employee_id . '_' . time() . '.' . $image->getClientOriginalExtension();

                // Store in public disk
                $imagePath = $image->storeAs('employees', $imageName, 'public');

                // Delete old image if it exists
                if ($employee->image && Storage::disk('public')->exists($employee->image)) {
                    Storage::disk('public')->delete($employee->image);
                }

                $employee->image = $imagePath;
            }

            // Update employee record
            $employee->update([
                'name' => $validatedData['name'],
                'phone' => $validatedData['phone'],
                'territory' => $validatedData['territory'],
                'district' => $validatedData['district'],
                'national_id' => $validatedData['national_id'] ?? $employee->national_id,
                'blood_group' => $validatedData['blood_group'] ?? $employee->blood_group,
            ]);

            // Update user record (email)
            $user->update([
                'email' => $validatedData['email'],
            ]);

            DB::commit();

            // Fetch fresh updated user with profile details
            $updatedUser = User::with(['employee.designation'])->find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedUser,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Change password for the authenticated user.
     */
    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed',
            ]);

            $user = $request->user();

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'The current password you entered is incorrect.',
                ], 422);
            }

            // Update user password
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Password changed successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to change password',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
