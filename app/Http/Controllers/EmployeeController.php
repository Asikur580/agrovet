<?php

namespace App\Http\Controllers;

use Exception;
use Throwable;
use App\Models\User;
use App\Models\Employee;
use App\Services\CreditUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $designation = $user->employee->designation->slug ?? null;
        $employeeId = $user->employee->id ?? null;

        $query = Employee::with('designation', 'relations', 'user:id,employee_id,email')->where('status', 'active');

        if (!in_array($designation, ['admin', 'super_admin', 'developer'])) {
            if ($designation === 'officer') {
                $query->where('id', $employeeId);
            } elseif ($designation === 'manager') {
                $officerIds = DB::table('relations')->where('relation_id', $employeeId)->pluck('employee_id');
                $allowedIds = $officerIds->push($employeeId);
                $query->whereIn('id', $allowedIds);
            } elseif ($designation === 'rsm') {
                $managerIds = DB::table('relations')->where('relation_id', $employeeId)->pluck('employee_id');
                $officerIds = DB::table('relations')->whereIn('relation_id', $managerIds)->pluck('employee_id');
                $allowedIds = $managerIds->merge($officerIds)->push($employeeId);
                $query->whereIn('id', $allowedIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $employees = $query->get();

        return response()->json([
            'status' => true,
            'message' => 'Employees retrieved successfully',
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
                'basic_salary' => 'nullable|numeric',
                'credit_limit' => 'nullable|numeric',
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
            $validatedEmployeeData['credit_limit'] = $validatedEmployeeData['credit_limit'] ?? 0;

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
                'basic_salary' => 'nullable|numeric',
                'credit_limit' => 'nullable|numeric',
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
            return response()->json(['status' => true, 'message' => 'Employee updated successfully', 'data' => $employee->fresh()]);
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

            // Purchase, payment and due belong to the customer's current officer, the same
            // way GET /customers shows them. Invoices and payments keep the employee_id of
            // whoever created them, which is not always that officer.
            $sumByOfficer = function (string $table, string $amountColumn, ?string $dateColumn = null) use ($fromDate, $toDate) {
                $query = DB::table($table)
                    ->join('customers', 'customers.id', '=', "{$table}.cust_id")
                    ->selectRaw("customers.employee_id AS officer_id, SUM({$table}.{$amountColumn}) AS total")
                    ->groupBy('customers.employee_id');

                if ($dateColumn && $fromDate && $toDate) {
                    $query->whereBetween("{$table}.{$dateColumn}", [$fromDate, $toDate]);
                }

                return $query->pluck('total', 'officer_id');
            };

            $oldDueByOfficer = DB::table('customers')
                ->selectRaw('employee_id AS officer_id, SUM(old_due) AS total')
                ->groupBy('employee_id')
                ->pluck('total', 'officer_id');

            // Due is the running balance, so the date range does not apply to it.
            $allPurchaseByOfficer = $sumByOfficer('invoices', 'grand_total');
            $allPaymentByOfficer = $sumByOfficer('payments', 'amount');

            if ($fromDate && $toDate) {
                $purchaseByOfficer = $sumByOfficer('invoices', 'grand_total', 'sale_date');
                $paymentByOfficer = $sumByOfficer('payments', 'amount', 'payment_date');
            } else {
                $purchaseByOfficer = $allPurchaseByOfficer;
                $paymentByOfficer = $allPaymentByOfficer;
            }

            // Credit use is a running balance plus pending credit orders, so the date range does not apply to it.
            $creditUseByOfficer = app(CreditUsageService::class)->byOfficer();

            $report = $employees->map(function ($employee) use ($oldDueByOfficer, $allPurchaseByOfficer, $allPaymentByOfficer, $purchaseByOfficer, $paymentByOfficer, $creditUseByOfficer) {
                $creditUse = $creditUseByOfficer[$employee->id] ?? 0;
                $creditLimit = $employee->credit_limit ?? 0;
                $creditDue = $creditLimit - $creditUse;

                $oldDue = $oldDueByOfficer[$employee->id] ?? 0;
                $totalDue = $oldDue
                    + ($allPurchaseByOfficer[$employee->id] ?? 0)
                    - ($allPaymentByOfficer[$employee->id] ?? 0);

                return [
                    'id' => $employee->id,
                    'employee_name' => $employee->name,
                    'credit_limit' => number_format($creditLimit, 2, '.', ''),
                    'old_due' => number_format($oldDue, 2, '.', ''),
                    'total_purchase' => number_format($purchaseByOfficer[$employee->id] ?? 0, 2, '.', ''),
                    'total_payment' => number_format($paymentByOfficer[$employee->id] ?? 0, 2, '.', ''),
                    'total_due' => number_format($totalDue, 2, '.', ''),
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
