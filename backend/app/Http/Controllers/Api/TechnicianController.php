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
     * - Project Managers (project_role='manager'): see themselves + ONLY members (not other managers) from their managed projects
     * - Admins (Spatie System Role): see all technicians (excluding Owner users for privacy)
     * - Owner (Spatie System Role): see ALL users including themselves
     * 
     * NOTE: "Project Manager" is determined by project relationships (manager_id or project_members.project_role),
     * NOT by Spatie role 'Manager'.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        // Owner (system role) can see ALL users including themselves
        if ($user->hasRole('Owner')) {
            $technicians = Technician::with(['user.roles'])
                ->orderBy('name')
                ->get()
                ->map(function ($tech) {
                    $techArray = $tech->toArray();
                    $techArray['is_owner'] = $tech->user && $tech->user->hasRole('Owner');
                    return $techArray;
                });
            return response()->json(['data' => $technicians]);
        }

        // Admin (system role) can see all technicians, but exclude users with Owner role (for privacy)
        if ($user->hasRole('Admin')) {
            $technicians = Technician::with(['user.roles'])
                ->whereHas('user', function($q) {
                    $q->whereDoesntHave('roles', function($roleQuery) {
                        $roleQuery->where('name', 'Owner');
                    });
                })
                ->orWhereNull('user_id') // Include technicians without user relation
                ->orderBy('name')
                ->get()
                ->map(function ($tech) {
                    $techArray = $tech->toArray();
                    $techArray['is_owner'] = false; // Admin can't see owners
                    return $techArray;
                });
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

            // Get technician records for member users + current manager (exclude Owners)
            $technicians = Technician::with(['user.roles'])
                ->where(function($q) use ($memberUserIds, $user) {
                    $q->whereIn('user_id', $memberUserIds)
                      ->orWhere('user_id', $user->id);
                })
                ->whereDoesntHave('user.roles', function($q) {
                    $q->where('name', 'Owner');
                })
                ->orderBy('name')
                ->get()
                ->map(function ($tech) {
                    $techArray = $tech->toArray();
                    $techArray['is_owner'] = false;
                    return $techArray;
                });
            
            return response()->json(['data' => $technicians]);
        }

        // Regular users see only themselves (exclude if Owner)
        $technician = Technician::with(['user.roles'])
            ->where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        $technicians = $technician ? [$technician] : [];
        $technicians = array_map(function($tech) {
            $techArray = is_array($tech) ? $tech : $tech->toArray();
            $techArray['is_owner'] = false;
            return $techArray;
        }, $technicians);
        
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
            'worker_contract_country' => 'nullable|string|max:255',
        ]);

        $technician = Technician::create($validated);
        return response()->json($technician, 201);
    }

    /**
     * Display the specified resource.
     * Follows same visibility rules as index() - Owner sees all, Admin sees non-owners, Managers see team members, Users see self.
     */
    public function show(Technician $technician): JsonResponse
    {
        $user = Auth::user();
        
        // Owner can see all
        if ($user->hasRole('Owner')) {
            return response()->json($technician);
        }
        
        // Admin can see all except Owners
        if ($user->hasRole('Admin')) {
            if ($technician->user && $technician->user->hasRole('Owner')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
            return response()->json($technician);
        }
        
        // Project Managers can see team members + self
        if ($user->isProjectManager()) {
            $managedProjectIds = $user->getManagedProjectIds();
            $memberUserIds = \App\Models\ProjectMember::whereIn('project_id', $managedProjectIds)
                ->where('project_role', 'member')
                ->pluck('user_id')
                ->unique()
                ->filter()
                ->toArray();
            
            if ($technician->user_id === $user->id || in_array($technician->user_id, $memberUserIds)) {
                return response()->json($technician);
            }
            
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        
        // Regular users can only see themselves
        if ($technician->user_id === $user->id || $technician->email === $user->email) {
            return response()->json($technician);
        }
        
        return response()->json(['message' => 'Unauthorized.'], 403);
    }

    /**
     * Update the specified resource in storage.
     * Owner users can only be edited by themselves and only their name.
     */
    public function update(Request $request, Technician $technician): JsonResponse
    {
        $currentUser = Auth::user();
        
        // Check if technician is an Owner
        if ($technician->user && $technician->user->hasRole('Owner')) {
            // Only the Owner themselves can edit
            if ($currentUser->id !== $technician->user_id) {
                return response()->json([
                    'message' => 'Owner users cannot be edited by other users.'
                ], 403);
            }
            
            // Owner can update: name, hourly_rate, worker_id, worker_name, worker_contract_country, password
            $validated = $request->validate([
                'name' => 'string|max:255',
                'hourly_rate' => 'nullable|numeric|min:0',
                'worker_id' => ['nullable','string','max:64', Rule::unique('technicians','worker_id')->ignore($technician->id)],
                'worker_name' => 'nullable|string|max:255',
                'worker_contract_country' => 'nullable|string|max:255',
                'password' => 'nullable|string|min:6',
            ]);
            
            // Update technician fields
            $technician->update(array_filter($validated, fn($key) => $key !== 'password', ARRAY_FILTER_USE_KEY));
            
            // Update password in User model if provided
            if (!empty($validated['password']) && $technician->user) {
                $technician->user->update([
                    'password' => bcrypt($validated['password'])
                ]);
            }
            
            return response()->json($technician);
        }
        
        // Normal users - full validation
        $validated = $request->validate([
            'name' => 'string|max:255',
            'email' => ['email', Rule::unique('technicians')->ignore($technician->id)],
            'role' => [Rule::in(['technician', 'manager'])],
            'hourly_rate' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'worker_id' => ['nullable','string','max:64', Rule::unique('technicians','worker_id')->ignore($technician->id)],
            'worker_name' => 'nullable|string|max:255',
            'worker_contract_country' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
        ]);

        // Update technician fields (exclude password)
        $technician->update(array_filter($validated, fn($key) => $key !== 'password', ARRAY_FILTER_USE_KEY));
        
        // Update password in User model if provided
        if (!empty($validated['password']) && $technician->user) {
            $technician->user->update([
                'password' => bcrypt($validated['password'])
            ]);
        }
        
        return response()->json($technician);
    }

    /**
     * Remove the specified resource from storage.
     * Owner users cannot be deleted.
     */
    public function destroy(Technician $technician): JsonResponse
    {
        // Prevent deletion of Owner users
        if ($technician->user && $technician->user->hasRole('Owner')) {
            return response()->json([
                'message' => 'Owner users cannot be deleted.'
            ], 403);
        }
        
        $technician->delete();
        return response()->json(['message' => 'Technician deleted successfully']);
    }
}
