<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PendingTenantSignup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PlanManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Subscription;

class TenantProvisioningService
{
    /**
     * Provision (or resume provisioning) for an already-created tenant.
     *
     * Idempotent: safe to call multiple times.
     */
    public function provisionFromPendingSignup(Tenant $tenant, PendingTenantSignup $pendingSignup): Tenant
    {
        $tenant->refresh();

        $previousDefaultConnection = DB::getDefaultConnection();

        $databaseName = $tenant->getInternal('db_name');

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            Artisan::call('tenants:migrate', ['tenant' => $tenant->id]);
        } catch (\Exception $e) {
            \Log::error('Failed to run tenant migrations (resume)', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Ensure trial subscription exists (central)
        if (!Subscription::where('tenant_id', $tenant->id)->exists()) {
            $planManager = app(PlanManager::class);
            $planManager->startTrialForTenant($tenant);
        }

        // Configure tenant connection
        config(['database.connections.tenant' => [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => $databaseName,
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'unix_socket' => config('database.connections.mysql.unix_socket'),
            'charset' => config('database.connections.mysql.charset'),
            'collation' => config('database.connections.mysql.collation'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => config('database.connections.mysql.options', []),
        ]]);

        try {
            $tenant->run(function () use ($pendingSignup, $tenant, $databaseName) {
                config(['database.connections.tenant.database' => $databaseName]);
                DB::purge('tenant');
                DB::reconnect('tenant');
                DB::setDefaultConnection('tenant');

                Artisan::call('migrate', [
                    '--path'  => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
                    '--force' => true,
                ]);

                if (!Company::where('tenant_id', $tenant->id)->exists()) {
                    Company::create([
                        'tenant_id' => $tenant->id,
                        'name'      => $pendingSignup->company_name,
                        'industry'  => $pendingSignup->industry,
                        'country'   => $pendingSignup->country,
                        'timezone'  => $pendingSignup->timezone,
                        'status'    => 'active',
                    ]);
                }

                if (!User::where('email', $pendingSignup->admin_email)->exists()) {
                    $owner = User::create([
                        'name'              => $pendingSignup->admin_name,
                        'email'             => $pendingSignup->admin_email,
                        'password'          => $pendingSignup->password_hash,
                        'email_verified_at' => now(),
                        'role'              => 'Owner',
                    ]);

                    $owner->assignRole('Owner');

                    \App\Models\Technician::create([
                        'name'       => $owner->name,
                        'email'      => $owner->email,
                        'role'       => 'owner',
                        'phone'      => null,
                        'user_id'    => $owner->id,
                        'created_by' => $owner->id,
                        'updated_by' => $owner->id,
                    ]);
                }
            });
        } finally {
            DB::setDefaultConnection($previousDefaultConnection);
        }

        $tenant->refresh();

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['provisioning_status'] = 'active';
        $settings['provisioning_error'] = null;

        $tenant->forceFill([
            'status' => 'active',
            'settings' => $settings,
        ])->save();

        return $tenant->refresh();
    }
}
