<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use App\Models\EmployeeCostCategory;

class EmployeeCostCategoryController extends Controller
{
    // Display a listing of the resource
    public function index()
    {
        $employeeCostCategories = EmployeeCostCategory::all();
        return response()->json([
            'status' => true,
            'message' => 'All employee cost categories retrieved successfully',
            'data' => $employeeCostCategories
        ]);
    }

    public function store(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
            ]);

            $employeeCostCategory = EmployeeCostCategory::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Employee Cost category created successfully',
                'data' => $employeeCostCategory,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create employee cost category',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show($id)
    {
        try {
            $employeeCostCategory = EmployeeCostCategory::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Employee cost category retrieved successfully',
                'data' => $employeeCostCategory
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve employee cost category',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        try {

            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
            ]);

            $employeeCostCategory = EmployeeCostCategory::findOrFail($id);
            $employeeCostCategory->update($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Employee cost category updated successfully',
                'data' => $employeeCostCategory
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update employee cost category',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function destroy($id)
    {
        try {
            $employeeCostCategory = EmployeeCostCategory::findOrFail($id);
            $employeeCostCategory->delete();

            return response()->json([
                'status' => true,
                'message' => 'Employee cost category deleted successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle foreign key constraint violation
            if ($e->errorInfo[1] == 1451) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this employee cost category because it is referenced by another record.'
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete employee cost category',
                'error' => $e->getMessage()
            ]);
        }
    }
}
