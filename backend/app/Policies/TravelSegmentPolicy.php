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
        if (!$user->can('view-timesheets')) {
            return false;
        }

        if (!$travelSegment->project->isUserMember($user)) {
            return false;
        }

        $technician = $user->technician;
        if ($technician && $travelSegment->technician_id === $technician->id) {
            return true;
        }

        if ($travelSegment->project->isUserProjectManager($user)) {
            if ($travelSegment->technician && $travelSegment->technician->user) {
                $ownerRole = $travelSegment->project->getUserProjectRole($travelSegment->technician->user);
                return $ownerRole === 'member';
            }
            return true;
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
        // Completed or cancelled segments cannot be edited
        if (in_array($travelSegment->status, ['completed', 'cancelled'])) {
            return false;
        }

        if (!$travelSegment->project->isUserMember($user)) {
            return false;
        }

        $technician = $user->technician;
        if ($technician && $travelSegment->technician_id === $technician->id) {
            return true;
        }

        if ($travelSegment->project->isUserProjectManager($user)) {
            if ($travelSegment->technician && $travelSegment->technician->user) {
                $ownerRole = $travelSegment->project->getUserProjectRole($travelSegment->technician->user);
                return $ownerRole === 'member';
            }
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can delete the travel segment.
     */
    public function delete(User $user, TravelSegment $travelSegment): bool
    {
        // Completed segments cannot be deleted
        if ($travelSegment->status === 'completed') {
            return false;
        }

        if (!$travelSegment->project->isUserMember($user)) {
            return false;
        }

        $technician = $user->technician;
        if ($technician && $travelSegment->technician_id === $technician->id) {
            return true;
        }

        if ($travelSegment->project->isUserProjectManager($user)) {
            if ($travelSegment->technician && $travelSegment->technician->user) {
                $ownerRole = $travelSegment->project->getUserProjectRole($travelSegment->technician->user);
                return $ownerRole === 'member';
            }
            return true;
        }

        return false;
    }
}
