<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use App\Models\User;
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
                ->where('is_active', 1) // Only show active technicians
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
                ->where('is_active', 1) // Only show active technicians
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
                ->where('is_active', 1) // Only show active technicians
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
            ->where('is_active', 1) // Only show if active
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('email', $user->email);
            })
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
     * 
     * If a technician with the same email exists but is inactive, reactivate them instead of creating new.
     */
    public function store(Request $request): JsonResponse
    {
        // First, check if an INACTIVE technician with this email already exists
        $existingTechnician = Technician::where('email', $request->email)
            ->where('is_active', 0)
            ->first();

        if ($existingTechnician) {
            // REACTIVATION PATH: User exists but is inactive
            
            // Check license limit before reactivation
            $tenant = tenancy()->tenant;
            if ($tenant) {
                $subscription = $tenant->subscription;
                if ($subscription && $subscription->user_limit > 0) {
                    $currentUserCount = $tenant->run(function () {
                        return Technician::where('is_active', 1)->count();
                    });
                    
                    if ($currentUserCount >= $subscription->user_limit) {
                        return response()->json([
                            'success' => false,
                            'code' => 'user_limit_reached',
                            'message' => "Cannot reactivate user: Your {$subscription->plan} plan allows a maximum of {$subscription->user_limit} active users. You currently have {$currentUserCount}. Please upgrade your plan or deactivate other users.",
                        ], 422);
                    }
                }
            }

            // Update existing technician data with new values
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email',
                'role' => ['nullable', Rule::in(['technician', 'manager'])],
                'hourly_rate' => 'nullable|numeric|min:0',
                'worker_id' => ['nullable', 'string', 'max:64', Rule::unique('technicians', 'worker_id')->ignore($existingTechnician->id)],
                'worker_name' => 'nullable|string|max:255',
                'worker_contract_country' => 'nullable|string|max:255',
            ]);

            $existingTechnician->update([
                'name' => $validated['name'],
                'role' => $validated['role'] ?? $existingTechnician->role,
                'hourly_rate' => $validated['hourly_rate'] ?? $existingTechnician->hourly_rate,
                'worker_id' => $validated['worker_id'] ?? $existingTechnician->worker_id,
                'worker_name' => $validated['worker_name'] ?? $existingTechnician->worker_name,
                'worker_contract_country' => $validated['worker_contract_country'] ?? $existingTechnician->worker_contract_country,
                'is_active' => 1, // Reactivate
            ]);

            // If has user relation, update user name too
            if ($existingTechnician->user) {
                $existingTechnician->user->update(['name' => $validated['name']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'User reactivated successfully',
                'technician' => $existingTechnician->fresh(),
                'reactivated' => true,
            ], 200);
        }

        // CREATION PATH: New user
        
        // Check license limit BEFORE validation to prevent creating user when at limit
        $tenant = tenancy()->tenant;
        if ($tenant) {
            $subscription = $tenant->subscription;
            if ($subscription && $subscription->user_limit > 0) {
                // Count only ACTIVE technicians (matches billing logic)
                $currentUserCount = $tenant->run(function () {
                    return Technician::where('is_active', 1)->count();
                });
                
                if ($currentUserCount >= $subscription->user_limit) {
                    return response()->json([
                        'success' => false,
                        'code' => 'user_limit_reached',
                        'message' => "Cannot create user: Your {$subscription->plan} plan allows a maximum of {$subscription->user_limit} users. You currently have {$currentUserCount}. Please upgrade your plan to add more users.",
                    ], 422);
                }
            }
        }
        
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

        // Create User for the Technician (REQUIRED for billing user count)
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt('password123'), // Default password - should be changed on first login
            'email_verified_at' => now(),
        ]);

        // Assign Technician role
        $user->assignRole('Technician');

        // Create Technician with user_id
        $validated['user_id'] = $user->id;
        $technician = Technician::create($validated);

        return response()->json([
            'success' => true,
            'technician' => $technician,
            'reactivated' => false,
        ], 201);
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
            
            // Sync name and password to User (email and role CANNOT be changed for Owner)
            if ($technician->user) {
                $userUpdates = [];
                
                if (!empty($validated['name'])) {
                    $userUpdates['name'] = $validated['name'];
                }
                
                if (!empty($validated['password'])) {
                    $userUpdates['password'] = bcrypt($validated['password']);
                }
                
                if (!empty($userUpdates)) {
                    $technician->user->update($userUpdates);
                }
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
        
        // Sync changes to associated User if exists
        if ($technician->user) {
            $userUpdates = [];
            
            if (!empty($validated['name'])) {
                $userUpdates['name'] = $validated['name'];
            }
            
            if (!empty($validated['email'])) {
                $userUpdates['email'] = $validated['email'];
            }
            
            if (!empty($validated['password'])) {
                $userUpdates['password'] = bcrypt($validated['password']);
            }
            
            if (!empty($userUpdates)) {
                $technician->user->update($userUpdates);
            }
        }
        
        return response()->json($technician);
    }

    /**
     * Deactivate the specified resource (soft delete).
     * Sets is_active = 0 to preserve historical data and referential integrity.
     * Owner users cannot be deactivated.
     */
    public function destroy(Technician $technician): JsonResponse
    {
        // Prevent deactivation of Owner users
        if ($technician->user && $technician->user->hasRole('Owner')) {
            return response()->json([
                'message' => 'Owner users cannot be deactivated.'
            ], 403);
        }
        
        // Soft delete: set is_active = 0 instead of hard delete
        // This preserves:
        // - Historical data (timesheets, expenses)
        // - Audit trail (created_by, updated_by references)
        // - Referential integrity (foreign keys remain valid)
        $technician->update(['is_active' => 0]);
        
        return response()->json([
            'message' => 'User deactivated successfully',
            'note' => 'User preserved for historical data. Will not count in billing.'
        ]);
    }

    /**
     * Reactivate a deactivated technician.
     * Sets is_active = 1 to restore user access.
     * Requires Admin/Owner role.
     */
    public function reactivate(Technician $technician): JsonResponse
    {
        if ($technician->is_active) {
            return response()->json([
                'message' => 'User is already active.'
            ], 400);
        }

        // Check license limit before reactivation
        $tenant = tenancy()->tenant;
        if ($tenant) {
            $subscription = $tenant->subscription;
            if ($subscription && $subscription->user_limit > 0) {
                $currentUserCount = $tenant->run(function () {
                    return Technician::where('is_active', 1)->count();
                });
                
                if ($currentUserCount >= $subscription->user_limit) {
                    return response()->json([
                        'success' => false,
                        'code' => 'user_limit_reached',
                        'message' => "Cannot reactivate user: Your {$subscription->plan} plan allows a maximum of {$subscription->user_limit} active users. You currently have {$currentUserCount}. Please upgrade your plan or deactivate other users.",
                    ], 422);
                }
            }
        }

        $technician->update(['is_active' => 1]);
        
        return response()->json([
            'message' => 'User reactivated successfully',
            'technician' => $technician
        ]);
    }
}
