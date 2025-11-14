<?php

namespace App\Policies;

use App\Models\TravelSegment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TravelSegmentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view any travel segments.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-timesheets') 
            || $user->hasPermissionTo('view-all-timesheets');
    }

    /**
     * Determine if the user can view a specific travel segment.
     */
    public function view(User $user, TravelSegment $travelSegment): bool
    {
        // Admins and Owners can view all
        if ($user->hasRole(['Admin', 'Owner'])) {
            return true;
        }

        // User can view their own travel segments
        $technician = $user->technician;
        if ($technician && $travelSegment->technician_id === $technician->id) {
            return true;
        }

        // Managers can view travel segments for projects they manage
        if ($user->hasPermissionTo('view-all-timesheets')) {
            $managedProjectIds = $user->managedProjects()->pluck('id')->toArray();
            return in_array($travelSegment->project_id, $managedProjectIds);
        }

        return false;
    }

    /**
     * Determine if the user can create travel segments.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-timesheets');
    }

    /**
     * Determine if the user can update the travel segment.
     */
    public function update(User $user, TravelSegment $travelSegment): bool
    {
        // Admins and Owners can update all
        if ($user->hasRole(['Admin', 'Owner'])) {
            return true;
        }

        // Completed or cancelled segments cannot be edited
        if (in_array($travelSegment->status, ['completed', 'cancelled'])) {
            return false;
        }

        // User can update their own travel segments
        $technician = $user->technician;
        if ($technician && $travelSegment->technician_id === $technician->id) {
            return true;
        }

        // Managers can update travel segments for projects they manage
        if ($user->hasPermissionTo('edit-all-timesheets')) {
            $managedProjectIds = $user->managedProjects()->pluck('id')->toArray();
            return in_array($travelSegment->project_id, $managedProjectIds);
        }

        return false;
    }

    /**
     * Determine if the user can delete the travel segment.
     */
    public function delete(User $user, TravelSegment $travelSegment): bool
    {
        // Admins and Owners can delete all
        if ($user->hasRole(['Admin', 'Owner'])) {
            return true;
        }

        // Completed segments cannot be deleted
        if ($travelSegment->status === 'completed') {
            return false;
        }

        // User can delete their own travel segments
        $technician = $user->technician;
        if ($technician && $travelSegment->technician_id === $technician->id) {
            return true;
        }

        return false;
    }
}
