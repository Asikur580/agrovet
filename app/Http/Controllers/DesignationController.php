<?php

namespace App\Http\Controllers;

use Exception;
use Mockery\Expectation;
use App\Models\Designation;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    public function index()
    {
        $designations = Designation::all();
        return response()->json([
            'status' => true,
            'message' => 'All designations retrieved successfully',
            'data' => $designations
        ]);
    }
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);

        try {
            $designation = Designation::create([
                'name' => $request->name,
                'slug' => strtolower($request->slug)
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Designation created successfully',
                'data' => $designation
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'status' => false,
                    'message' => 'The name has already been taken.',
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to create designation',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function show($id)
    {
        try {
            $designation = Designation::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Designation retrieved successfully',
                'data' => $designation
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Designation not found',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);

        try {
            $designation = Designation::findOrFail($id);
            $designation->update([
                'name' => $request->name,
                'slug' => strtolower($request->slug)
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Designation updated successfully',
                'data' => $designation
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update designation',
                'error' => $e->getMessage()
            ]);
        }
    }
    
    public function destroy($id)
    {
        try {
            $designation = Designation::findOrFail($id);
            $designation->delete();

            return response()->json([
                'status' => true,
                'message' => 'Designation deleted successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Catch foreign key constraint violation
            if ($e->errorInfo[1] == 1451) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this designation because it is referenced by another record.',
                ]); 
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete designation',
                'error' => $e->getMessage()
            ]);
        }
    }
}
