<?php

namespace App\Support\Access;

class RoleMatrix
{
    public static function permissions(): array
    {
        return [
            // Timesheet permissions
            'view-timesheets',
            'create-timesheets',
            'edit-own-timesheets',
            'edit-all-timesheets',
            'approve-timesheets',
            'delete-timesheets',

            // Expense permissions
            'view-expenses',
            'create-expenses',
            'edit-own-expenses',
            'edit-all-expenses',
            'approve-expenses',
            'delete-expenses',

            // Management permissions
            'view-reports',
            'view-projects',
            'view-tasks',
            'view-locations',
            'manage-users',
            'manage-technicians',
            'manage-projects',
            'manage-tasks',
            'manage-locations',
            
            // Billing permissions
            'manage-billing',
        ];
    }

    public static function rolePermissions(): array
    {
        return [
            'Technician' => [
                'view-timesheets',
                'create-timesheets',
                'edit-own-timesheets',
                'view-expenses',
                'create-expenses',
                'edit-own-expenses',
            ],
            'Manager' => [
                'view-timesheets',
                'create-timesheets',
                'edit-own-timesheets',
                'edit-all-timesheets',
                'approve-timesheets',
                'view-expenses',
                'create-expenses',
                'edit-own-expenses',
                'edit-all-expenses',
                'approve-expenses',
                'view-reports',
                'view-projects',
                'view-tasks',
                'view-locations',
            ],
            'Admin' => self::permissions(),
            'Owner' => self::permissions(), // Owner has all permissions
        ];
    }
}
