<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TimesheetAi;

use App\Models\Project;
use App\Models\Technician;
use App\Models\User;
use App\Services\TimesheetAi\TimesheetPlanParser;
use Tests\TenantTestCase;

class TimesheetPlanParserTest extends TenantTestCase
{
    public function test_parser_errors_on_unknown_project(): void
    {
        $user = User::factory()->create();
        $technician = Technician::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Project::create([
            'name' => 'Project A',
            'description' => 'Demo',
            'status' => 'active',
        ]);

        $parser = app(TimesheetPlanParser::class);

        $payload = [
            'prompt' => 'Create entries for last 1 workdays: 09:00-12:00 Project Z',
            'timezone' => 'UTC',
        ];

        $result = $parser->parse($payload, $user, $technician, $user);

        $this->assertNull($result['plan']);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(collect($result['errors'])->contains(fn($msg) => str_contains($msg, 'Project "Project Z" not found.')));
    }
}
