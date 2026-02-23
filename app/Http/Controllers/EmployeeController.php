<?php

namespace App\Http\Controllers;

use Exception;
use Throwable;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {

        $employees = Employee::with('designation', 'relations')->where('status', 'active')->get();

        return response()->json([
            'status' => true,
            'message' => 'All employees retrieved successfully',
            'data' => $employees,
        ]);
    }

    public function store(Request $request)
    {

        try {
            $validatedEmployeeData = $request->validate([
                'employee_id' => 'required|string|unique:employees',
                'designation_id' => 'required|exists:designations,id',
                'name' => 'required|string|max:255',
                'phone' => 'required|string|unique:employees',
                'territory' => 'required|string|max:255',
                'district' => 'required|string|max:255',
                'national_id' => 'nullable|string',
                'blood_group' => 'nullable|string',
                'credit_limit' => 'nullable|numeric',
                'basic_salary' => 'nullable|numeric',
            ]);

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the image
                $imageName = 'employee_' . $validatedEmployeeData['employee_id'] . '_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the image with the custom name in the 'employees' directory within the 'public' disk
                $imagePath = $image->storeAs('employees', $imageName, 'public');

                // Save the image path to the validated data
                $validatedEmployeeData['image'] = $imagePath;
            }

            // dd($request->user()->id);
            $validatedEmployeeData['created_by'] = $request->user()->id;

            // Create the employee
            $employee = Employee::create($validatedEmployeeData);



            return response()->json(['status' => true, 'message' => 'Employee created successfully', 'data' => $employee]);
        } catch (Throwable $e) {

            return response()->json(['status' => false, 'message' => 'Something is worng', 'error' => $e->getMessage()]);
        }
    }
    public function show($id)
    {
        try {
            // Find the employee or fail
            $employee = Employee::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Employee retrieved successfully',
                'data' => $employee,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Employee not found',
                'error' => $e->getMessage()
            ]);
        }
    }
    public function update(Request $request, $id)
    {
        try {
            // Find the employee by ID
            $employee = Employee::findOrFail($id);

            // Validate the incoming request data
            $validatedEmployeeData = $request->validate([
                'employee_id' => 'required|string|unique:employees,employee_id,' . $employee->id,
                'designation_id' => 'required|exists:designations,id',
                'name' => 'required|string|max:255',
                'phone' => 'required|string|unique:employees,phone,' . $employee->id,
                'territory' => 'required|string|max:255',
                'district' => 'required|string|max:255',
                'national_id' => 'nullable|string',
                'blood_group' => 'nullable|string',
                'credit_limit' => 'nullable|numeric',
                'basic_salary' => 'nullable|numeric',
            ]);

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the new image
                $imageName = 'employee_' . $validatedEmployeeData['employee_id'] . '_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the new image in the 'employees' directory within the 'public' disk
                $imagePath = $image->storeAs('employees', $imageName, 'public');

                // Delete the old image if it exists
                if ($employee->image && Storage::disk('public')->exists($employee->image)) {
                    Storage::disk('public')->delete($employee->image);
                }

                // Save the new image path to the validated data
                $validatedEmployeeData['image'] = $imagePath;
            }

            // Update the employee record
            $employee->update($validatedEmployeeData);

            // Return a success response
            return response()->json(['status' => true, 'message' => 'Employee updated successfully', 'data' => $employee]);
        } catch (Exception $e) {
            // Return an error response
            return response()->json(['status' => false, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $currentUserId = $request->user()->id;

            // Prevent the currently logged-in user from deactivating themselves
            if ($currentUserId == $id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot deactivate yourself.',
                ]);
            }

            $employee = Employee::findOrFail($id);

            // Update the status to 'deactive'
            $employee->update(['status' => 'deactive']);

            return response()->json(['status' => true, 'message' => 'Employee deactivated successfully']);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function creditReport(Request $request)
    {
        try {
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');

            $employees = Employee::where('status', 'active')->get();

            $report = $employees->map(function ($employee) use ($fromDate, $toDate) {
                // Total credit purchase from invoices
                $purchaseQuery = DB::table('invoices')
                    ->where('employee_id', $employee->id);

                if ($fromDate && $toDate) {
                    $purchaseQuery->whereBetween('sale_date', [$fromDate, $toDate]);
                }

                $totalCreditPurchase = $purchaseQuery->sum('grand_total');

                // Total payment
                $paymentQuery = DB::table('payments')
                    ->where('employee_id', $employee->id);

                if ($fromDate && $toDate) {
                    $paymentQuery->whereBetween('payment_date', [$fromDate, $toDate]);
                }

                $totalPayment = $paymentQuery->sum('amount');

                $creditUseFromInvoices = $totalCreditPurchase - $totalPayment;

                // Total amount from pending orders (not yet invoiced)
                $orderQuery = DB::table('orders')
                    ->where('employee_id', $employee->id)
                    ->where('orders.order_type', 'credit');

                if ($fromDate && $toDate) {
                    $orderQuery->whereBetween('order_date', [$fromDate, $toDate]);
                }

                $creditUseFromOrders = $orderQuery->join('order_products', 'orders.id', '=', 'order_products.order_id')
                    ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

                $creditUse = $creditUseFromInvoices + $creditUseFromOrders;

                //  Total Sale (invoice based)
                $saleQuery = DB::table('invoices')
                    ->where('employee_id', $employee->id);

                if ($fromDate && $toDate) {
                    $saleQuery->whereBetween('sale_date', [$fromDate, $toDate]);
                }

                $totalSale = $saleQuery->sum('grand_total'); // invoice total column
                $creditLimit = $employee->credit_limit ?? 0;
                $creditDue = $creditLimit - $creditUse;

                return [
                    'id' => $employee->id,
                    'employee_name' => $employee->name,
                    'credit_limit' => number_format($creditLimit, 2, '.', ''),
                    'total_purchase' => number_format($totalCreditPurchase, 2, '.', ''),
                    'total_payment' => number_format($totalPayment, 2, '.', ''),
                    'total_due' => number_format($totalCreditPurchase - $totalPayment, 2, '.', ''),
                    'credit_use' => number_format($creditUse, 2, '.', ''),
                    'credit_due' => number_format($creditDue, 2, '.', ''),
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Employee credit report retrieved successfully',
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve credit report',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
