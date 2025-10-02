<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Customer;
use App\Models\Relation;
use App\Models\Employee;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
{
    try {
        $user = $request->user(); // logged-in user

        $customersQuery = Customer::with('employee:id,name');

        if ($user->employee->designation->slug == 'admin') {
            // Admin সব customer দেখবে
            $customers = $customersQuery->get();
        } elseif ($user->employee->designation->slug == 'officer') {
            // Officer শুধু নিজের customer
            $customers = $customersQuery->where('employee_id', $user->employee->id)->get();
        
        } elseif ($user->employee->designation->slug == 'manager') {
            // Manager এর under থাকা officer এর customer
            $officerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');
            $customers = $customersQuery->whereIn('employee_id', $officerIds)->get();
        } elseif ($user->employee->designation->slug == 'rsm') {
            // RS এর under থাকা manager + তাদের officer এর customer
            $managerIds = Relation::where('relation_id', $user->employee->id)->pluck('employee_id');
            $officerIds = Relation::whereIn('relation_id', $managerIds)->pluck('employee_id');
            $allEmployeeIds = $managerIds->merge($officerIds);
            $customers = $customersQuery->whereIn('employee_id', $allEmployeeIds)->get();
        } else {
            // অন্য কেউ দেখবে না
            $customers = collect();
        }

        return response()->json([
            'status' => true,
            'message' => 'Customers retrieved successfully',
            'data' => count($customers),
        ]);

    } catch (Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Failed to retrieve customers',
            'error' => $e->getMessage(),
        ]);
    }
}

    
    // public function customerByEmployee($id)
    // {
    //     try {
    //         $customers = Customer::where('employee_id',$id)->get();

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'customers by employee retrieved successfully',
    //             'data' => $customers,
    //         ]);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Failed to retrieve customers',
    //             'error' =>  $e->getMessage()
    //         ]);
    //     }
    // }
    
    public function store(Request $request)
    {
        try {
            // Validate the incoming data
            $validatedData = $request->validate([
                'employee_id' => 'required|integer|exists:employees,id',
                'customer_name' => 'required|string|max:255',
                'proprietor_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'address' => 'required|string|max:255'
            ]);


            // Fetch the employee and their designation
            $employee = Employee::with('designation')->findOrFail($validatedData['employee_id']);

            // Check if the designation is 'Officer'
            if ($employee->designation->slug !== 'officer') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only employees with the Officer designation can be associated with customers.',
                ]);
            }

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate a unique name for the image
                $imageName = 'customer_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the image in the 'customers' directory within the 'public' disk
                $imagePath = $image->storeAs('customers', $imageName, 'public');

                // Save the image path to the validated data
                $validatedData['image'] = $imagePath;
            }

            // Create the customer
            $customer = Customer::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Customer created successfully',
                'data' => $customer,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create customer',
                'error' => $e->getMessage(),
            ]);
        }
    }
    public function show($id)
    {
        try {
            $customer = Customer::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Customer retrieved successfully',
                'data' => $customer,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve customer',
                'error' => $e->getMessage()
            ]);
        }
    }
    public function update(Request $request, $id)
    {
        try {
            // Validate the incoming data
            $validatedData = $request->validate([
                'employee_id' => 'required|integer|exists:employees,id',
                'customer_name' => 'required|string|max:255',
                'proprietor_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20', // Exclude the current record
                'address' => 'required|string|max:255'
            ]);

            // Fetch the employee and their designation
            $employee = Employee::with('designation')->findOrFail($validatedData['employee_id']);

            // Check if the designation is 'Officer'
            if ($employee->designation->slug !== 'officer') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only employees with the Officer designation can be associated with customers.',
                ]);
            }

            // Find the customer by ID
            $customer = Customer::findOrFail($id);

            // Handle the image upload if provided
            if ($request->hasFile('image')) {
                // Delete the old image if it exists
                if ($customer->image && file_exists(storage_path('app/public/' . $customer->image))) {
                    unlink(storage_path('app/public/' . $customer->image));
                }

                $image = $request->file('image');

                // Generate a unique name for the image
                $imageName = 'customer_' . time() . '.' . $image->getClientOriginalExtension();

                // Store the image in the 'customers' directory within the 'public' disk
                $imagePath = $image->storeAs('customers', $imageName, 'public');

                // Save the image path to the validated data
                $validatedData['image'] = $imagePath;
            }

            // Update the customer with the validated data
            $customer->update($validatedData);

            // Return a successful response
            return response()->json([
                'status' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ]);
        }
    }
    public function destroy($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            // Delete the old image if it exists
            if ($customer->image && file_exists(storage_path('app/public/' . $customer->image))) {
                unlink(storage_path('app/public/' . $customer->image));
            }
            $customer->delete();

            return response()->json([
                'status' => true,
                'message' => 'Customer deleted successfully',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451) { // Foreign key constraint violation
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete this customer because it is referenced by another record.',
                ]);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ]);
        }
    }
}
