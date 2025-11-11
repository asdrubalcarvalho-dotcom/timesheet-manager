<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $roles = $user->getRoleNames();
        $permissions = $user->getAllPermissions()->pluck('name');
        // Get managed projects based on project relationships, not Spatie role
        $managedProjects = $user->isProjectManager()
            ? $user->getManagedProjectIds()
            : [];
        
        // Get project memberships with roles
        $projectMemberships = $user->memberRecords()
            ->select('project_id', 'project_role', 'expense_role', 'finance_role')
            ->get()
            ->map(function ($membership) {
                return [
                    'project_id' => $membership->project_id,
                    'project_role' => $membership->project_role,
                    'expense_role' => $membership->expense_role,
                    'finance_role' => $membership->finance_role,
                ];
            });

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'Technician',
                'roles' => $roles,
                'permissions' => $permissions,
                'is_manager' => $user->isProjectManager(), // Based on project relationships
                'is_technician' => $user->hasRole('Technician'),
                'is_admin' => $user->hasRole('Admin'),
                'managed_projects' => $managedProjects,
                'project_memberships' => $projectMemberships,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user();
        
        // Get project memberships with roles
        $projectMemberships = $user->memberRecords()
            ->select('project_id', 'project_role', 'expense_role', 'finance_role')
            ->get()
            ->map(function ($membership) {
                return [
                    'project_id' => $membership->project_id,
                    'project_role' => $membership->project_role,
                    'expense_role' => $membership->expense_role,
                    'finance_role' => $membership->finance_role,
                ];
            });
        
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'Technician',
            'roles' => $user->getRoleNames(), // Spatie roles
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_manager' => $user->isProjectManager(), // Based on project relationships
            'is_technician' => $user->hasRole('Technician'),
            'is_admin' => $user->hasRole('Admin'),
            // Get managed projects based on project relationships, not Spatie role
            'managed_projects' => $user->isProjectManager() 
                ? $user->getManagedProjectIds()
                : [],
            'project_memberships' => $projectMemberships,
        ]);
    }
}