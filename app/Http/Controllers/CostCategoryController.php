<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\CostCategory;
use Illuminate\Http\Request;

class CostCategoryController extends Controller
{
    // Display a listing of the resource
    public function index()
    {
        $costCategories = CostCategory::all();
        return response()->json([
            'status' => true,
            'message' => 'All cost categories retrieved successfully',
            'data' => $costCategories
        ]);
    }

    public function store(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
            ]);

            $costCategory = CostCategory::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Cost category created successfully',
                'data' => $costCategory,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create cost category',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show($id)
    {
        try {
            $costCategory = CostCategory::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Cost category retrieved successfully',
                'data' => $costCategory
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve cost category',
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

            $costCategory = CostCategory::findOrFail($id);
            $costCategory->update($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Cost category updated successfully',
                'data' => $costCategory
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update cost category',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function destroy($id)
    {
        try {
            $costCategory = CostCategory::findOrFail($id);
            $costCategory->delete();

            return response()->json([
                'status' => true,
                'message' => 'Cost category deleted successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle foreign key constraint violation
            if ($e->errorInfo[1] == 1451) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this cost category because it is referenced by another record.'
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete cost category',
                'error' => $e->getMessage()
            ]);
        }
    }
}
