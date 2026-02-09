<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Services\TimesheetAi\TimesheetIntentParser;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TimesheetIntentParserTest extends TestCase
{
    public function test_parses_portuguese_prompt(): void
    {
        Http::fake([
            '*' => Http::response([
                'response' => json_encode([
                    'intent' => 'create_timesheets',
                    'date_range' => ['type' => 'relative', 'value' => 'last_week'],
                    'schedule' => [
                        ['from' => '09:00', 'to' => '18:00'],
                    ],
                    'project' => 'Mobile',
                    'missing_fields' => [],
                ]),
            ], 200),
        ]);

        $parser = app(TimesheetIntentParser::class);
        $result = $parser->parsePrompt('Criar horas da semana passada das 9 às 18 no projeto Mobile', 'Europe/Lisbon', 'monday');

        $this->assertTrue($result['ok']);
        $this->assertSame('create_timesheets', $result['intent']?->intent);
        $this->assertSame('last_week', $result['intent']?->dateRange?->value);
        $this->assertSame('Mobile', $result['intent']?->project);
    }

    public function test_parses_english_prompt(): void
    {
        Http::fake([
            '*' => Http::response([
                'response' => json_encode([
                    'intent' => 'create_timesheets',
                    'date_range' => ['type' => 'relative', 'value' => 'last_n_workdays', 'count' => 5],
                    'schedule' => [
                        ['from' => '09:00', 'to' => '13:00'],
                        ['from' => '14:00', 'to' => '18:00'],
                    ],
                    'project' => 'ACME',
                    'missing_fields' => [],
                ]),
            ], 200),
        ]);

        $parser = app(TimesheetIntentParser::class);
        $result = $parser->parsePrompt('Create timesheets last 5 workdays 09:00-13:00 and 14:00-18:00 project ACME', 'Europe/Lisbon', 'monday');

        $this->assertTrue($result['ok']);
        $this->assertSame('last_n_workdays', $result['intent']?->dateRange?->value);
        $this->assertSame(5, $result['intent']?->dateRange?->count);
        $this->assertSame('ACME', $result['intent']?->project);
        $this->assertCount(2, $result['intent']?->schedule ?? []);
    }
}
