<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountriesSeeder extends Seeder
{
    /**
     * Seed a minimal canonical set of countries.
     *
     * Notes:
     * - Idempotent (safe to re-run)
     * - Never truncates/deletes
     */
    public function run(): void
    {
        // Minimal realistic set (expand as needed). ISO2 must be uppercase + unique.
        $countries = [
            'PT' => 'Portugal',
            'ES' => 'Spain',
            'FR' => 'France',
            'DE' => 'Germany',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'BR' => 'Brazil',
        ];

        foreach ($countries as $iso2 => $name) {
            $iso2 = strtoupper(trim((string) $iso2));

            Country::on('tenant')->updateOrCreate(
                ['iso2' => $iso2],
                ['name' => $name]
            );
        }
    }
}
