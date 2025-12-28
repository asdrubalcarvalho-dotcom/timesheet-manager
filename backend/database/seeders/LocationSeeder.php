<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::setDefaultConnection('tenant');

        $this->call(CountriesSeeder::class);

        $locations = [
            // PORTUGAL - Leiria
            [
                'name' => 'PRT - Instituto Politécnico de Leiria',
                'country' => 'PRT',
                'city' => 'Leiria',
                'address' => 'Rua General Norton de Matos, Apartado 4133',
                'postal_code' => '2411-901',
                'latitude' => 39.7436,
                'longitude' => -8.8071,
                'asset_id' => 1001,
                'oem_id' => 501
            ],
            [
                'name' => 'PRT - Centro Empresarial de Leiria',
                'country' => 'PRT', 
                'city' => 'Leiria',
                'address' => 'Zona Industrial de Leiria, Rua da Indústria 45',
                'postal_code' => '2430-028',
                'latitude' => 39.7649,
                'longitude' => -8.7956,
                'asset_id' => 1002,
                'oem_id' => 502
            ],
            [
                'name' => 'PRT - Parque Tecnológico de Leiria',
                'country' => 'PRT',
                'city' => 'Leiria', 
                'address' => 'Edifício NERLEI, Rua José Luís de Morais',
                'postal_code' => '2400-441',
                'latitude' => 39.7298,
                'longitude' => -8.8205,
                'asset_id' => 1003,
                'oem_id' => 503
            ],
            
            // PORTUGAL - Lisboa
            [
                'name' => 'PRT - Instituto Superior Técnico de Lisboa',
                'country' => 'PRT',
                'city' => 'Lisboa',
                'address' => 'Av. Rovisco Pais 1, Alameda',
                'postal_code' => '1049-001',
                'latitude' => 38.7369,
                'longitude' => -9.1395,
                'asset_id' => 1004,
                'oem_id' => 504
            ],
            [
                'name' => 'PRT - Centro Colombo Business Center',
                'country' => 'PRT',
                'city' => 'Lisboa',
                'address' => 'Av. Lusíada, Edifício Colombo',
                'postal_code' => '1500-392',
                'latitude' => 38.7564,
                'longitude' => -9.1969,
                'asset_id' => 1005,
                'oem_id' => 505
            ],
            [
                'name' => 'PRT - Tagus Park Oeiras',
                'country' => 'PRT',
                'city' => 'Lisboa',
                'address' => 'Núcleo Central 100, Tagus Park',
                'postal_code' => '2740-122',
                'latitude' => 38.7014,
                'longitude' => -9.3147,
                'asset_id' => 1006,
                'oem_id' => 506
            ],
            [
                'name' => 'PRT - Beato Innovation District',
                'country' => 'PRT',
                'city' => 'Lisboa',
                'address' => 'Av. Infante Dom Henrique, Hub Criativo do Beato',
                'postal_code' => '1950-376',
                'latitude' => 38.7458,
                'longitude' => -9.1108,
                'asset_id' => 1007,
                'oem_id' => 507
            ],
            
            // FRANCE - Paris  
            [
                'name' => 'FRA - École Polytechnique Paris',
                'country' => 'FRA',
                'city' => 'Paris',
                'address' => 'Route de Saclay, Palaiseau',
                'postal_code' => '91128',
                'latitude' => 48.7159,
                'longitude' => 2.2069,
                'asset_id' => 2001,
                'oem_id' => 601
            ],
            [
                'name' => 'FRA - La Défense Business Center',
                'country' => 'FRA',
                'city' => 'Paris',
                'address' => '110 Esplanade du Général de Gaulle, La Défense',
                'postal_code' => '92931',
                'latitude' => 48.8889,
                'longitude' => 2.2426,
                'asset_id' => 2002,
                'oem_id' => 602
            ],
            [
                'name' => 'FRA - Station F Startup Campus',
                'country' => 'FRA',
                'city' => 'Paris',
                'address' => '5 Parvis Alan Turing, Halle Freyssinet',
                'postal_code' => '75013',
                'latitude' => 48.8334,
                'longitude' => 2.3723,
                'asset_id' => 2003,
                'oem_id' => 603
            ],
            [
                'name' => 'FRA - Châtelet Innovation Hub',
                'country' => 'FRA',
                'city' => 'Paris',
                'address' => '2 Place du Châtelet, Forum des Halles',
                'postal_code' => '75001',
                'latitude' => 48.8604,
                'longitude' => 2.3469,
                'asset_id' => 2004,
                'oem_id' => 604
            ],
            [
                'name' => 'FRA - Saclay Technology Center',
                'country' => 'FRA',
                'city' => 'Paris',
                'address' => 'Plateau de Saclay, Digiteo Labs',
                'postal_code' => '91190',
                'latitude' => 48.7014,
                'longitude' => 2.1758,
                'asset_id' => 2005,
                'oem_id' => 605
            ],
            
            // SPAIN - Madrid
            [
                'name' => 'ESP - Universidad Politécnica de Madrid',
                'country' => 'ESP',
                'city' => 'Madrid',
                'address' => 'Calle Ramiro de Maeztu 7, Ciudad Universitaria',
                'postal_code' => '28040',
                'latitude' => 40.4518,
                'longitude' => -3.7295,
                'asset_id' => 3001,
                'oem_id' => 701
            ],
            [
                'name' => 'ESP - AZCA Business District',
                'country' => 'ESP',
                'city' => 'Madrid',
                'address' => 'Paseo de la Castellana 95, Torre Europa',
                'postal_code' => '28046',
                'latitude' => 40.4506,
                'longitude' => -3.6906,
                'asset_id' => 3002,
                'oem_id' => 702
            ],
            [
                'name' => 'ESP - Cuatro Torres Business Area',
                'country' => 'ESP',
                'city' => 'Madrid',
                'address' => 'Paseo de la Castellana 259, Torre Caleido',
                'postal_code' => '28046',
                'latitude' => 40.4756,
                'longitude' => -3.6887,
                'asset_id' => 3003,
                'oem_id' => 703
            ],
            [
                'name' => 'ESP - Parque Científico de Madrid',
                'country' => 'ESP',
                'city' => 'Madrid',
                'address' => 'Campus de Cantoblanco UAM, C/ Faraday 7',
                'postal_code' => '28049',
                'latitude' => 40.5378,
                'longitude' => -3.6896,
                'asset_id' => 3004,
                'oem_id' => 704
            ],
            [
                'name' => 'ESP - Méndez Álvaro Innovation Center',
                'country' => 'ESP',
                'city' => 'Madrid',
                'address' => 'Calle Méndez Álvaro 44, Distrito Tecnológico',
                'postal_code' => '28045',
                'latitude' => 40.3985,
                'longitude' => -3.6789,
                'asset_id' => 3005,
                'oem_id' => 705
            ],
            [
                'name' => 'ESP - Las Rozas Technology Park',
                'country' => 'ESP', 
                'city' => 'Madrid',
                'address' => 'Calle Severo Ochoa 2, Parque Empresarial',
                'postal_code' => '28232',
                'latitude' => 40.4969,
                'longitude' => -3.8736,
                'asset_id' => 3006,
                'oem_id' => 706
            ]
        ];

        foreach ($locations as $locationData) {
            $iso2 = $this->iso2FromLegacyCountry($locationData['country']);
            $countryId = $iso2 ? Country::on('tenant')->where('iso2', $iso2)->value('id') : null;

            Location::on('tenant')->updateOrCreate(
                ['asset_id' => $locationData['asset_id']],
                [
                    'name' => $locationData['name'],
                    // Legacy/display-only (do not remove or repurpose)
                    // Keep legacy column, but prefer ISO2 to stay consistent with canonical Countries.
                    'country' => $iso2 ?? $locationData['country'],
                    // Canonical relation
                    'country_id' => $countryId,
                    'city' => $locationData['city'],
                    'address' => $locationData['address'],
                    'postal_code' => $locationData['postal_code'],
                    'latitude' => $locationData['latitude'],
                    'longitude' => $locationData['longitude'],
                    'oem_id' => $locationData['oem_id'],
                    // Deterministic ~90% active, safe to re-run
                    'is_active' => (($locationData['asset_id'] % 10) !== 0),
                ]
            );
        }

        $this->command->info('✅ Created ' . count($locations) . ' professional locations across 3 countries');
        $this->command->info('   🇵🇹 Portugal: Leiria (3) + Lisboa (4) = 7 locations');
        $this->command->info('   🇫🇷 France: Paris (5) locations');  
        $this->command->info('   🇪🇸 Spain: Madrid (6) locations');
        $this->command->info('   📍 Total: ' . Location::count() . ' locations with coordinates');
    }

    private function iso2FromLegacyCountry(?string $legacyCountry): ?string
    {
        if (!$legacyCountry) {
            return null;
        }

        $normalized = strtoupper(trim($legacyCountry));

        // ISO-2 already
        if (strlen($normalized) === 2) {
            return $normalized;
        }

        // Common ISO-3 legacy values found in seed data
        return match ($normalized) {
            'PRT' => 'PT',
            'ESP' => 'ES',
            'FRA' => 'FR',
            'DEU' => 'DE',
            default => null,
        };
    }
}