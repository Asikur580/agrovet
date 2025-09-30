<?php

namespace App\Http\Controllers;

use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index()
    {
        try {
            $roles = Role::all();
            return response()->json([
                'status' => true,
                'message' => 'Roles retrieved successfully',
                'data' => $roles,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve roles',
                'error' => $e->getMessage(),
            ]);
        }
    }


    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'unique:roles,name'
                ]
            ]);

            $role = Role::create([
                'name' => $request->name,
                //'guard_name' => 'web',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Role created successfully',
                'data' => $role,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create role',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Role retrieved successfully',
                'data' => $role,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve role',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'unique:roles,name,' . $role->id
                ]
            ]);

            $role->update([
                'name' => $request->name,
                //'guard_name' => 'web',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Role updated successfully',
                'data' => $role,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update role',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function destroy($id)
    {
        // dd("hello");
        try {
            $role = Role::findOrFail($id);
            $role->delete();

            return response()->json([
                'status' => true,
                'message' => 'Role deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete role',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function addPermissionToRole(Request $request, $roleId)
    {
        try {
            $permissions = Permission::all();
            $role = Role::findOrFail($roleId);
            $rolePermissions = DB::table('role_has_permissions')
                ->where('role_has_permissions.role_id', $role->id)
                ->pluck('role_has_permissions.permission_id', 'role_has_permissions.permission_id')
                ->all();
            $currentUserId = $request->user()->id;
            $user = User::findOrFail(3);
            $userPermissions = $user->getDirectPermissions()->pluck('id')->toArray();
            return response()->json([
                'status' => true,
                'message' => 'Permissions for the role retrieved successfully',
                'role' => $role,
                'permissions' => $permissions,
                'rolePermissions' => $rolePermissions,
                'userPermissions' => $userPermissions,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve role permissions',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function givePermissionToRole(Request $request, $roleId)
    {
        try {
            $request->validate([
                'permission' => 'required|array',
            ]);

            // Specify the guard explicitly
            $role = Role::where('id', $roleId)
                ->where('guard_name', 'sanctum') // Ensure the guard matches
                ->firstOrFail();
            $role->syncPermissions($request->permission);
            $currentUserId = $request->user()->id;
            $user = User::findOrFail(3);
            $user->syncPermissions($request->permission);

            return response()->json([
                'status' => true,
                'message' => 'Permissions successfully assigned to the role',
                'data' => $role,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to assign permissions to the role',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
