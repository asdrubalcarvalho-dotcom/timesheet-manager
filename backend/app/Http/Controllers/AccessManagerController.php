<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AccessManagerController extends Controller
{
    // List all users with roles
    public function listUsers()
    {
        return User::with('roles', 'permissions')->get();
    }

    // List all roles
    public function listRoles()
    {
        return Role::all();
    }

    // Structured permissions index for UI grids
    public function indexPermissions(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->get(['id', 'name', 'guard_name', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'count' => $permissions->count(),
            'data' => $permissions,
        ]);
    }

    // Legacy alias kept for backwards compatibility
    public function listPermissions(): JsonResponse
    {
        return $this->indexPermissions();
    }

    // Assign role to user
    public function assignRole(Request $request, User $user)
    {
        $role = $request->input('role');
        if (!$role) {
            return response()->json(['error' => 'Role required'], 400);
        }
        $user->assignRole($role);
        return response()->json(['success' => true, 'user' => $user->load('roles')]);
    }

    // Remove role from user
    public function removeRole(Request $request, User $user)
    {
        $role = $request->input('role');
        if (!$role) {
            return response()->json(['error' => 'Role required'], 400);
        }
        $user->removeRole($role);
        return response()->json(['success' => true, 'user' => $user->load('roles')]);
    }

    // Assign permission to user
    public function assignPermission(Request $request, User $user)
    {
        $permission = $request->input('permission');
        if (!$permission) {
            return response()->json(['error' => 'Permission required'], 400);
        }
        $user->givePermissionTo($permission);
        return response()->json(['success' => true, 'user' => $user->load('permissions')]);
    }

    // Remove permission from user
    public function removePermission(Request $request, User $user)
    {
        $permission = $request->input('permission');
        if (!$permission) {
            return response()->json(['error' => 'Permission required'], 400);
        }
        $user->revokePermissionTo($permission);
        return response()->json(['success' => true, 'user' => $user->load('permissions')]);
    }
    // Get all permissions for a role
    public function getRolePermissions($role)
    {
        $roleObj = Role::where('name', $role)->first();
        if (!$roleObj) {
            return response()->json(['error' => 'Role not found'], 404);
        }
        $permissions = $roleObj->permissions()->get();
        return response()->json($permissions);
    }

    // Assign permission to a role
    public function assignPermissionToRole(Request $request, $role)
    {
        $permission = $request->input('permission');
        $roleObj = Role::where('name', $role)->first();
        if (!$roleObj || !$permission) {
            return response()->json(['error' => 'Role or permission required'], 400);
        }
        $permObj = Permission::where('name', $permission)->first();
        if (!$permObj) {
            return response()->json(['error' => 'Permission not found'], 404);
        }
        $roleObj->givePermissionTo($permObj);
        return response()->json(['success' => true, 'role' => $roleObj->load('permissions')]);
    }

    // Remove permission from a role
    public function removePermissionFromRole(Request $request, $role)
    {
        $permission = $request->input('permission');
        $roleObj = Role::where('name', $role)->first();
        if (!$roleObj || !$permission) {
            return response()->json(['error' => 'Role or permission required'], 400);
        }
        $permObj = Permission::where('name', $permission)->first();
        if (!$permObj) {
            return response()->json(['error' => 'Permission not found'], 404);
        }
        $roleObj->revokePermissionTo($permObj);
        return response()->json(['success' => true, 'role' => $roleObj->load('permissions')]);
    }
}
