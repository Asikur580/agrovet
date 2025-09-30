<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Product;
use App\Models\StockInOut;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // Fetch all products
    public function index()
    {
        $products = Product::with(['category', 'brand'])->orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'All products retrieved successfully',
            'data' => $products
        ]);
    }

    // Fetch a single product by ID
    public function show($id)
    {
        try {
            $product = Product::with(['category', 'brand'])->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Product retrieved successfully',
                'data' => $product
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Store a newly created product
    public function store(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'cat_id' => 'required|exists:categories,id',
                'brand_id' => 'required|exists:brands,id',
                'name' => 'required|string|max:255',
                'pack_size' => 'nullable|string|max:255',
                'expire_date' => 'nullable|string|max:255',
                'buy_price' => 'nullable|numeric',
                'sell_price' => 'nullable|numeric',
                'flat_price' => 'nullable|numeric'
            ]);

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the image
                $imageName = 'product_' . $validatedData['cat_id'] . '_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the image with the custom name in the 'employees' directory within the 'public' disk
                $imagePath = $image->storeAs('products', $imageName, 'public');

                // Save the image path to the validated data
                $validatedData['image'] = $imagePath;
            }

            $product = Product::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully',
                'data' => $product
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ]);
        }
    }


    // Update the specified product
    public function update(Request $request, $id)
    {
        try {

            $validatedData = $request->validate([
                'cat_id' => 'required|exists:categories,id',
                'brand_id' => 'required|exists:brands,id',
                'name' => 'required|string|max:255',
                'pack_size' => 'nullable|string|max:255',
                'expire_date' => 'nullable|string|max:255',
                'buy_price' => 'nullable|numeric',
                'sell_price' => 'nullable|numeric',
                'flat_price' => 'nullable|numeric'
            ]);

            $product = Product::findOrFail($id);

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the new image
                $imageName = 'product_' . $validatedData['cat_id'] . '_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the new image in the 'employees' directory within the 'public' disk
                $imagePath = $image->storeAs('products', $imageName, 'public');

                // Delete the old image if it exists
                if ($product->image && Storage::disk('public')->exists($product->image)) {
                    Storage::disk('public')->delete($product->image);
                }

                // Save the new image path to the validated data
                $validatedData['image'] = $imagePath;
            }


            // Update the record with validated data
            $product->update($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ]);
        }
    }

    // Remove the specified product
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->delete();

            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451) { // Foreign key constraint violation
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this product because it is referenced by another record.',
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function stockIn(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                'buy_price' => 'required|string',
                'supplier_id' => 'required|exists:suppliers,id',
                'in_out_date' => 'required|date',
            ]);

            // Create the stock-in record
            $stockIn = StockInOut::create([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'in_out' => 'in', // Specify 'in' for stock in                
                'supplier_id' => $request->supplier_id,
                'buy_price' => $request->buy_price,
                'in_out_date' => $request->in_out_date,
            ]);

            // Update the product's quantity
            $product = Product::find($request->product_id);
            $product->quantity += $request->quantity; // Increase the product quantity
            $product->save(); // Save the changes to the database

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Stock added successfully.',
                'data' => $stockIn,
                'updated_product' => $product
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to add stock.',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function stockOut(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                'purpose' => 'required|string',
                'who_take' => 'required|string',
                'in_out_date' => 'required|date',
            ]);

            // Retrieve the product
            $product = Product::find($request->product_id);

            // Check if the product quantity is sufficient
            if ($product->quantity < $request->quantity) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient stock available.',
                    'current_quantity' => $product->quantity,
                ]);
            }

            // Create the stock-out record
            $stockOut = StockInOut::create([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'in_out' => 'out',
                'purpose' => $request->purpose,
                'who_take' => $request->who_take,
                'in_out_date' => $request->in_out_date,
            ]);

            // Update the product's quantity
            $product->quantity -= $request->quantity; // Decrease the product quantity
            $product->save(); // Save the changes to the database

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Stock removed successfully.',
                'data' => $stockOut,
                'updated_product' => $product
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to remove stock.',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function stockInOutHistory($id)
    {
        try {
            // Fetch stock in-out history for a specific product
            $stockHistory = DB::table('stock_in_outs')
                ->join('products', 'stock_in_outs.product_id', '=', 'products.id')
                ->leftJoin('suppliers', 'stock_in_outs.supplier_id', '=', 'suppliers.id')
                ->where('stock_in_outs.product_id', $id)
                ->orderBy('stock_in_outs.in_out_date', 'desc') // Order by latest transactions
                ->selectRaw("
                stock_in_outs.id,
                products.name as product_name,
                stock_in_outs.quantity,
                stock_in_outs.buy_price,
                stock_in_outs.purpose,
                stock_in_outs.who_take,
                stock_in_outs.in_out, 
                suppliers.company_name as supplier_name,
                stock_in_outs.in_out_date
            ")
                ->get();

            // If no stock history found for the given product
            if ($stockHistory->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No stock in-out history found for this product.',
                    'data' => []
                ]);
            }

            // Success response with stock history data
            return response()->json([
                'status' => true,
                'message' => 'Stock in-out history retrieved successfully',
                'data' => $stockHistory
            ]);
        } catch (Exception $e) {

            // Return a JSON response with error details
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while retrieving stock history.',
                'error' => $e->getMessage() // Optional: Remove in production for security
            ]);
        }
    }
}
