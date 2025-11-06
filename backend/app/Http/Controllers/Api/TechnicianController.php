<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class TechnicianController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $technicians = Technician::orderBy('name')->get();
        return response()->json($technicians);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:technicians',
            'role' => ['required', Rule::in(['technician', 'manager'])],
            'hourly_rate' => 'nullable|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        $technician = Technician::create($validated);
        return response()->json($technician, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Technician $technician): JsonResponse
    {
        return response()->json($technician);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Technician $technician): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'email' => ['email', Rule::unique('technicians')->ignore($technician->id)],
            'role' => [Rule::in(['technician', 'manager'])],
            'hourly_rate' => 'nullable|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        $technician->update($validated);
        return response()->json($technician);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Technician $technician): JsonResponse
    {
        $technician->delete();
        return response()->json(['message' => 'Technician deleted successfully']);
    }
}
