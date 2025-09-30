<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Cost;
use App\Models\Employee;
use Illuminate\Http\Request;

class CostController extends Controller
{
    public function index()
    {
        try {
            $costs = Cost::all();
            return response()->json([
                'status' => true,
                'message' => 'Costs retrieved successfully.',
                'data' => $costs,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve costs.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function store(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'cost_cat_id' => 'nullable|exists:cost_categories,id',
                'employee_cost_cat_id' => 'nullable|exists:employee_cost_categories,id',
                'employee_id' => 'nullable|exists:employees,id',
                'amount' => 'required|numeric|min:0',
                'cost_date' => 'required|string',
            ]);

            $cost = Cost::create($validatedData);
            return response()->json([
                'status' => true,
                'message' => 'Cost created successfully.',
                'data' => $cost,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create cost.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function employeeCost($employeeId)
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

            // Check if the employee exists (assuming an Employee model)
            $employeeExists = Employee::where('id', $employeeId)->exists();
            if (!$employeeExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Employee not found.',
                    'data' => null,
                ]);
            }

            // Retrieve employee costs with employee name
            $employeeCost = Cost::select(
                'costs.id',
                'employees.name as employee_name',
                'costs.amount',
                'costs.cost_date',
                'employee_cost_categories.name as employee_cost_category_name'
            )
                ->join('employees', 'costs.employee_id', '=', 'employees.id')
                ->join('employee_cost_categories', 'costs.employee_cost_cat_id', '=', 'employee_cost_categories.id') // Join with employees table
                ->where('costs.employee_id', $employeeId)
                ->orderBy('costs.cost_date', 'desc') // Optional: Order costs by date
                ->get();

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'Employee cost details retrieved successfully.',
                'data' => $employeeCost,
            ]);
        } catch (Exception $e) {
            // Return error response
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve employee cost details.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function officeCost()
    {
        try {
            // Retrieve all office cost, including cost category name
            $costs = Cost::select(
                'costs.id',
                'costs.amount',
                'costs.cost_date',                
                'cost_categories.name as cost_category_name'
            )
                ->leftJoin('cost_categories', 'costs.cost_cat_id', '=', 'cost_categories.id') // Join with cost_categories table
                ->whereNull('costs.employee_id')
                ->orderBy('costs.cost_date', 'desc') // Optional: Order by cost date
                ->get();

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'Office cost retrieved successfully.',
                'data' => $costs,
            ]);
        } catch (Exception $e) {
            // Return error response
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve office cost.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show($id)
    {
        try {
            $cost = Cost::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Cost retrieved successfully.',
                'data' => $cost,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Cost not found.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        try {

            $validatedData = $request->validate([
                'cost_cat_id' => 'nullable|exists:cost_categories,id',
                'employee_cost_cat_id' => 'nullable|exists:employee_cost_categories,id',
                'employee_id' => 'nullable|exists:employees,id',
                'amount' => 'required|numeric|min:0',
                'cost_date' => 'required|string',
            ]);

            $cost = Cost::findOrFail($id);
            $cost->update($validatedData);
            return response()->json([
                'status' => true,
                'message' => 'Cost updated successfully.',
                'data' => $cost,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update cost.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function destroy($id)
    {
        try {
            $cost = Cost::findOrFail($id);
            $cost->delete();
            return response()->json([
                'status' => true,
                'message' => 'Cost deleted successfully.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete cost.',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
