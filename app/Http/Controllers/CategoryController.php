<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // Fetch all categories
    public function index()
    {
        $categories = Category::all();

        return response()->json([
            'status' => true,
            'message' => 'All categories retrieved successfully',
            'data' => $categories
        ]);
    }

    // Fetch a single category by ID
    public function show($id)
    {
        try {
            $category = Category::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Category retrieved successfully',
                'data' => $category
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Store a newly created category
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $category = Category::create(['name' => $request->name]);

            return response()->json([
                'status' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Update the specified category
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $category = Category::findOrFail($id);
            $category->update(['name' => $request->name]);

            return response()->json([
                'status' => true,
                'message' => 'Category updated successfully',
                'data' => $category
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update category',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Remove the specified category
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->delete();

            return response()->json([
                'status' => true,
                'message' => 'Category deleted successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle foreign key constraint violation
            if ($e->errorInfo[1] == 1451) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this category because it is referenced by another record.'
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete category',
                'error' => $e->getMessage()
            ]);
        }
    }
}
