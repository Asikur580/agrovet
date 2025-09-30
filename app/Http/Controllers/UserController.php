<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;


class UserController extends Controller
{
    public function login(Request $request)
    {
        
        try {
            // Validate the request
            $request->validate([
                'email' => 'required|string|email|max:255',
                'password' => 'required|string|min:6',
            ]);

            // Attempt to authenticate the user
            if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ]);
            }

            // Retrieve the authenticated user
            $user = User::with('employee.designation')->where('email', $request->email)->firstOrFail();

            // Check if the user's status is 'active'
            if ($user->status !== 'active') {
                return response()->json([
                    'status' => false,
                    'message' => 'Account is not active. Please contact support.',
                ]);
            }

            // Fetch the permissions for the user based on their roles
            //$rolePermissions = $user->getPermissionsViaRoles()->pluck('name');
            $userPermissions = $user->getDirectPermissions()->pluck('name');

            // Generate a token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return a successful response with the token
            return response()->json([
                'status' => true,
                'token' => $token,
                'message' => 'Login Successful',
                'user' => $user,
                // 'roles' => $userRoles,
                'permissions' => $userPermissions,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ]);
        }
    }


    public function logout(Request $request)
    {
        try {
            // Revoke the current user's token
            $request->user()->currentAccessToken()->delete();

            // Return a successful response
            return response()->json([
                'status' => true,
                'message' => 'Logout successful',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request)
    {
        try {
            $currentUserId = $request->user()->id; // Get the logged-in user's ID

            // Fetch all active users except the logged-in user
            $users = User::where('status', 'active')
                //->where('id', '!=', $currentUserId) // Exclude the logged-in user
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Active users retrieved successfully',
                'data' => $users,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ]);
        }
    }


    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            // Validate incoming data
            $validatedData = $request->validate([
                'employee_id' => 'required|integer|exists:employees,id|unique:users,employee_id',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
                'permission' => 'required|array', // Ensure roles is an array
                // 'roles' => 'required|array', // Ensure roles is an array
                // 'roles.*' => 'exists:roles,name', // Validate each role exists in the roles table
            ]);

            // Create the user
            $user = User::create([
                'employee_id' => $validatedData['employee_id'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
            ]);

            // Assign permissions to the user         
            $user->syncPermissions($request->permission);

            DB::commit();
            return response()->json([
                'status' => true,
                'message' => 'User created successfully',
                'data' => $user,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $permissions = Permission::where('name','!=','Developer')->get();
            $userPermissions = $user->permissions->pluck('id', 'id')->all();
            return response()->json([
                'status' => true,
                'message' => 'User retrieved successfully',
                'user' => $user,
                'permissions' => $permissions,
                'userPermissions' => $userPermissions
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'User not found', 'error' => $e->getMessage()]);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $validatedData = $request->validate([
                'email' => 'required|email|unique:users,email,' . $id,
                'password' => 'nullable|string|min:6',
                'permission' => 'required|array',
            ]);

            $user = User::findOrFail($id);
            $user->update([
                'email' => $validatedData['email'],
                'password' => $validatedData['password'] ? Hash::make($validatedData['password']) : $user->password,
            ]);

            $user->syncPermissions($request->permission);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'User updated successfully', 'data' => $user]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            $currentUserId = $request->user()->id;
            $user = User::findOrFail($id);

            // Prevent the currently logged-in user from deactivating themselves
            if ($currentUserId == $id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot deactivate yourself.',
                ]);
            }

            // Ensure the user model allows mass assignment for 'status'
            $user->update(['status' => 'deactive']);

            return response()->json([
                'status' => true,
                'message' => 'User deactivated successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
