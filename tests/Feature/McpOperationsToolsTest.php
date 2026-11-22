<?php

namespace Tests\Feature;

use App\Mcp\Authorization\McpToolPermissionRegistry;
use App\Mcp\Server\McpServerFactory;
use App\Mcp\Tools\Operations\OperationsMcpCapabilityRegistrar;
use App\Models\Employee;
use App\Models\Mcp\McpApproval;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\QuarterlyObjective;
use App\Models\Task;
use App\Models\User;
use App\Services\Mcp\McpApprovalService;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpOperationsToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionPath;

    private string $keyPath;

    private User $principal;

    /** @var list<string> */
    private array $scopes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPath = storage_path('framework/testing/mcp-operations-passport');
        File::ensureDirectoryExists($this->keyPath, 0700, true);
        $private = $this->keyPath.'/oauth-private.key';
        $public = $this->keyPath.'/oauth-public.key';
        if (! is_file($private) || ! is_file($public)) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $privateKey));
            $details = openssl_pkey_get_details($key);
            $this->assertIsArray($details);
            file_put_contents($private, $privateKey);
            file_put_contents($public, $details['key']);
            chmod($private, 0600);
            chmod($public, 0600);
        }
        Passport::loadKeysFrom($this->keyPath);

        $this->sessionPath = storage_path('framework/testing/mcp-operations-sessions');
        config()->set('mcp.session_path', $this->sessionPath);
        File::deleteDirectory($this->sessionPath);

        $this->principal = User::factory()->create(['email' => 'mcp-operations@example.test']);
        config()->set('mcp.ai_principal_email', $this->principal->email);
        $this->scopes = ['mcp:use', ...array_keys((new McpToolPermissionRegistry)->passportScopes())];

        app()->instance(
            McpServerFactory::class,
            new McpServerFactory([app(OperationsMcpCapabilityRegistrar::class)]),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sessionPath);
        parent::tearDown();
    }

    public function test_operations_tools_include_read_tools_and_approval_gated_writes_with_closed_schemas(): void
    {
        $this->grant([
            'project.index', 'project_phase.index', 'task.index', 'task.view_all',
            'quarterly_objective.index', 'meeting.index',
        ]);

        $tools = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass,
        ], $this->initialize())->assertOk()->json('result.tools');

        $expected = [
            'get_projects', 'get_project_details', 'get_tasks', 'get_task_details',
            'get_overdue_tasks', 'get_operational_bottlenecks', 'get_quarterly_objectives', 'get_meetings',
            'create_task', 'update_task', 'assign_task', 'complete_task',
            'execute_create_task', 'execute_update_task', 'execute_assign_task', 'execute_complete_task',
        ];
        $this->assertEqualsCanonicalizing($expected, array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertFalse($tool['inputSchema']['additionalProperties'] ?? true);
            $permission = (new McpToolPermissionRegistry)->forTool($tool['name']);
            if ($permission->operationType === 'read') {
                $this->assertFalse($permission->requiresApproval);
            } else {
                $this->assertSame('write', $permission->operationType);
                $this->assertTrue($permission->requiresApproval);
            }
        }
        $taskTools = collect($tools)->whereIn('name', ['get_tasks', 'get_task_details', 'get_overdue_tasks']);
        foreach ($taskTools as $tool) {
            $this->assertContains('task.view_all', (new McpToolPermissionRegistry)->forTool($tool['name'])->optionalDomainPermissions);
        }
    }

    public function test_task_lists_and_details_are_scoped_to_assigned_or_created_records(): void
    {
        $other = User::factory()->create();
        $this->grant(['task.index']);

        $this->actingAs($this->principal);
        $assigned = Task::factory()->create([
            'action' => 'Assigned task',
            'status' => 'pending',
            'assigned_to' => $this->principal->getKey(),
            'description' => 'Private task description',
        ]);
        $created = Task::factory()->create([
            'action' => 'Created task',
            'status' => 'in_progress',
            'assigned_to' => $other->getKey(),
        ]);
        $this->actingAs($other);
        $hidden = Task::factory()->create([
            'action' => 'Hidden task',
            'status' => 'pending',
            'assigned_to' => $other->getKey(),
        ]);
        $this->actingAs($this->principal);

        $session = $this->initialize();
        $result = $this->callTool('get_tasks', ['limit' => 1, 'sort_by' => 'action'], $session)
            ->assertOk()->json('result.structuredContent');

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(1, $result['returned_count']);
        $this->assertTrue($result['has_more']);
        $this->assertSame('Assigned task', $result['tasks'][0]['action']);
        $this->assertArrayNotHasKey('description', $result['tasks'][0]);
        $this->assertArrayNotHasKey('created_by', $result['tasks'][0]);

        $detail = $this->callTool('get_task_details', ['task_id' => $assigned->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('Assigned task', $detail['task']['action']);

        $hiddenDetail = $this->callTool('get_task_details', ['task_id' => $hidden->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('unavailable_task_not_found', $hiddenDetail['availability']);
        $this->assertNotSame($created->getKey(), $hidden->getKey());

        $content = (string) $this->callTool('get_task_details', ['task_id' => $assigned->getKey()], $session)->getContent();
        $this->assertStringNotContainsString('Private task description', $content);
    }

    public function test_task_view_all_permission_expands_visibility_without_changing_default_scope(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);
        Task::factory()->create(['action' => 'Other employee task', 'assigned_to' => $other->getKey()]);
        $this->actingAs($this->principal);
        Task::factory()->create(['action' => 'Assistant-owned task', 'assigned_to' => $this->principal->getKey()]);
        $this->grant(['task.index', 'task.view_all']);

        $result = $this->callTool('get_tasks', [], $this->initialize())
            ->assertOk()->json('result.structuredContent');

        $this->assertSame(2, $result['total_count']);
    }

    public function test_overdue_tasks_exclude_today_and_done_but_preserve_unknown_status(): void
    {
        $this->grant(['task.index']);
        $this->actingAs($this->principal);
        Task::factory()->create(['action' => 'Yesterday overdue', 'status' => 'pending', 'assigned_to' => $this->principal->getKey(), 'due_date' => '2026-11-19']);
        Task::factory()->create(['action' => 'Due today', 'status' => 'pending', 'assigned_to' => $this->principal->getKey(), 'due_date' => '2026-11-20']);
        Task::factory()->create(['action' => 'Completed', 'status' => 'done', 'assigned_to' => $this->principal->getKey(), 'due_date' => '2026-11-18']);
        Task::factory()->create(['action' => 'Unrecognized status', 'status' => 'blocked', 'assigned_to' => $this->principal->getKey(), 'due_date' => '2026-11-18']);

        $result = $this->callTool('get_overdue_tasks', ['as_of' => '2026-11-20'], $this->initialize())
            ->assertOk()->json('result.structuredContent');

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(['Unrecognized status', 'Yesterday overdue'], array_column($result['tasks'], 'action'));
        $this->assertSame('blocked', $result['tasks'][0]['status']);
        $this->assertSame(2, $result['tasks'][0]['overdue_days']);
        $this->assertStringContainsString('Null due dates are excluded', $result['basis']);

        $page = $this->callTool('get_overdue_tasks', [
            'as_of' => '2026-11-20',
            'limit' => 1,
            'offset' => 1,
        ], $this->initialize())->assertOk()->json('result.structuredContent');

        $this->assertSame(2, $page['total_count']);
        $this->assertSame(1, $page['returned_count']);
        $this->assertFalse($page['has_more']);
        $this->assertSame('Yesterday overdue', $page['tasks'][0]['action']);
    }

    public function test_bottleneck_aggregates_use_the_same_task_record_scope_as_task_lists(): void
    {
        // Keep the fixed November fixtures stable regardless of the CI runner's current date.
        $this->travelTo(now()->setDate(2026, 11, 20)->startOfDay());

        $other = User::factory()->create();
        $this->grant(['task.index', 'project.index', 'project_phase.index']);
        $this->actingAs($this->principal);
        $project = Project::factory()->create([
            'planned_start_date' => '2026-11-01',
            'start_date' => '2026-11-05',
            'planned_end_date' => '2026-12-01',
            'end_date' => '2026-12-01',
        ]);
        $phase = ProjectPhase::factory()->create(['project_id' => $project->getKey()]);
        Task::factory()->create([
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'action' => 'Visible overdue',
            'status' => 'pending',
            'assigned_to' => $this->principal->getKey(),
            'due_date' => '2026-11-19',
            'estimated_effort_minutes' => 90,
        ]);
        Task::factory()->create([
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'action' => 'Visible open',
            'status' => 'in_progress',
            'assigned_to' => $this->principal->getKey(),
            'due_date' => '2026-11-22',
            'estimated_effort_minutes' => null,
        ]);
        $this->actingAs($other);
        Task::factory()->create([
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'action' => 'Hidden overdue',
            'status' => 'pending',
            'assigned_to' => $other->getKey(),
            'due_date' => '2026-11-18',
        ]);
        $this->actingAs($this->principal);

        $result = $this->callTool('get_operational_bottlenecks', [], $this->initialize())
            ->assertOk()->json('result.structuredContent');

        $this->assertSame(2, $result['task_total']);
        $this->assertSame(2, $result['task_open']);
        $this->assertSame(1, $result['task_overdue']);
        $this->assertSame(1, $result['project_schedule']['projects_started_after_plan']);
        $this->assertSame(2, $result['assignee_workload'][0]['open_count']);
        $this->assertArrayNotHasKey('assignee_id', $result['assignee_workload'][0]);
        $this->assertStringContainsString('unknown values are not normalized', $result['status_basis']);
    }

    public function test_projects_and_phases_are_permission_gated_and_sensitive_fields_are_excluded(): void
    {
        $this->grant(['project.index', 'project_phase.index']);
        $project = Project::factory()->create([
            'title' => 'Delivery project',
            'status' => 'active',
            'description' => 'Confidential project description',
            'budget' => 987654,
            'planned_start_date' => '2026-11-01',
            'planned_end_date' => '2026-12-01',
            'start_date' => '2026-11-05',
            'end_date' => '2026-11-28',
        ]);
        ProjectPhase::factory()->create([
            'project_id' => $project->getKey(),
            'title' => 'Discovery',
            'description' => 'Private phase notes',
            'start_on' => '2026-11-01',
            'end_on' => '2026-11-10',
            'order' => 1,
        ]);

        $session = $this->initialize();
        $projects = $this->callTool('get_projects', ['status' => 'active'], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame(1, $projects['total_count']);
        $this->assertSame('Delivery project', $projects['projects'][0]['title']);
        $this->assertArrayNotHasKey('budget', $projects['projects'][0]);
        $this->assertArrayNotHasKey('description', $projects['projects'][0]);

        $detail = $this->callTool('get_project_details', ['project_id' => $project->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertTrue($detail['phases_available']);
        $this->assertSame(1, $detail['phase_count']);
        $this->assertSame('Discovery', $detail['phases'][0]['title']);
        $this->assertArrayNotHasKey('description', $detail['phases'][0]);

        $content = (string) $this->callTool('get_project_details', ['project_id' => $project->getKey()], $session)->getContent();
        $this->assertStringNotContainsString('Confidential project description', $content);
        $this->assertStringNotContainsString('987654', $content);
        $this->assertStringNotContainsString('Private phase notes', $content);
    }

    public function test_quarterly_objectives_return_minimal_fields_and_respect_filters(): void
    {
        $this->grant(['quarterly_objective.index']);
        $employee = Employee::factory()->create(['full_name' => 'Operations Employee']);
        QuarterlyObjective::factory()->create([
            'employee_id' => $employee->getKey(),
            'objectives' => 'Improve delivery predictability',
            'description' => 'Confidential performance notes',
            'quarter' => '2026-Q4',
            'status' => 'on_track',
        ]);
        QuarterlyObjective::factory()->create([
            'quarter' => '2026-Q3',
            'status' => 'complete',
        ]);

        $result = $this->callTool('get_quarterly_objectives', ['quarter' => '2026-Q4', 'status' => 'on_track'], $this->initialize())
            ->assertOk()->json('result.structuredContent');

        $this->assertSame(1, $result['total_count']);
        $this->assertSame('Operations Employee', $result['objectives'][0]['employee_name']);
        $this->assertSame('Improve delivery predictability', $result['objectives'][0]['objective']);
        $this->assertArrayNotHasKey('description', $result['objectives'][0]);
        $this->assertArrayNotHasKey('employee_id', $result['objectives'][0]);

        $content = (string) $this->callTool('get_quarterly_objectives', ['quarter' => '2026-Q4'], $this->initialize())->getContent();
        $this->assertStringNotContainsString('Confidential performance notes', $content);
    }

    public function test_meetings_are_date_filtered_and_participant_identities_are_redacted(): void
    {
        $this->grant(['meeting.index']);
        $organizer = Employee::factory()->create(['full_name' => 'Meeting Organizer']);
        Meeting::query()->create([
            'subject' => 'Quarterly operations review',
            'description' => 'Confidential meeting notes',
            'type' => 'review',
            'status' => 'scheduled',
            'starts_at' => '2026-11-20 10:00:00',
            'ends_at' => '2026-11-20 11:00:00',
            'location' => 'Bucharest office',
            'organizer_id' => $organizer->getKey(),
            'participants' => ['employee-1' => 'Secret Person', 'employee-2' => 'Another Secret'],
        ]);
        Meeting::query()->create([
            'subject' => 'Outside date range',
            'description' => 'Not returned',
            'type' => 'review',
            'status' => 'scheduled',
            'starts_at' => '2026-11-21 10:00:00',
            'ends_at' => '2026-11-21 11:00:00',
            'location' => 'Remote',
            'organizer_id' => $organizer->getKey(),
            'participants' => [],
        ]);

        $result = $this->callTool('get_meetings', [
            'from_date' => '2026-11-20',
            'to_date' => '2026-11-20',
            'query' => 'Quarterly',
        ], $this->initialize())->assertOk()->json('result.structuredContent');

        $this->assertSame(1, $result['total_count']);
        $this->assertSame('Quarterly operations review', $result['meetings'][0]['subject']);
        $this->assertSame('Meeting Organizer', $result['meetings'][0]['organizer_name']);
        $this->assertSame(2, $result['meetings'][0]['participants_count']);
        $this->assertArrayNotHasKey('description', $result['meetings'][0]);
        $this->assertArrayNotHasKey('participants', $result['meetings'][0]);

        $content = (string) $this->callTool('get_meetings', ['query' => 'Quarterly'], $this->initialize())->getContent();
        $this->assertStringNotContainsString('Confidential meeting notes', $content);
        $this->assertStringNotContainsString('Secret Person', $content);
    }

    public function test_list_tools_return_empty_results_for_unmatched_filters(): void
    {
        $this->grant(['project.index', 'task.index', 'quarterly_objective.index', 'meeting.index']);
        $session = $this->initialize();

        $projects = $this->callTool('get_projects', ['status' => 'no-such-status'], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame(0, $projects['total_count']);
        $this->assertSame(0, $projects['returned_count']);
        $this->assertFalse($projects['has_more']);
        $this->assertSame([], $projects['projects']);

        $tasks = $this->callTool('get_tasks', ['status' => 'done'], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame(0, $tasks['total_count']);
        $this->assertSame([], $tasks['tasks']);

        $overdue = $this->callTool('get_overdue_tasks', ['as_of' => '2026-11-20'], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame(0, $overdue['total_count']);
        $this->assertSame([], $overdue['tasks']);

        $objectives = $this->callTool('get_quarterly_objectives', ['quarter' => '2099-Q1'], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame(0, $objectives['total_count']);
        $this->assertSame([], $objectives['objectives']);

        $meetings = $this->callTool('get_meetings', ['query' => 'No matching subject'], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame(0, $meetings['total_count']);
        $this->assertSame([], $meetings['meetings']);

        $projectDetail = $this->callTool('get_project_details', ['project_id' => 999999], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('unavailable_project_not_found', $projectDetail['availability']);

        $taskDetail = $this->callTool('get_task_details', ['task_id' => 999999], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('unavailable_task_not_found', $taskDetail['availability']);
    }

    public function test_task_writes_require_independent_approval_and_execute_the_immutable_payload(): void
    {
        $this->grant([
            'task.create', 'task.update', 'task.assign', 'task.view_all',
            'project.index', 'project_phase.index',
        ]);
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->getKey()]);
        $otherAssignee = User::factory()->create();
        $session = $this->initialize();
        $reviewer = $this->reviewer();

        $createArguments = [
            'action' => 'Prepare the quarterly review',
            'description' => 'Collect and validate the operating metrics.',
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'due_date' => '2026-12-15',
            'idempotency_key' => 'task-create-quarterly-review',
        ];
        $createResponse = $this->callTool('create_task', $createArguments, $session)->assertOk();
        $createRequest = $createResponse->json('result.structuredContent');
        $this->assertIsArray($createRequest, json_encode($createResponse->json()));
        $createApproval = McpApproval::query()->findOrFail($createRequest['approval_id']);
        $this->assertSame(McpApproval::STATUS_PENDING, $createApproval->status);
        $this->assertSame(0, Task::query()->count());

        app(McpApprovalService::class)->approve($createApproval, $reviewer, 'Reviewed the exact task request.');
        $created = $this->callTool('execute_create_task', [
            'approval_id' => $createApproval->getKey(),
        ], $session)->assertOk()->json('result.structuredContent');

        $task = Task::query()->findOrFail($created['task_id']);
        $this->assertSame('Prepare the quarterly review', $task->action);
        $this->assertSame('pending', $task->status);
        $this->assertSame($this->principal->getKey(), $task->assigned_to);
        $this->assertSame($this->principal->getKey(), $task->created_by);
        $this->assertSame(McpApproval::STATUS_EXECUTED, $createApproval->fresh()->status);

        $replayed = $this->callTool('execute_create_task', ['approval_id' => $createApproval->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame($created, $replayed);
        $this->assertSame(1, Task::query()->count());

        $duplicateRequest = $this->callTool('create_task', $createArguments, $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame($createApproval->getKey(), $duplicateRequest['approval_id']);
        $this->assertSame(1, Task::query()->count());

        $updateArguments = [
            'task_id' => $task->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'action' => 'Prepare the approved quarterly review',
            'status' => 'in_progress',
            'idempotency_key' => 'task-update-quarterly-review',
        ];
        $updateRequest = $this->callTool('update_task', $updateArguments, $session)
            ->assertOk()->json('result.structuredContent');
        $updateApproval = McpApproval::query()->findOrFail($updateRequest['approval_id']);
        $this->assertSame('Prepare the quarterly review', $task->fresh()->action);
        app(McpApprovalService::class)->approve($updateApproval, $reviewer, 'Reviewed the task title change.');
        $updated = $this->callTool('execute_update_task', ['approval_id' => $updateApproval->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('Prepare the approved quarterly review', $task->fresh()->action);
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame($task->getKey(), $updated['task_id']);

        $assignRequest = $this->callTool('assign_task', [
            'task_id' => $task->getKey(),
            'assigned_to' => $otherAssignee->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'idempotency_key' => 'task-assign-quarterly-review',
        ], $session)->assertOk()->json('result.structuredContent');
        $assignApproval = McpApproval::query()->findOrFail($assignRequest['approval_id']);
        $this->assertSame($this->principal->getKey(), $task->fresh()->assigned_to);
        app(McpApprovalService::class)->approve($assignApproval, $reviewer, 'Reviewed the assignment change.');
        $this->callTool('execute_assign_task', ['approval_id' => $assignApproval->getKey()], $session)->assertOk();
        $this->assertSame($otherAssignee->getKey(), $task->fresh()->assigned_to);

        $completeRequest = $this->callTool('complete_task', [
            'task_id' => $task->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'idempotency_key' => 'task-complete-quarterly-review',
        ], $session)->assertOk()->json('result.structuredContent');
        $completeApproval = McpApproval::query()->findOrFail($completeRequest['approval_id']);
        $this->assertSame('in_progress', $task->fresh()->status);
        app(McpApprovalService::class)->approve($completeApproval, $reviewer, 'Reviewed the completion request.');
        $this->callTool('execute_complete_task', ['approval_id' => $completeApproval->getKey()], $session)->assertOk();
        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_task_assignment_preserves_the_existing_eligible_role_policy(): void
    {
        $this->grant(['task.update', 'task.assign']);
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->getKey()]);
        $task = Task::withoutEvents(fn () => Task::factory()->create([
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'assigned_to' => $this->principal->getKey(),
            'created_by' => $this->principal->getKey(),
            'status' => 'pending',
        ]));
        $eligibleAssignee = User::factory()->create();
        $ineligibleAssignee = User::factory()->create();
        $eligibleRole = HexaRole::query()->create([
            'name' => 'Academician',
            'created_by_name' => 'Test',
            'access' => [],
            'guard' => (string) config('mcp.domain_permission_guard', 'web'),
        ]);
        DB::table('hexa_role_user')->insert([
            'user_id' => $eligibleAssignee->getKey(),
            'role_id' => $eligibleRole->getKey(),
        ]);
        $session = $this->initialize();

        $eligibleRequest = $this->callTool('assign_task', [
            'task_id' => $task->getKey(),
            'assigned_to' => $eligibleAssignee->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'idempotency_key' => 'task-assign-eligible-user',
        ], $session)->assertOk()->json('result.structuredContent');
        $this->assertSame(McpApproval::STATUS_PENDING, McpApproval::query()->findOrFail($eligibleRequest['approval_id'])->status);

        $ineligibleResponse = $this->callTool('assign_task', [
            'task_id' => $task->getKey(),
            'assigned_to' => $ineligibleAssignee->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'idempotency_key' => 'task-assign-ineligible-user',
        ], $session)->assertOk();
        $this->assertArrayHasKey('error', $ineligibleResponse->json());
        $this->assertSame(1, McpApproval::query()->count());
        $this->assertSame($this->principal->getKey(), $task->fresh()->assigned_to);
    }

    public function test_task_approval_is_blocked_when_record_leaves_the_principals_scope(): void
    {
        $this->grant(['task.update']);
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->getKey()]);
        $task = Task::withoutEvents(fn () => Task::factory()->create([
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'assigned_to' => $this->principal->getKey(),
            'created_by' => $this->principal->getKey(),
            'action' => 'Original task title',
            'status' => 'pending',
        ]));
        $session = $this->initialize();

        $request = $this->callTool('update_task', [
            'task_id' => $task->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'action' => 'Approved but no longer visible',
            'idempotency_key' => 'task-update-scope-revoked',
        ], $session)->assertOk()->json('result.structuredContent');
        $approval = McpApproval::query()->findOrFail($request['approval_id']);
        app(McpApprovalService::class)->approve($approval, $this->reviewer(), 'Reviewed the task update.');

        $otherUser = User::factory()->create();
        $task->forceFill([
            'assigned_to' => $otherUser->getKey(),
            'created_by' => $otherUser->getKey(),
        ])->save();

        $response = $this->callTool('execute_update_task', ['approval_id' => $approval->getKey()], $session)
            ->assertOk();
        $this->assertArrayHasKey('error', $response->json());
        $this->assertSame('Original task title', $task->fresh()->action);
        $this->assertSame(McpApproval::STATUS_EXECUTION_BLOCKED, $approval->fresh()->status);
    }

    public function test_stale_task_approval_is_blocked_without_overwriting_the_newer_record(): void
    {
        $this->grant(['task.update', 'task.view_all']);
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->getKey()]);
        $task = Task::withoutEvents(fn () => Task::factory()->create([
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'assigned_to' => $this->principal->getKey(),
            'created_by' => $this->principal->getKey(),
            'action' => 'Original task title',
            'status' => 'pending',
        ]));
        $session = $this->initialize();

        $updateResponse = $this->callTool('update_task', [
            'task_id' => $task->getKey(),
            'expected_updated_at' => $task->fresh()->updated_at->toIso8601String(),
            'action' => 'Approved replacement title',
            'idempotency_key' => 'task-update-stale-version',
        ], $session)->assertOk();
        $request = $updateResponse->json('result.structuredContent');
        $this->assertIsArray($request, json_encode($updateResponse->json()));
        $approval = McpApproval::query()->findOrFail($request['approval_id']);
        $reviewer = $this->reviewer();
        app(McpApprovalService::class)->approve($approval, $reviewer, 'Reviewed the task update.');

        $this->travel(2)->seconds();
        $task->forceFill(['action' => 'Concurrent human update'])->save();

        $failedExecution = $this->callTool('execute_update_task', ['approval_id' => $approval->getKey()], $session)
            ->assertOk();
        $this->assertArrayHasKey('error', $failedExecution->json());
        $this->assertSame('Concurrent human update', $task->fresh()->action);
        $this->assertSame(McpApproval::STATUS_EXECUTION_BLOCKED, $approval->fresh()->status);
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create(['name' => 'MCP Reviewer']);
        $role = HexaRole::query()->create([
            'name' => 'MCP Approval Reviewer',
            'created_by_name' => 'Test',
            'access' => ['MCP' => ['mcp_approval.approve']],
            'guard' => (string) config('mcp.domain_permission_guard', 'web'),
        ]);
        DB::table('hexa_role_user')->insert([
            'user_id' => $reviewer->getKey(),
            'role_id' => $role->getKey(),
        ]);

        return $reviewer;
    }

    private function grant(array $permissions): void
    {
        $role = HexaRole::query()->create([
            'name' => 'Assistant',
            'created_by_name' => 'Test',
            'access' => ['Operations tools' => $permissions],
            'guard' => hexa()->guard(),
        ]);
        $this->principal->roles()->attach($role->getKey());
    }

    private function initialize(): string
    {
        $response = $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'mcp-operations-test', 'version' => '1.0'],
            ],
        ])->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertNotEmpty($session);

        return (string) $session;
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $name, array $arguments, string $session): TestResponse
    {
        return $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ], $session);
    }

    /** @param array<string, mixed> $payload */
    private function mcpPost(array $payload, ?string $session = null): TestResponse
    {
        Passport::actingAs($this->principal, $this->scopes);
        $clientClaims = rtrim(strtr(base64_encode(json_encode(['client_id' => 'd8d14d2c-0a80-4f4c-aac8-bb3e67e2a11c'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $server = [
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer eyJ0eXAiOiJKV1QifQ.'.$clientClaims.'.test-signature',
        ];
        if ($session !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }

        return $this->call('POST', '/mcp', [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
