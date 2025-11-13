<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:list {--status= : Filter by status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all tenants with their details';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Tenant::with('domains');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Slug', 'Name', 'Status', 'Plan', 'Owner Email', 'Domains', 'Created'],
            $tenants->map(fn ($t) => [
                substr($t->id, 0, 8) . '...',
                $t->slug,
                $t->name,
                $t->status,
                $t->plan,
                $t->owner_email,
                $t->domains->pluck('domain')->implode(', '),
                $t->created_at->format('Y-m-d H:i'),
            ])
        );

        $this->info("Total: {$tenants->count()} tenants");

        return Command::SUCCESS;
    }
}
