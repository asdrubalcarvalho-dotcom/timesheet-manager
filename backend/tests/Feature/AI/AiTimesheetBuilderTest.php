<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AiAction;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TenantTestCase;

final class AiTimesheetBuilderTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeIntentParsing();
    }

    private function seedTenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0:User,1:Technician,2:Project,3:Task,4:Location}
     */
    private function makeUserWithDeps(string $name, string $email, string $role): array
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]);
        $user->assignRole($role);

        $tech = Technician::create([
            'name' => $name,
            'email' => $email,
            'role' => 'technician',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $project = Project::create([
            'name' => 'Project Alpha',
            'description' => 'Alpha',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task A',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'HQ',
            'country' => 'PRT',
            'city' => 'Lisbon',
            'address' => 'Main St',
            'postal_code' => '1000-000',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        config(['ai.ollama_enabled' => true]);
        app()->forgetInstance(\App\Services\TimesheetAIService::class);
        app()->forgetInstance(\App\Services\TimesheetAi\TimesheetIntentParser::class);

        return [$user, $tech, $project, $task, $location];
    }

    public function test_preview_returns_plan_without_db_writes(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai1@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $beforeTimesheets = Timesheet::count();
        $beforeActions = AiAction::count();

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => '09:00-11:00 Project Alpha',
                'start_date' => '2025-01-15',
                'end_date' => '2025-01-15',
            ]);

        $res->assertOk();
        $res->assertJsonStructure([
            'plan' => [
                'range' => ['start_date', 'end_date'],
                'timezone',
                'days' => [
                    [
                        'date',
                        'work_blocks',
                        'breaks',
                    ],
                ],
            ],
            'warnings',
        ]);

        $this->assertSame($beforeTimesheets, Timesheet::count());
        $this->assertSame($beforeActions, AiAction::count());
    }

    /**
     * @return array<string, array{prompt: string}>
     */
    public static function promptDateRangeProvider(): array
    {
        return [
            'from-to' => [
                'prompt' => 'from 2026-02-10 to 2026-02-14 09:00-11:00 Project Alpha',
            ],
            'to' => [
                'prompt' => '2026-02-10 to 2026-02-14 09:00-11:00 Project Alpha',
            ],
            'dash' => [
                'prompt' => '2026-02-10 - 2026-02-14 09:00-11:00 Project Alpha',
            ],
            'between' => [
                'prompt' => "between 2026-02-10 and 2026-02-14\n09:00-11:00 Project Alpha",
            ],
            'punctuation' => [
                'prompt' => 'from 2026-02-10 to 2026-02-14, 09:00-11:00 Project Alpha',
            ],
        ];
    }

    /**
     * @return array<string, array{prompt: string}>
     */
    public static function promptPortugueseExplicitRangeProvider(): array
    {
        return [
            'de_a' => [
                'prompt' => "Criar timesheets de 2026-02-10 a 2026-02-14 09:00-13:00 e 14:00-18:00 projeto 'Mobile Banking App'",
            ],
            'de_ate' => [
                'prompt' => "Criar timesheets de 2026-02-10 até 2026-02-14 09:00-13:00 e 14:00-18:00 projeto 'Mobile Banking App'",
            ],
        ];
    }

    /**
     * @return array<string, array{prompt: string, start: string, end: string}>
     */
    public static function promptPortugueseRelativeWeekProvider(): array
    {
        return [
            'proxima_semana_accent' => [
                'prompt' => 'Criar timesheets para a próxima semana 09:00-11:00 Project Alpha',
                'start' => '2026-02-09',
                'end' => '2026-02-15',
            ],
            'proxima_semana' => [
                'prompt' => 'Criar timesheets para a proxima semana 09:00-11:00 Project Alpha',
                'start' => '2026-02-09',
                'end' => '2026-02-15',
            ],
            'esta_semana' => [
                'prompt' => 'Criar timesheets esta semana 09:00-11:00 Project Alpha',
                'start' => '2026-02-02',
                'end' => '2026-02-08',
            ],
            'semana_passada' => [
                'prompt' => 'Criar timesheets semana passada 09:00-11:00 Project Alpha',
                'start' => '2026-01-26',
                'end' => '2026-02-01',
            ],
            'ultima_semana' => [
                'prompt' => 'Criar timesheets ultima semana 09:00-11:00 Project Alpha',
                'start' => '2026-01-26',
                'end' => '2026-02-01',
            ],
        ];
    }

    /**
     * @dataProvider promptDateRangeProvider
     */
    public function test_preview_accepts_prompt_date_ranges(string $prompt): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai3@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $beforeTimesheets = Timesheet::count();
        $beforeActions = AiAction::count();

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => $prompt,
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $res->assertJsonStructure([
            'plan' => [
                'range' => ['start_date', 'end_date'],
                'timezone',
                'days' => [
                    [
                        'date',
                        'work_blocks',
                        'breaks',
                    ],
                ],
            ],
            'warnings',
        ]);

        $this->assertSame($beforeTimesheets, Timesheet::count());
        $this->assertSame($beforeActions, AiAction::count());
    }

    /**
     * @dataProvider promptPortugueseExplicitRangeProvider
     */
    public function test_preview_accepts_portuguese_explicit_date_ranges(string $prompt): void
    {
        $this->seedTenant();

        [$user, $tech, $baseProject, $baseTask, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai7.pt@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = Project::create([
            'name' => 'Mobile Banking App',
            'description' => 'Mobile',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task Mobile',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => $prompt,
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $res->assertJsonPath('plan.range.start_date', '2026-02-10');
        $res->assertJsonPath('plan.range.end_date', '2026-02-14');
        $res->assertJsonPath('plan.days.0.work_blocks.0.project.name', 'Mobile Banking App');
    }

    public function test_preview_accepts_curly_quoted_project_names(): void
    {
        $this->seedTenant();

        [$user, $tech, $baseProject, $baseTask, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai8.pt@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = Project::create([
            'name' => 'Mobile Banking App',
            'description' => 'Mobile',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task Mobile',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-14 Criar timesheets 09:00-13:00 e 14:00-18:00 projeto “Mobile Banking App”',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $res->assertJsonPath('plan.days.0.work_blocks.0.project.name', 'Mobile Banking App');
    }

    /**
     * @dataProvider promptPortugueseRelativeWeekProvider
     */
    public function test_preview_accepts_portuguese_relative_weeks(string $prompt, string $start, string $end): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-08 10:00:00', 'Europe/Lisbon'));
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai3.pt@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $this->tenant->settings = array_merge($this->tenant->settings ?? [], [
            'week_start' => 'monday',
        ]);
        $this->tenant->saveQuietly();

        try {
            $res = $this->withHeaders($this->tenantHeaders())
                ->postJson('/api/ai/timesheet/preview', [
                    'prompt' => $prompt,
                    'timezone' => 'Europe/Lisbon',
                ]);

            $this->assertSame(200, $res->status(), json_encode($res->json()));
            $res->assertJsonStructure([
                'plan' => [
                    'range' => ['start_date', 'end_date'],
                    'timezone',
                    'days' => [
                        [
                            'date',
                            'work_blocks',
                            'breaks',
                        ],
                    ],
                ],
                'warnings',
            ]);
            $res->assertJsonPath('plan.range.start_date', $start);
            $res->assertJsonPath('plan.range.end_date', $end);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_preview_supports_multiple_schedule_blocks_next_week_weekdays(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-08 10:00:00', 'Europe/Lisbon'));
        $this->seedTenant();

        [$user, $tech, $baseProject, $baseTask, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai12.pt@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = Project::create([
            'name' => 'Mobile Banking App',
            'description' => 'Mobile',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task Mobile',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Sanctum::actingAs($user);

        try {
            $res = $this->withHeaders($this->tenantHeaders())
                ->postJson('/api/ai/timesheet/preview', [
                    'prompt' => "Criar timesheets para a próxima semana Seg-Sex 09:00-13:00 e 14:00-18:00 projeto 'Mobile Banking App'",
                    'timezone' => 'Europe/Lisbon',
                ]);

            $this->assertSame(200, $res->status(), json_encode($res->json()));
            $res->assertJsonCount(5, 'plan.days');
            $res->assertJsonCount(2, 'plan.days.0.work_blocks');
            $res->assertJsonPath('plan.days.0.work_blocks.0.start_time', '09:00');
            $res->assertJsonPath('plan.days.0.work_blocks.0.end_time', '13:00');
            $res->assertJsonPath('plan.days.0.work_blocks.1.start_time', '14:00');
            $res->assertJsonPath('plan.days.0.work_blocks.1.end_time', '18:00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_preview_accepts_multiline_labeled_prompt_blocks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-08 10:00:00', 'Europe/Lisbon'));
        $this->seedTenant();

        [$user, $tech, $baseProject, $baseTask, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai13.pt@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = Project::create([
            'name' => 'Mobile Banking App',
            'description' => 'Mobile',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'iOS App Development',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Http::fake([
            '*' => Http::response([
                'response' => json_encode([
                    'intent' => 'create_timesheets',
                    'date_range' => ['type' => 'relative', 'value' => 'next_week'],
                    'schedule' => [
                        ['from' => '09:00', 'to' => '13:00'],
                        ['from' => '14:00', 'to' => '18:00'],
                    ],
                    'missing_fields' => ['project'],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        try {
            $res = $this->withHeaders($this->tenantHeaders())
                ->postJson('/api/ai/timesheet/preview', [
                    'prompt' => "Projeto: \"Mobile Banking App\"\nTarefa: \"iOS App Development\"\nDescricao: \"Sprint 12\"\nPeriodo: proxima semana\nDias: Seg-Sex\nBloco 1: 09:00-13:00\nBloco 2: 14:00-18:00",
                    'timezone' => 'Europe/Lisbon',
                ]);

            $this->assertSame(200, $res->status(), json_encode($res->json()));
            $res->assertJsonCount(5, 'plan.days');
            $res->assertJsonCount(2, 'plan.days.0.work_blocks');
            $res->assertJsonPath('plan.days.0.work_blocks.0.project.name', 'Mobile Banking App');
            $res->assertJsonPath('plan.days.0.work_blocks.0.start_time', '09:00');
            $res->assertJsonPath('plan.days.0.work_blocks.0.end_time', '13:00');
            $res->assertJsonPath('plan.days.0.work_blocks.1.start_time', '14:00');
            $res->assertJsonPath('plan.days.0.work_blocks.1.end_time', '18:00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_preview_accepts_builder_style_prompt_with_date_range(): void
    {
        $this->seedTenant();

        [$user, $tech, $baseProject, $baseTask, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai14.pt@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = Project::create([
            'name' => 'Mobile Banking App',
            'description' => 'Mobile',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'iOS App Development',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Http::fake([
            '*' => Http::response([
                'response' => json_encode([
                    'intent' => 'create_timesheets',
                    'date_range' => ['type' => 'absolute', 'from' => '2026-02-10', 'to' => '2026-02-14'],
                    'schedule' => [
                        ['from' => '14:00', 'to' => '18:00'],
                    ],
                    'missing_fields' => ['project'],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => "DATE_RANGE=2026-02-10..2026-02-14\nProjeto: \"Mobile Banking App\"\nBloco 1: 09:00–13:00\nBloco 2: 14:00–18:00",
                'timezone' => 'Europe/Lisbon',
            ]);

        $this->assertSame(200, $res->status(), json_encode($res->json()));
        $res->assertJsonMissing(['missing_fields']);
        $res->assertJsonCount(5, 'plan.days');
        $days = $res->json('plan.days');
        $this->assertIsArray($days);

        foreach ($days as $day) {
            $this->assertSame(2, count($day['work_blocks'] ?? []));

            $ranges = array_map(static function (array $block): string {
                return sprintf('%s-%s', $block['start_time'] ?? '', $block['end_time'] ?? '');
            }, $day['work_blocks'] ?? []);

            sort($ranges);
            $this->assertSame(['09:00-13:00', '14:00-18:00'], $ranges);

            foreach ($day['work_blocks'] ?? [] as $block) {
                $projectName = $block['project']['name'] ?? $block['project_name'] ?? null;
                $this->assertSame('Mobile Banking App', $projectName);
            }
        }
    }

    public function test_preview_accepts_date_range_token(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai4@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-14 Create timesheets 09:00-11:00 Project Alpha',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $res->assertJsonPath('plan.range.start_date', '2026-02-10');
        $res->assertJsonPath('plan.range.end_date', '2026-02-14');
    }

    public function test_preview_rejects_missing_date_range(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai5@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => '09:00-11:00 Project Alpha',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('missing_fields.0', 'date_range');
    }

    public function test_preview_overlaps_existing_entries_with_time(): void
    {
        $this->seedTenant();

        [$user, $tech, $project, $task, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai10@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Timesheet::create([
            'technician_id' => $tech->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2026-02-10',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'hours_worked' => 1.0,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-10 09:30-10:30 Project Alpha',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertStatus(422);
        $message = (string) $res->json('message');
        $this->assertSame('Overlaps with existing entry on 2026-02-10.', $message);
        $this->assertStringNotContainsString('existing entries without time', $message);
    }

    public function test_preview_allows_existing_entries_with_time_no_overlap(): void
    {
        $this->seedTenant();

        [$user, $tech, $project, $task, $location] = $this->makeUserWithDeps(
            'Technician',
            'tech.ai11@example.com',
            'Technician'
        );
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Timesheet::create([
            'technician_id' => $tech->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2026-02-10',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'hours_worked' => 1.0,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-10 09:30-10:30 Project Alpha',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $message = (string) $res->json('message');
        $this->assertStringNotContainsString('existing entries without time', $message);
    }

    private function fakeIntentParsing(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/generate')) {
                $prompt = (string) (($request->data()['prompt'] ?? '') ?: '');
                $intent = $this->buildIntentFromPrompt($prompt);
                return Http::response([
                    'response' => json_encode($intent, JSON_UNESCAPED_UNICODE),
                ], 200);
            }

            return Http::response(['response' => ''], 200);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIntentFromPrompt(string $prompt): array
    {
        $intent = [
            'intent' => 'create_timesheets',
            'missing_fields' => [],
        ];

        $schedule = [];
        if (preg_match_all('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $prompt, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $schedule[] = ['from' => $match[1], 'to' => $match[2]];
            }
        }

        $project = null;
        if (str_contains($prompt, 'Mobile Banking App')) {
            $project = 'Mobile Banking App';
        } elseif (str_contains($prompt, 'Project Alpha')) {
            $project = 'Project Alpha';
        }

        $dateRange = null;
        if (preg_match('/DATE_RANGE\s*=\s*(\d{4}-\d{2}-\d{2})\s*\.\.\s*(\d{4}-\d{2}-\d{2})/i', $prompt, $rangeMatch)) {
            $dateRange = [
                'type' => 'absolute',
                'from' => $rangeMatch[1],
                'to' => $rangeMatch[2],
            ];
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2}).*?(\d{4}-\d{2}-\d{2})/s', $prompt, $rangeMatch)) {
            $dateRange = [
                'type' => 'absolute',
                'from' => $rangeMatch[1],
                'to' => $rangeMatch[2],
            ];
        } else {
            $lower = mb_strtolower($prompt, 'UTF-8');
            if (str_contains($lower, 'next week') || str_contains($lower, 'próxima semana') || str_contains($lower, 'proxima semana')) {
                $dateRange = ['type' => 'relative', 'value' => 'next_week'];
            } elseif (str_contains($lower, 'this week') || str_contains($lower, 'esta semana')) {
                $dateRange = ['type' => 'relative', 'value' => 'this_week'];
            } elseif (str_contains($lower, 'last week') || str_contains($lower, 'semana passada') || str_contains($lower, 'ultima semana')) {
                $dateRange = ['type' => 'relative', 'value' => 'last_week'];
            }
        }

        if (!$dateRange) {
            $intent['missing_fields'][] = 'date_range';
        } else {
            $intent['date_range'] = $dateRange;
        }

        if (empty($schedule)) {
            $intent['missing_fields'][] = 'schedule';
        } else {
            $intent['schedule'] = $schedule;
        }

        if (!$project) {
            $intent['missing_fields'][] = 'project';
        } else {
            $intent['project'] = $project;
        }

        return $intent;
    }

    /**
     * @return array<string, array{prompt: string}>
     */
    public static function promptProjectProvider(): array
    {
        return [
            'project_colon_quoted' => [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-14 Create timesheets 09:00-13:00 project: "Project Alpha"',
            ],
            'project_unquoted' => [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-14 Create timesheets 09:00-13:00 project Project Alpha',
            ],
            'project_after_and_time' => [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-14 Create timesheets 09:00-13:00 and 14:00-18:00 project: "Project Alpha"',
            ],
            'projeto_colon_quoted' => [
                'prompt' => 'DATE_RANGE=2026-02-10..2026-02-14 Criar timesheets 09:00-13:00 projeto: "Project Alpha"',
            ],
        ];
    }

    /**
     * @dataProvider promptProjectProvider
     */
    public function test_preview_resolves_project_variants(string $prompt): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai6@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => $prompt,
                'timezone' => 'Europe/Lisbon',
            ]);

        $this->assertSame(200, $res->status(), json_encode($res->json()));
        $res->assertJsonPath('plan.days.0.work_blocks.0.project.name', 'Project Alpha');
    }

    public function test_commit_is_idempotent(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai2@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $preview = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'from 2025-01-15 to 2025-01-15 09:00-11:00 Project Alpha',
                'timezone' => 'Europe/Lisbon',
            ]);

        $preview->assertOk();
        $plan = $preview->json('plan');

        $requestId = 'req-123';

        $first = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/commit', [
                'request_id' => $requestId,
                'confirmed' => true,
                'plan' => $plan,
            ]);

        $first->assertOk();
        $createdIds = $first->json('created_ids');

        $this->assertIsArray($createdIds);
        $this->assertNotEmpty($createdIds);
        $this->assertSame(count($createdIds), Timesheet::count());
        $this->assertSame(1, AiAction::count());

        $second = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/commit', [
                'request_id' => $requestId,
                'confirmed' => true,
                'plan' => $plan,
            ]);

        $second->assertOk();
        $second->assertJson([
            'created_ids' => $createdIds,
        ]);

        $this->assertSame(count($createdIds), Timesheet::count());
        $this->assertSame(1, AiAction::count());
    }

    public function test_preview_falls_back_when_intent_parser_fails(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai9.pt@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Http::fake([
            '*' => Http::response(['response' => ''], 500),
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'Criar timesheets para a próxima semana 09:00-13:00 e 14:00-18:00 projeto Project Alpha tarefa QA descricao Revisao',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $res->assertJsonPath('plan.days.0.work_blocks.0.project.name', 'Project Alpha');
    }

    public function test_preview_falls_back_for_portuguese_last_workdays(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Technician', 'tech.ai10.pt@example.com', 'Technician');
        $user->givePermissionTo('create-timesheets');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Http::fake([
            '*' => Http::response(['response' => ''], 500),
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/timesheet/preview', [
                'prompt' => 'Criar timesheets para os ultimos 5 dias uteis 09:00-13:00 e 14:00-18:00 projeto Project Alpha (pausa 1h)',
                'timezone' => 'Europe/Lisbon',
            ]);

        $res->assertOk();
        $res->assertJsonPath('plan.days.0.work_blocks.0.project.name', 'Project Alpha');
    }
}
