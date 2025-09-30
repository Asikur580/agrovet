<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Salary;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalaryController extends Controller
{
    // Display a listing of salaries
    public function index()
    {
        try {
            $salaries = Salary::all(); // Retrieve all salary records
            return response()->json([
                'status' => true,
                'message' => 'Salaries retrieved successfully',
                'data' => $salaries
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve salaries',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Show the form for editing a salary (if using Blade views)
    public function show($id)
    {
        try {
            $salary = Salary::findOrFail($id); // Find the salary record
            // Retrieve all employees
            return response()->json([
                'status' => true,
                'message' => 'Salary record fetched successfully for editing',
                'data' => $salary
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Salary record not found',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function employeeSalary($employeeId)
    {
        try {
            // Validate employeeId
            if (empty($employeeId)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Employee ID is required.',
                    'data' => null,
                ]);
            }

            // Check if the employee exists
            $employeeExists = Employee::where('id', $employeeId)->exists();
            if (!$employeeExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Employee not found.',
                    'data' => null,
                ]);
            }

            // Retrieve employee salary details ordered by month_year (ascending)
            $employeeSalary = Salary::select(
                'salaries.id',
                'employees.name as employee_name',
                'salaries.month_year',
                'salaries.basic_salary',
                'salaries.paid_amount',
                'salaries.advance_amount',
                'salaries.due_amount'
            )
                ->join('employees', 'salaries.employee_id', '=', 'employees.id')
                ->where('salaries.employee_id', $employeeId)
                ->orderByRaw('
                CASE
                    WHEN STR_TO_DATE(salaries.month_year, "%Y-%m") IS NOT NULL THEN STR_TO_DATE(salaries.month_year, "%Y-%m")
                    ELSE NULL
                END ASC
            ') // Handle invalid data
                ->get();

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'Employee salary details retrieved successfully.',
                'data' => $employeeSalary,
            ]);
        } catch (Exception $e) {
            // Return error response
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve employee salary details.',
                'error' => $e->getMessage(),
            ]);
        }
    }



    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validate Request
            $validatedData = $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'paid_amount' => 'required|numeric|min:0',
                'month_year' => 'required'
            ]);

            $employee = Employee::findOrFail($request->employee_id);
            $basicSalary = $employee->basic_salary;
            $paidAmount = $request->paid_amount;
            $currentMonth = $request->month_year;

            // 🔹 Check if a salary record already exists for the current month
            $existingSalary = Salary::where('employee_id', $employee->id)
                ->where('month_year', $currentMonth)
                ->first();

            // 🔹 Get ALL previous unpaid months (including dues & advances)
            $previousSalaries = Salary::where('employee_id', $employee->id)
                ->where('month_year', '<', $currentMonth)
                ->orderBy('month_year', 'desc')
                ->get();

            // 🔹 Calculate total previous dues & advances
            $totalPreviousDue = 0;
            $totalPreviousAdvance = 0;

            foreach ($previousSalaries as $prevSalary) {
                $totalPreviousDue += $prevSalary->due_amount;
                $totalPreviousAdvance += $prevSalary->advance_amount;
            }

            // ✅ Total Adjusted Salary Calculation (Including Current Month)
            $adjustedSalary = ($basicSalary + $totalPreviousDue) - $totalPreviousAdvance;

            // 🔹 If salary already exists for the month, update it
            if ($existingSalary) {
                $newPaidAmount = $existingSalary->paid_amount + $paidAmount;

                if ($newPaidAmount >= $adjustedSalary) {
                    $newAdvance = $newPaidAmount - $adjustedSalary;
                    $newDue = 0;
                } else {
                    $newDue = $adjustedSalary - $newPaidAmount;
                    $newAdvance = 0;
                }

                // Update existing salary record
                $existingSalary->update([
                    'basic_salary' => $basicSalary,
                    'paid_amount' => $newPaidAmount,
                    'advance_amount' => $newAdvance,
                    'due_amount' => $newDue,
                ]);

                $salary = $existingSalary;
            } else {
                // 🔹 First-time salary entry for the month
                if ($paidAmount >= $adjustedSalary) {
                    $newAdvance = $paidAmount - $adjustedSalary;
                    $newDue = 0;
                } else {
                    $newDue = $adjustedSalary - $paidAmount;
                    $newAdvance = 0;
                }

                // Insert new salary record
                $salary = Salary::create([
                    'employee_id' => $employee->id,
                    'month_year' => $currentMonth,
                    'basic_salary' => $basicSalary,
                    'paid_amount' => $paidAmount,
                    'advance_amount' => $newAdvance,
                    'due_amount' => $newDue,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Salary processed successfully',
                'data' => $salary
            ]);
        } catch (Exception $e) {
            DB::rollback();

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong!',
                'error' => $e->getMessage()
            ]);
        }
    }



    // Update a salary record in the database
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            // Validate Request
            $validatedData = $request->validate([
                'paid_amount' => 'required|numeric|min:0'
            ]);

            // Get the salary record by ID
            $salary = Salary::findOrFail($id);
            $employee = Employee::findOrFail($salary->employee_id);
            $basicSalary = $employee->basic_salary;
            $newPaidAmount = $request->paid_amount;

            // Get previous salaries for due & advance adjustments
            $previousSalaries = Salary::where('employee_id', $employee->id)
                ->where('month_year', '<', $salary->month_year)
                ->orderBy('month_year', 'desc')
                ->get();

            $totalPreviousDue = 0;
            $totalPreviousAdvance = 0;

            foreach ($previousSalaries as $prevSalary) {
                $totalPreviousDue += $prevSalary->due_amount;
                $totalPreviousAdvance += $prevSalary->advance_amount;
            }

            // ✅ Total Adjusted Salary Calculation (Including Previous Due & Advances)
            $adjustedSalary = ($basicSalary + $totalPreviousDue) - $totalPreviousAdvance;

            // ✅ Update paid amount
            $updatedPaidAmount = $newPaidAmount;

            if ($updatedPaidAmount >= $adjustedSalary) {
                $newAdvance = $updatedPaidAmount - $adjustedSalary;
                $newDue = 0;
            } else {
                $newDue = $adjustedSalary - $updatedPaidAmount;
                $newAdvance = 0;
            }

            // Update salary record
            $salary->update([
                'paid_amount' => $updatedPaidAmount,
                'advance_amount' => $newAdvance,
                'due_amount' => $newDue,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Salary updated successfully',
                'data' => $salary
            ]);
        } catch (Exception $e) {
            DB::rollback();

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong!',
                'error' => $e->getMessage()
            ]);
        }
    }



    // Remove a salary record from the database
    public function destroy($id)
    {
        try {
            $salary = Salary::findOrFail($id);
            $salary->delete(); // Delete the salary record
            return response()->json([
                'status' => true,
                'message' => 'Salary deleted successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while deleting the salary',
                'error' => $e->getMessage()
            ]);
        }
    }
}
