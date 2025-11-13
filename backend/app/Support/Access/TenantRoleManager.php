<?php

namespace App\Support\Access;

use App\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantRoleManager
{
    public function __construct(
        protected PermissionRegistrar $registrar
    ) {
    }

    public function ensurePermissions(): void
    {
        foreach (RoleMatrix::permissions() as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }
    }

    public function syncTenantRoles(Tenant $tenant): void
    {
        $this->registrar->setPermissionsTeamId($tenant->id);

        foreach (RoleMatrix::rolePermissions() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            // Owner and Admin have all permissions
            if (in_array($roleName, ['Admin', 'Owner'])) {
                $role->syncPermissions(Permission::all());
                continue;
            }

            $role->syncPermissions($permissions);
        }

        $this->registrar->setPermissionsTeamId(null);
    }
}
