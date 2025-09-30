<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index()
    {
        try {
            $permissions = Permission::where('name','!=','Developer')->get();
            return response()->json([
                'status' => true,
                'message' => 'Permissions retrieved successfully',
                'data' => $permissions,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve permissions',
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
                    'unique:permissions,name'
                ]
            ]);

            $permission = Permission::create([
                'name' => $request->name,                
                //'guard_name' => 'web',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Permission created successfully',
                'data' => $permission,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create permission',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $permission = Permission::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Permission retrieved successfully',
                'data' => $permission,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve permission',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $permission = Permission::findOrFail($id);
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'unique:permissions,name,' . $permission->id
                ]
            ]);

            $permission->update([
                'name' => $request->name,
                //'guard_name' => 'web',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Permission updated successfully',
                'data' => $permission,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update permission',
                'error' => $e->getMessage(),
            ]);
        }
    }


    public function destroy($id)
    {
        
        try {
            $permission = Permission::findOrFail($id);
            $permission->delete();

            return response()->json([
                'status' => true,
                'message' => 'Permission deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete permission',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
