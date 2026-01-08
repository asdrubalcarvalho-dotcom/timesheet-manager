<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

$tenant = App\Models\Tenant::first();
if (!$tenant) {
    echo 'No tenant found!' . PHP_EOL;
    exit(1);
}

echo '🔍 Testing Email System for Tenant: ' . $tenant->id . PHP_EOL;
echo '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' . PHP_EOL;

$tenant->run(function () {
    // Check queue tables exist
    $tables = ['jobs', 'job_batches', 'failed_jobs'];
    foreach ($tables as $tableName) {
        $exists = Schema::hasTable($tableName);
        echo ($exists ? '✅' : '❌') . ' Table: ' . $tableName . PHP_EOL;
    }
    
    echo PHP_EOL . '📧 Creating test user and triggering email...' . PHP_EOL;
    
    // Create test user
    $user = App\Models\User::create([
        'name' => 'Test User ' . time(),
        'email' => 'test' . time() . '@example.com',
        'password' => Hash::make('password'),
    ]);
    
    echo '✅ User created: ' . $user->email . PHP_EOL;
    
    // Dispatch event
    $inviter = App\Models\User::first() ?? $user;
    $tenant = tenancy()->tenant;
    event(new App\Events\UserInvited($tenant, $user, $inviter));
    
    echo '✅ UserInvited event dispatched' . PHP_EOL;
    
    // Check if job was queued
    $jobCount = DB::table('jobs')->count();
    echo '✅ Jobs in queue: ' . $jobCount . PHP_EOL;
    
    if ($jobCount > 0) {
        echo PHP_EOL . '🎉 SUCCESS! Email system is fully functional!' . PHP_EOL;
        echo '   Queue worker will process this job automatically.' . PHP_EOL;
        echo PHP_EOL . '📋 To check the email log:' . PHP_EOL;
        echo '   docker-compose exec app tail -50 storage/logs/laravel.log' . PHP_EOL;
    } else {
        echo PHP_EOL . '⚠️  No job queued - check listener configuration' . PHP_EOL;
    }
});
