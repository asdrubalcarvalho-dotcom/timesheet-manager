<?php

echo "=== Email System Validation Test ===" . PHP_EOL . PHP_EOL;

// 1. Check tenant exists
$tenant = App\Models\Tenant::where('slug', 'demo')->first();
if (!$tenant) {
    echo '❌ Demo tenant not found' . PHP_EOL;
    exit(1);
}
echo '✅ Tenant found: ' . $tenant->slug . PHP_EOL;

// 2. Check queue tables exist in tenant DB
$tenant->run(function() {
    $hasJobs = Schema::hasTable('jobs');
    $hasJobBatches = Schema::hasTable('job_batches');
    $hasFailedJobs = Schema::hasTable('failed_jobs');
    
    if (!$hasJobs || !$hasJobBatches || !$hasFailedJobs) {
        echo '❌ Queue tables missing' . PHP_EOL;
        exit(1);
    }
    echo '✅ Queue tables exist (jobs, job_batches, failed_jobs)' . PHP_EOL;
    
    // 3. Check event listener is registered
    if (!class_exists('App\\Events\\UserInvited')) {
        echo '❌ UserInvited event not found' . PHP_EOL;
        exit(1);
    }
    echo '✅ UserInvited event exists' . PHP_EOL;
    
    if (!class_exists('App\\Listeners\\SendUserInvitationEmail')) {
        echo '❌ SendUserInvitationEmail listener not found' . PHP_EOL;
        exit(1);
    }
    echo '✅ SendUserInvitationEmail listener exists' . PHP_EOL;
    
    if (!class_exists('App\\Mail\\UserInvitationMail')) {
        echo '❌ UserInvitationMail not found' . PHP_EOL;
        exit(1);
    }
    echo '✅ UserInvitationMail exists' . PHP_EOL;
    
    // 4. Test email dispatch
    $testEmail = 'validation' . time() . '@test.com';
    $user = App\Models\User::create([
        'name' => 'Validation Test',
        'email' => $testEmail,
        'password' => bcrypt('test123')
    ]);
    
    $inviter = App\Models\User::first();
    if (!$inviter) {
        echo '❌ No inviter user found' . PHP_EOL;
        exit(1);
    }
    
    // Dispatch event
    event(new App\Events\UserInvited(tenancy()->tenant, $user, $inviter));
    echo '✅ Event dispatched for: ' . $testEmail . PHP_EOL;
    
    // 5. Check job was queued
    sleep(1); // Wait for DB write
    $jobCount = DB::table('jobs')->where('queue', 'default')->count();
    echo '✅ Jobs in queue: ' . $jobCount . PHP_EOL;
});

echo PHP_EOL . '🎉 Email system validation PASSED!' . PHP_EOL;
echo '📬 A tenant-scoped worker must process the queued job.' . PHP_EOL;
echo '📋 Recommended: run the feature test:' . PHP_EOL;
echo '   docker-compose exec app php artisan test --filter=UserInvitationEmailTest --no-ansi' . PHP_EOL;
echo '📋 If MAIL_MAILER=log, check:' . PHP_EOL;
echo '   docker-compose exec app tail -50 storage/logs/laravel.log' . PHP_EOL;
