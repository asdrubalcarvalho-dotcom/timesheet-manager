<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('countries')) {
            return;
        }

        if (!DB::getSchemaBuilder()->hasTable('locations')) {
            return;
        }

        // Minimal canonical seed set (covers existing demo seeders)
        $seedCountries = [
            ['name' => 'Portugal', 'iso2' => 'PT'],
            ['name' => 'Spain', 'iso2' => 'ES'],
            ['name' => 'France', 'iso2' => 'FR'],
            ['name' => 'Germany', 'iso2' => 'DE'],
        ];

        foreach ($seedCountries as $seed) {
            DB::table('countries')->updateOrInsert(
                ['iso2' => $seed['iso2']],
                ['name' => $seed['name'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $iso3ToIso2 = [
            'PRT' => 'PT',
            'ESP' => 'ES',
            'FRA' => 'FR',
            'DEU' => 'DE',
        ];

        $nameToIso2 = [
            'PORTUGAL' => 'PT',
            'SPAIN' => 'ES',
            'FRANCE' => 'FR',
            'GERMANY' => 'DE',
        ];

        // Load existing countries for quick lookup
        $countryIdByIso2 = DB::table('countries')->pluck('id', 'iso2')->all();

        DB::table('locations')
            ->select(['id', 'country', 'country_id'])
            ->orderBy('id')
            ->chunkById(200, function ($locations) use ($iso3ToIso2, $nameToIso2, &$countryIdByIso2) {
                foreach ($locations as $location) {
                    if (!empty($location->country_id)) {
                        continue;
                    }

                    $raw = trim((string) ($location->country ?? ''));
                    if ($raw === '') {
                        continue;
                    }

                    $upper = strtoupper($raw);

                    $iso2 = null;
                    $shouldNormalizeLocationCountry = false;

                    // If location already stores ISO-2, reuse it.
                    if (strlen($upper) === 2 && ctype_alpha($upper)) {
                        $iso2 = $upper;
                    } elseif (isset($iso3ToIso2[$upper])) {
                        $iso2 = $iso3ToIso2[$upper];
                        $shouldNormalizeLocationCountry = true;
                    } elseif (isset($nameToIso2[$upper])) {
                        $iso2 = $nameToIso2[$upper];
                        $shouldNormalizeLocationCountry = true;
                    }

                    if ($iso2 === null) {
                        // Unknown / non-mappable value: keep legacy country text, and leave country_id null.
                        continue;
                    }

                    $countryId = $countryIdByIso2[$iso2] ?? null;
                    if ($countryId === null) {
                        $countryId = DB::table('countries')->insertGetId([
                            'name' => $iso2,
                            'iso2' => $iso2,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $countryIdByIso2[$iso2] = $countryId;
                    }

                    $update = [
                        'country_id' => $countryId,
                        'updated_at' => now(),
                    ];

                    // Safe normalization for known legacy ISO-3 / known names.
                    // This fixes 422s in Travels where the UI derives country choices from locations.
                    if ($shouldNormalizeLocationCountry && $raw !== $iso2) {
                        $update['country'] = $iso2;
                    }

                    DB::table('locations')->where('id', $location->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        // Intentionally no destructive rollback for backfill.
        // countries/location links are additive and should not delete user data.
    }
};
