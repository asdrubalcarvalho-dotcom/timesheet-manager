# Queue System Quick Reference

## Important: Queue is Tenant-Scoped

This project uses the **database** queue and jobs are intentionally written to the **tenant database** (e.g. `timesheet_<tenant_id>`).

That means:
- Running `php artisan queue:work ...` with no tenant context will read from the **central** DB (and will not consume tenant jobs).
- In dev/test you must run the worker **explicitly inside the tenant context**.

Related: `docs/EMAIL_SYSTEM.md`.

## Manual Job Processing (Testing Only)

### Process jobs for a specific tenant (recommended)

Run `queue:work` against a specific tenant DB by explicitly configuring the tenant connection in CLI.

```bash
# Process ONE job from the demo tenant queue
docker-compose exec -T app php artisan tinker --execute='$tenant = App\\Models\\Tenant::where("slug", "demo")->first();
if (!$tenant) { echo "Tenant not found\\n"; return; }
\Illuminate\\Support\\Facades\\DB::setDefaultConnection("mysql");
$databaseName = $tenant->getInternal("db_name");
\Illuminate\\Support\\Facades\\Config::set("database.connections.tenant.database", $databaseName);
\Illuminate\\Support\\Facades\\DB::purge("tenant");
\Illuminate\\Support\\Facades\\DB::reconnect("tenant");
\Illuminate\\Support\\Facades\\DB::setDefaultConnection("tenant");
\Illuminate\\Support\\Facades\\Config::set("database.default", "tenant");
\Illuminate\\Support\\Facades\\Artisan::call("queue:work", ["--once" => true, "--queue" => "default"]);
echo \Illuminate\\Support\\Facades\\Artisan::output();'
```

Notes:
- This is intentionally **per-tenant**. If you have multiple tenants, run one worker per tenant when testing.
- The single-quoted `--execute='...'` avoids zsh history expansion issues.

## Testing

For deterministic validation, prefer running the feature tests:

```bash
docker-compose exec app php artisan test --filter=UserInvitationEmailTest --no-ansi
docker-compose exec app php artisan test --filter=BillingPhase3EmailsTest --no-ansi
```

## Queue Inspection

```bash
# Count pending jobs in tenant database
docker-compose exec app php artisan tinker
> $tenant = App\Models\Tenant::first();
> $tenant->run(fn() => DB::table('jobs')->count());

# View all pending jobs
> $tenant->run(fn() => DB::table('jobs')->get());

# Check failed jobs
> $tenant->run(fn() => DB::table('failed_jobs')->get());
```

## Email Testing

### 1. Create Test User via Tinker
```bash
docker-compose exec app php artisan tinker
```

```php
$tenant = App\Models\Tenant::where('slug', 'demo')->first();
$tenant->run(function() {
    $user = App\Models\User::create([
        'name' => 'Test Email ' . time(),
        'email' => 'test' . time() . '@example.com',
        'password' => bcrypt('password123')
    ]);
    
    $inviter = App\Models\User::first(); // Any existing user
    event(new App\Events\UserInvited(tenancy()->tenant, $user, $inviter));
    
    echo "User created: {$user->email}\n";
    echo "Event dispatched - check queue worker logs\n";
});
```

### 2. Check Email in Logs
```bash
# View last 100 lines of Laravel log
docker-compose exec app tail -100 storage/logs/laravel.log

# Search for specific email
docker-compose exec app grep -A 20 "test.*@example.com" storage/logs/laravel.log
```

### 3. Browser Test
1. Go to: `http://demo.localhost:8082`
2. Login as admin
3. Navigate to Users/Technicians
4. Click "Add User"
5. Fill form with test email
6. Submit
7. Process the queued job for the tenant (see “Process jobs for a specific tenant”)
8. Verify email in laravel.log

## Troubleshooting

### Jobs Not Being Processed

**Symptom**: Jobs added to `jobs` table but never processed

**Most common cause**: `queue:work` was run without tenant context (so it read from the central DB).

**Solution**: Run `queue:work` explicitly against the tenant DB (see “Process jobs for a specific tenant”).

### Jobs Table Missing

**Symptom**: Error "Table 'jobs' doesn't exist"

**Solution**:
```bash
# Run migrations for all tenants
docker-compose exec app php artisan tenants:migrate --all

# Or specific tenant
docker-compose exec app php artisan tenants:migrate demo
```

### Failed Jobs

**Check failed jobs**:
```bash
docker-compose exec app php artisan tinker
> $tenant->run(fn() => DB::table('failed_jobs')->get());
```

**Retry failed job**:
```bash
# IMPORTANT: failed jobs are tenant-scoped too.
# Run retries inside the tenant context, otherwise you will target the central DB.

docker-compose exec -T app php artisan tinker
> $tenant = App\\Models\\Tenant::where('slug', 'demo')->first();
> $tenant->run(fn() => Artisan::call('queue:retry', ['id' => 'all']));
> echo Artisan::output();
```

**Clear failed jobs**:
```bash
docker-compose exec -T app php artisan tinker
> $tenant = App\\Models\\Tenant::where('slug', 'demo')->first();
> $tenant->run(fn() => Artisan::call('queue:flush'));
> echo Artisan::output();
```
## Quick Commands Summary

| Task | Command |
|------|---------|
| Process 1 tenant job | Use “Process jobs for a specific tenant” |
| Count pending jobs | Tinker: `$tenant->run(fn() => DB::table('jobs')->count())` |
| View email in log | `docker-compose exec app tail -100 storage/logs/laravel.log` |
| Retry failed jobs | Run `queue:retry` inside `$tenant->run()` |
| Clear failed jobs | Run `queue:flush` inside `$tenant->run()` |
| Migrate tenant queues | `docker-compose exec app php artisan tenants:migrate --all` |
