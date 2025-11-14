<?php

namespace App\Providers;

use App\Models\Timesheet;
use App\Models\Expense;
use App\Models\TravelSegment;
use App\Policies\TimesheetPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\TravelSegmentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Timesheet::class => TimesheetPolicy::class,
        Expense::class => ExpensePolicy::class,
        TravelSegment::class => TravelSegmentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}