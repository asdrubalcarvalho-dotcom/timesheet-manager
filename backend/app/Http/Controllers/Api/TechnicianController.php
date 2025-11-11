<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class TechnicianController extends Controller
{
        /**
     * Display a listing of technicians visible to the authenticated user.
     * - Regular users: see only themselves
     * - Project Managers: see themselves + ONLY members (not other managers) from their managed projects
     * - Admins: see all technicians (excluding other Admin users)
     * 
     * NOTE: "Project Manager" is determined by project relationships (manager_id or project_members),
     * NOT by Spatie role 'Manager'.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        // Admin can see all technicians, but exclude users with Admin role
        if ($user->hasRole('Admin')) {
            $technicians = Technician::whereHas('user', function($q) {
                $q->whereDoesntHave('roles', function($roleQuery) {
                    $roleQuery->where('name', 'Admin');
                });
            })
            ->orWhereNull('user_id') // Include technicians without user relation
            ->orderBy('name')
            ->get();
            return response()->json(['data' => $technicians]);
        }

        // Project Managers can see themselves + ONLY members (not other managers) from their managed projects
        // Check if user manages any project (via manager_id or project_members)
        if ($user->isProjectManager()) {
            // Get all projects managed by this user
            $managedProjectIds = $user->getManagedProjectIds();
            
            // Get member user IDs from managed projects (ONLY members, exclude managers)
            $memberUserIds = \App\Models\ProjectMember::whereIn('project_id', $managedProjectIds)
                ->where('project_role', 'member') // Only members, not managers
                ->pluck('user_id')
                ->unique()
                ->filter()
                ->toArray();

            // Get technician records for member users + current manager
            $technicians = Technician::where(function($q) use ($memberUserIds, $user) {
                $q->whereIn('user_id', $memberUserIds)
                  ->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get();
            
            return response()->json(['data' => $technicians]);
        }

        // Regular users see only themselves
        $technician = Technician::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        $technicians = $technician ? [$technician] : [];
        return response()->json(['data' => $technicians]);
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
            'is_active' => 'boolean',
            'worker_id' => 'nullable|string|max:64|unique:technicians,worker_id',
            'worker_name' => 'nullable|string|max:255',
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
            'is_active' => 'boolean',
            'worker_id' => ['nullable','string','max:64', Rule::unique('technicians','worker_id')->ignore($technician->id)],
            'worker_name' => 'nullable|string|max:255',
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
