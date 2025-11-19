<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'tenant_slug' => 'required|string',
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // Find tenant by slug
        $tenant = Tenant::where('slug', $request->tenant_slug)->first();

        if (!$tenant) {
            throw ValidationException::withMessages([
                'tenant_slug' => ['The specified workspace does not exist.'],
            ]);
        }

        // Initialize tenant context and execute login within tenant database
        return $tenant->run(function () use ($request, $tenant) {
            // MANUAL DATABASE CONNECTION (DatabaseTenancyBootstrapper disabled)
            $databaseName = $tenant->getInternal('db_name');
            config(['database.connections.tenant.database' => $databaseName]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            DB::setDefaultConnection('tenant');
            
            \Log::info('Login attempt', [
                'email' => $request->email,
                'database' => $databaseName,
                'connection' => DB::getDefaultConnection(),
            ]);
            
            $user = User::where('email', $request->email)->first();
            
            \Log::info('User lookup result', [
                'user_found' => $user !== null,
                'user_id' => $user?->id,
                'user_email' => $user?->email,
            ]);

            if (!$user || !Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            $token = $user->createToken("tenant-{$tenant->id}", ["tenant:{$tenant->id}"])->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => $this->formatUserResponse($user),
            ]);
        });
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
        return response()->json($this->formatUserResponse($request->user()));
    }

    protected function formatUserResponse(User $user): array
    {
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

        $tenant = tenant();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'Technician',
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_owner' => $user->hasRole('Owner'),
            'is_manager' => $user->isProjectManager(),
            'is_technician' => $user->hasRole('Technician'),
            'is_admin' => $user->hasRole('Admin') || $user->hasRole('Owner'),
            'managed_projects' => $user->isProjectManager()
                ? $user->getManagedProjectIds()
                : [],
            'project_memberships' => $projectMemberships,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
            ] : null,
        ];
    }
}
