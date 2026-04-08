<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Employee;
use App\Models\Relation;
use Illuminate\Http\Request;

class RelationController extends Controller
{
    public function getRelatedEmployees(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'employee_id' => 'required|exists:employees,id', // Validate input
            ]);

            // Fetch the employee and their designation
            $employee = Employee::with('designation')->findOrFail($validatedData['employee_id']);
            $designation = $employee->designation->slug; // Assuming 'designation' has a 'name' column

            // Initialize related employees
            $relatedEmployees = null;

            if ($designation === 'officer') {
                // Get all Managers related to this Officer
                $relatedEmployees = Employee::whereHas('designation', function ($query) {
                    $query->where('slug', 'manager');
                })->where('status', 'active')->get();
            } elseif ($designation === 'manager') {
                // Get all RSMs related to this Manager
                $relatedEmployees = Employee::whereHas('designation', function ($query) {
                    $query->where('slug', 'rsm');
                })->where('status', 'active')->get();
            } elseif ($designation === 'rsm') {
                // RSM has no further relations
                $relatedEmployees = null;
            }

            return response()->json([
                'status' => true,
                'message' => 'Related employees retrieved successfully',
                'data' => $relatedEmployees,
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'relation_id' => 'required|exists:employees,id|different:employee_id',
            ]);

            $validatedData['created_by'] = $request->user()->id;

            // Retrieve employee and relation details
            $employee = Employee::find($validatedData['employee_id']);
            $relation = Employee::find($validatedData['relation_id']);

            // Check the roles
            if ($employee->designation->slug == 'officer' && $relation->designation->slug != 'manager') {
                return response()->json(['status' => false, 'message' => 'Relation ID must be a Manager for an Officer.']);
            }

            if ($employee->designation->slug == 'manager' && $relation->designation->slug != 'rsm') {
                return response()->json(['status' => false, 'message' => 'Relation ID must be an RSM for a Manager.']);
            }

            // Check if a relation already exists for the given employee_id
            $relations = Relation::where('employee_id', $validatedData['employee_id'])->first();

            $oldRelationId = null;
            if ($relations) {
                $oldRelationId = $relations->relation_id;
                // Update the existing relation
                $relations->update($validatedData);
                $message = 'Relation updated successfully';
            } else {
                // Create a new relation
                $relations = Relation::create($validatedData);
                $message = 'Relation created successfully';
            }

            // Recalculate new superior's credit limit
            $newSuperior = Employee::with('designation')->find($validatedData['relation_id']);
            if ($newSuperior) {
                $newSuperior->recalculateCreditLimit();
            }

            // If relation changed, recalculate old superior's credit limit too
            if ($oldRelationId && $oldRelationId != $validatedData['relation_id']) {
                $oldSuperior = Employee::with('designation')->find($oldRelationId);
                if ($oldSuperior) {
                    $oldSuperior->recalculateCreditLimit();
                }
            }

            // Create the relation
            //$relations = Relation::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $relation,
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }

    public function getOfficers()
    {
        try {
            // Fetch IDs and names of all employees with the designation 'officer'
            $officers = Employee::whereHas('designation', function ($query) {
                $query->where('slug', 'officer');
            })->select('id', 'name')->where('status', 'active')->get();

            return response()->json([
                'status' => true,
                'message' => 'Officers retrieved successfully',
                'data' => $officers,
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
