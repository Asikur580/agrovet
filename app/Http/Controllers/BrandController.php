<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    // Fetch all brands
    public function index()
    {
        $brands = Brand::all();

        return response()->json([
            'status' => true,
            'message' => 'All brands retrieved successfully',
            'data' => $brands
        ]);
    }
    // Fetch a single brand by ID
    public function show($id)
    {
        try {
            $brand = Brand::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Brand retrieved successfully',
                'data' => $brand
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Brand not found',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Create a new brand
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $brand = Brand::create(['name' => $request->name]);

            return response()->json([
                'status' => true,
                'message' => 'Brand created successfully',
                'data' => $brand
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create brand',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Update the specified brand
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $brand = Brand::findOrFail($id);
            $brand->update(['name' => $request->name]);

            return response()->json([
                'status' => true,
                'message' => 'Brand updated successfully',
                'data' => $brand
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update brand',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Remove the specified brand
    public function destroy($id)
    {
        try {
            $brand = Brand::findOrFail($id);
            $brand->delete();

            return response()->json([
                'status' => true,
                'message' => 'Brand deleted successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle foreign key constraint violation
            if ($e->errorInfo[1] == 1451) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this brand because it is referenced by another record.'
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete brand',
                'error' => $e->getMessage()
            ]);
        }
    }
}
