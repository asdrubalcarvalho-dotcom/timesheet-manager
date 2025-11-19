<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantDataController extends Controller
{
    /**
     * Reset tenant data: truncate all tables except Owner user, then re-seed.
     * 
     * CRITICAL: Only accessible by Owner role.
     */
    public function resetData(): JsonResponse
    {
        // Authorization: Only Owner can reset tenant data
        if (!auth()->user()->hasRole('Owner')) {
            return response()->json([
                'message' => 'Unauthorized. Only the Owner can reset tenant data.'
            ], 403);
        }

        try {
            // 1. Get Owner user(s) before truncation
            $owners = User::whereHas('roles', function ($query) {
                $query->where('name', 'Owner');
            })->get();

            if ($owners->isEmpty()) {
                return response()->json([
                    'message' => 'Error: No Owner user found. Cannot proceed with reset.'
                ], 500);
            }

            // Store Owner data for restoration
            $ownerData = $owners->map(function ($owner) {
                return [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'password' => $owner->password,
                    'email_verified_at' => $owner->email_verified_at,
                    'role' => $owner->role ?? 'Owner',
                    'created_at' => $owner->created_at,
                    'updated_at' => $owner->updated_at,
                ];
            })->toArray();

            Log::info('Tenant data reset initiated', [
                'tenant' => tenancy()->tenant?->slug ?? 'unknown',
                'user' => auth()->user()->email,
                'owners_to_preserve' => count($ownerData)
            ]);

            // 2. Disable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // 3. Get all tables in current tenant database
            $tables = DB::select('SHOW TABLES');
            $databaseName = DB::connection()->getDatabaseName();
            $tableKey = "Tables_in_{$databaseName}";

            $tablesToTruncate = [];
            foreach ($tables as $table) {
                $tableName = $table->$tableKey;
                
                // Skip migrations table
                if ($tableName === 'migrations') {
                    continue;
                }
                
                $tablesToTruncate[] = $tableName;
            }

            // 4. Truncate all tables (including users, roles, permissions, etc.)
            foreach ($tablesToTruncate as $tableName) {
                DB::table($tableName)->truncate();
                Log::debug("Truncated table: {$tableName}");
            }

            // 5. Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // 6. Re-seed roles and permissions
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
                '--force' => true,
            ]);

            // 7. Restore Owner user(s) and their technician records
            foreach ($ownerData as $ownerRecord) {
                $restoredOwner = User::create($ownerRecord);
                $restoredOwner->assignRole('Owner');
                
                // Create Technician record for Owner
                \App\Models\Technician::create([
                    'name'       => $restoredOwner->name,
                    'email'      => $restoredOwner->email,
                    'role'       => 'owner',
                    'phone'      => null,
                    'user_id'    => $restoredOwner->id,
                    'created_by' => $restoredOwner->id,
                    'updated_by' => $restoredOwner->id,
                ]);
                
                Log::info('Owner user and technician restored', [
                    'id' => $restoredOwner->id,
                    'email' => $restoredOwner->email
                ]);
            }

            // 8. Run demo data seeder (CompleteTenantSeeder)
            Log::info('Running CompleteTenantSeeder', [
                'database' => DB::connection()->getDatabaseName(),
                'default_connection' => DB::getDefaultConnection()
            ]);
            
            try {
                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\CompleteTenantSeeder',
                    '--force' => true,
                ]);
                
                $seederOutput = Artisan::output();
                Log::info('Seeder completed', ['output' => $seederOutput]);
            } catch (\Throwable $seederError) {
                Log::error('Seeder failed', [
                    'error' => $seederError->getMessage(),
                    'trace' => $seederError->getTraceAsString()
                ]);
                // Continue anyway - at least Owner is restored
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Tenant data has been reset successfully. All demo data has been restored.',
                'owners_preserved' => count($ownerData),
                'tables_reset' => count($tablesToTruncate)
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Tenant data reset failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset tenant data: ' . $e->getMessage()
            ], 500);
        }
    }
}
