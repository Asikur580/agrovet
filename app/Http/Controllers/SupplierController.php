<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupplierController extends Controller
{
    // Fetch all suppliers
    public function index()
    {
        $suppliers = Supplier::all();

        return response()->json([
            'status' => true,
            'message' => 'All suppliers retrieved successfully',
            'data' => $suppliers
        ]);
    }

    // Fetch a single supplier by ID
    public function show($id)
    {
        try {
            $supplier = Supplier::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Supplier retrieved successfully',
                'data' => $supplier
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function store(Request $request)
    {

        try {

            $validatedData = $request->validate([
                'proprietor_name' => 'required|string|max:255',
                'company_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|max:255|unique:suppliers,email',
                'whatsapp' => 'nullable|string|max:20',
                'country' => 'required|string|max:255',
                'address' => 'required|string|max:500'
            ]);

            // Handle image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the image
                $imageName = 'supplier_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the image in the 'suppliers' directory within the 'public' disk
                $imagePath = $image->storeAs('suppliers', $imageName, 'public');

                // Save the image path to the validated data
                $validatedData['image'] = $imagePath;
            }

            // Create the supplier
            $supplier = Supplier::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Supplier created successfully',
                'data' => $supplier,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create supplier',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, $id)
    {

        try {
            // Find the supplier by ID
            $supplier = Supplier::findOrFail($id);

            // Validate the incoming request data
            $updatedData = $request->validate([
                'proprietor_name' => 'required|string|max:255',
                'company_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20,' . $supplier->id,
                'email' => 'nullable|email|max:255|unique:suppliers,email,' . $supplier->id,
                'whatsapp' => 'nullable|string|max:20',
                'country' => 'required|string|max:255',
                'address' => 'required|string|max:500'
            ]);

            // Handle image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the new image
                $imageName = 'supplier_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the new image in the 'suppliers' directory within the 'public' disk
                $imagePath = $image->storeAs('suppliers', $imageName, 'public');

                // Delete the old image if it exists
                if ($supplier->image && Storage::disk('public')->exists($supplier->image)) {
                    Storage::disk('public')->delete($supplier->image);
                }

                // Save the new image path to the updated data
                $updatedData['image'] = $imagePath;
            }

            // Update the supplier record
            $supplier->update($updatedData);

            return response()->json([
                'status' => true,
                'message' => 'Supplier updated successfully',
                'data' => $supplier,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update supplier',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function destroy($id)
    {
        try {
            $supplier = Supplier::findOrFail($id);
            // Delete the old image if it exists
            if ($supplier->image && Storage::disk('public')->exists($supplier->image)) {
                Storage::disk('public')->delete($supplier->image);
            }
            $supplier->delete();

            return response()->json([
                'status' => true,
                'message' => 'Supplier deleted successfully',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451) { // Foreign key constraint violation
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this supplier because it is referenced by another record.',
                ]);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
