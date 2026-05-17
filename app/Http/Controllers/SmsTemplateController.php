<?php

namespace App\Http\Controllers;

use App\Models\SmsTemplate;
use Illuminate\Http\Request;
use Exception;

class SmsTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $templates = SmsTemplate::orderBy('id', 'desc')->get();
            return response()->json([
                'status' => true,
                'message' => 'SMS Templates retrieved successfully.',
                'data' => $templates
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve templates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'boolean'
            ]);

            $template = SmsTemplate::create([
                'name' => $validatedData['name'],
                'content' => $validatedData['content'],
                'status' => $validatedData['status'] ?? true
            ]);

            return response()->json([
                'status' => true,
                'message' => 'SMS Template created successfully.',
                'data' => $template
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $template = SmsTemplate::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'SMS Template retrieved successfully.',
                'data' => $template
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found or error occurred: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'boolean'
            ]);

            $template = SmsTemplate::findOrFail($id);
            $template->update($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'SMS Template updated successfully.',
                'data' => $template
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $template = SmsTemplate::findOrFail($id);
            $template->delete();

            return response()->json([
                'status' => true,
                'message' => 'SMS Template deleted successfully.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete template: ' . $e->getMessage()
            ], 500);
        }
    }
}
