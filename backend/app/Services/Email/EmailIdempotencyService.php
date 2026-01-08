<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\DB;

class EmailIdempotencyService
{
    /**
     * Attempts to acquire an idempotency key in the TENANT database.
     * Returns true if the key was newly created, false if it already exists.
     */
    public function acquire(string $key): bool
    {
        $affected = DB::connection('tenant')
            ->table('email_idempotency_keys')
            ->insertOrIgnore([
                'key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    public function exists(string $key): bool
    {
        return DB::connection('tenant')
            ->table('email_idempotency_keys')
            ->where('key', $key)
            ->exists();
    }

    public function existsWithPrefix(string $prefix): bool
    {
        return DB::connection('tenant')
            ->table('email_idempotency_keys')
            ->where('key', 'like', $prefix . '%')
            ->exists();
    }
}
