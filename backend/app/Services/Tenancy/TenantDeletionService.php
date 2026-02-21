<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TenantDeletionService
{
    public function deleteTenantFully(string $tenantIdOrSlug): array
    {
        $tenant = Tenant::query()
            ->where('slug', $tenantIdOrSlug)
            ->orWhere('id', $tenantIdOrSlug)
            ->first();

        $tenantId = $tenant?->id;
        $tenantSlug = $tenant?->slug ?? $tenantIdOrSlug;
        $ownerEmail = $tenant?->owner_email;
        $tenantDbName = $this->resolveTenantDatabaseName($tenant);

        Log::info('tenant.delete.start', [
            'tenant_id' => $tenantId,
            'tenant_slug' => $tenantSlug,
            'database' => $tenantDbName,
        ]);

        $central = DB::connection('mysql');
        $central->transaction(function () use ($tenant, $tenantId, $tenantSlug, $ownerEmail): void {
            $this->deleteCentralReferences($tenantId, $tenantSlug, $ownerEmail);

            if ($tenant) {
                $tenant->delete();
            }
        });

        $databaseDropped = $this->dropTenantDatabase($tenantDbName);

        Log::info('tenant.delete.completed', [
            'tenant_id' => $tenantId,
            'tenant_slug' => $tenantSlug,
            'database' => $tenantDbName,
            'database_dropped' => $databaseDropped,
        ]);

        return [
            'tenant_id' => $tenantId,
            'slug' => $tenantSlug,
            'database' => $tenantDbName,
            'tenant_found' => (bool) $tenant,
            'database_dropped' => $databaseDropped,
        ];
    }

    private function deleteCentralReferences(?string $tenantId, ?string $tenantSlug, ?string $ownerEmail): void
    {
        $tablesByTenantId = [
            'domains',
            'companies',
            'subscriptions',
            'billing_profiles',
            'billing_invoices',
            'billing_payment_failures',
            'payments',
            'plan_change_history',
            'subscription_plan_history',
            'tenant_metrics_daily',
            'admin_actions',
        ];

        if ($tenantId) {
            foreach ($tablesByTenantId as $table) {
                if (Schema::connection('mysql')->hasTable($table)) {
                    DB::connection('mysql')->table($table)->where('tenant_id', $tenantId)->delete();
                }
            }
        }

        $tablesBySlug = [
            'billing_invoices' => 'tenant_slug',
            'billing_payment_failures' => 'tenant_slug',
        ];

        if ($tenantSlug) {
            foreach ($tablesBySlug as $table => $column) {
                if (Schema::connection('mysql')->hasTable($table)) {
                    DB::connection('mysql')->table($table)->where($column, $tenantSlug)->delete();
                }
            }

            if (Schema::connection('mysql')->hasTable('pending_tenant_signups')) {
                $query = DB::connection('mysql')->table('pending_tenant_signups')
                    ->where('slug', $tenantSlug);

                if ($ownerEmail) {
                    $query->orWhere('admin_email', $ownerEmail);
                }

                $query->delete();
            }
        }
    }

    private function resolveTenantDatabaseName(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        $dbName = (string) $tenant->getInternal('db_name');
        if ($dbName !== '') {
            return $dbName;
        }

        return config('tenancy.database.prefix', 'timesheet_')
            . (string) $tenant->id
            . config('tenancy.database.suffix', '');
    }

    private function dropTenantDatabase(?string $databaseName): bool
    {
        if (! $databaseName) {
            return false;
        }

        $centralConnection = DB::connection('mysql');
        if ($centralConnection->getDriverName() !== 'mysql') {
            return false;
        }

        $centralDatabase = (string) config('database.connections.mysql.database');
        if ($databaseName === $centralDatabase) {
            Log::warning('tenant.delete.skip_drop_central', [
                'database' => $databaseName,
            ]);
            return false;
        }

        $safeDbName = str_replace('`', '', $databaseName);
        $safeDbName = preg_replace('/[^A-Za-z0-9_]/', '', $safeDbName) ?? '';
        if ($safeDbName === '') {
            Log::warning('tenant.delete.invalid_database_name', [
                'database' => $databaseName,
            ]);
            return false;
        }

        $databases = $centralConnection->select('SHOW DATABASES LIKE ?', [$safeDbName]);
        if (empty($databases)) {
            return false;
        }

        $centralConnection->statement("DROP DATABASE IF EXISTS `{$safeDbName}`");

        Log::info('tenant.db_dropped', [
            'database' => $safeDbName,
        ]);

        return true;
    }
}
