<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'tenant_id');

        $this->addTeamColumnToRoles($teamColumn);
        $this->addTeamColumnToModelHasRoles($teamColumn);
        $this->addTeamColumnToModelHasPermissions($teamColumn);
    }

    public function down(): void
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'tenant_id');

        if (Schema::hasColumn('model_has_permissions', $teamColumn)) {
            Schema::table('model_has_permissions', function (Blueprint $table) use ($teamColumn) {
                $table->dropIndex('model_has_permissions_team_foreign_key_index');
                $table->dropConstrainedForeignId($teamColumn);
            });
        }

        if (Schema::hasColumn('model_has_roles', $teamColumn)) {
            Schema::table('model_has_roles', function (Blueprint $table) use ($teamColumn) {
                $table->dropIndex('model_has_roles_team_foreign_key_index');
                $table->dropConstrainedForeignId($teamColumn);
            });
        }

        if (Schema::hasColumn('roles', $teamColumn)) {
            try {
                Schema::table('roles', function (Blueprint $table) {
                    $table->dropUnique('roles_tenant_name_guard_unique');
                });
            } catch (\Throwable $exception) {
                // Already removed
            }

            Schema::table('roles', function (Blueprint $table) {
                $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
            });

            Schema::table('roles', function (Blueprint $table) use ($teamColumn) {
                $table->dropIndex('roles_team_foreign_key_index');
                $table->dropConstrainedForeignId($teamColumn);
            });
        }
    }

    protected function addTeamColumnToRoles(string $column): void
    {
        if (! Schema::hasColumn('roles', $column)) {
            Schema::table('roles', function (Blueprint $table) use ($column) {
                $table->foreignUlid($column)
                    ->nullable()
                    ->after('id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();
                $table->index($column, 'roles_team_foreign_key_index');
            });
        }

        try {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_guard_name_unique');
            });
        } catch (\Throwable $exception) {
            // Already dropped
        }

        Schema::table('roles', function (Blueprint $table) use ($column) {
            $table->unique([$column, 'name', 'guard_name'], 'roles_tenant_name_guard_unique');
        });
    }

    protected function addTeamColumnToModelHasRoles(string $column): void
    {
        if (! Schema::hasColumn('model_has_roles', $column)) {
            Schema::table('model_has_roles', function (Blueprint $table) use ($column) {
                $table->foreignUlid($column)
                    ->nullable()
                    ->after('model_id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();
                $table->index($column, 'model_has_roles_team_foreign_key_index');
            });
        }

        $this->backfillModelHasRoles($column);
    }

    protected function addTeamColumnToModelHasPermissions(string $column): void
    {
        if (! Schema::hasColumn('model_has_permissions', $column)) {
            Schema::table('model_has_permissions', function (Blueprint $table) use ($column) {
                $table->foreignUlid($column)
                    ->nullable()
                    ->after('model_id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();
                $table->index($column, 'model_has_permissions_team_foreign_key_index');
            });
        }

        $this->backfillModelHasPermissions($column);
    }

    protected function backfillModelHasRoles(string $column): void
    {
        if (! Schema::hasColumn('model_has_roles', $column)) {
            return;
        }

        $defaultTenantId = DB::table('tenants')->value('id');

        if (! $defaultTenantId) {
            return;
        }

        $rows = DB::table('model_has_roles')
            ->whereNull($column)
            ->get(['role_id', 'model_id', 'model_type']);

        foreach ($rows as $row) {
            $tenantId = $this->resolveTenantIdForModel($row->model_type, (int) $row->model_id) ?? $defaultTenantId;

            DB::table('model_has_roles')
                ->where('role_id', $row->role_id)
                ->where('model_id', $row->model_id)
                ->where('model_type', $row->model_type)
                ->update([$column => $tenantId]);
        }
    }

    protected function backfillModelHasPermissions(string $column): void
    {
        if (! Schema::hasColumn('model_has_permissions', $column)) {
            return;
        }

        $defaultTenantId = DB::table('tenants')->value('id');

        if (! $defaultTenantId) {
            return;
        }

        $rows = DB::table('model_has_permissions')
            ->whereNull($column)
            ->get(['permission_id', 'model_id', 'model_type']);

        foreach ($rows as $row) {
            $tenantId = $this->resolveTenantIdForModel($row->model_type, (int) $row->model_id) ?? $defaultTenantId;

            DB::table('model_has_permissions')
                ->where('permission_id', $row->permission_id)
                ->where('model_id', $row->model_id)
                ->where('model_type', $row->model_type)
                ->update([$column => $tenantId]);
        }
    }

    protected function resolveTenantIdForModel(?string $modelType, int $modelId): ?string
    {
        if ($modelType === \App\Models\User::class) {
            return DB::table('users')
                ->where('id', $modelId)
                ->value('tenant_id');
        }

        return null;
    }
};
