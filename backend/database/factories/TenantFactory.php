<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->company;

        return [
            'id' => (string) Str::ulid(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . $this->faker->unique()->numerify('####'),
            'owner_email' => $this->faker->unique()->safeEmail,
            'status' => 'active',
            'plan' => 'standard',
            'timezone' => 'UTC',
            'settings' => [],
            'data' => [],
        ];
    }
}
