<?php

namespace Tests\Feature;

use App\Mcp\Authorization\McpToolPermissionRegistry;
use App\Mcp\Server\McpServerFactory;
use App\Mcp\Tools\Processes\ProcessMcpCapabilityRegistrar;
use App\Models\Employee;
use App\Models\Mcp\McpApproval;
use App\Models\Mcp\McpDirectWriteExecution;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class McpProcessToolsTest extends TestCase
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
        config(['mcp.direct_writes_enabled' => true]);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->keyPath = storage_path('framework/testing/mcp-process-passport');
        File::ensureDirectoryExists($this->keyPath, 0700, true);
        $private = $this->keyPath.'/oauth-private.key';
        $public = $this->keyPath.'/oauth-public.key';
        if (is_file($private) === false || is_file($public) === false) {
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

        $this->sessionPath = storage_path('framework/testing/mcp-process-sessions');
        config()->set('mcp.session_path', $this->sessionPath);
        File::deleteDirectory($this->sessionPath);

        $this->principal = User::factory()->create(['email' => 'mcp-process@example.test']);
        config()->set('mcp.ai_principal_email', $this->principal->email);
        $this->scopes = ['mcp:use', ...array_keys((new McpToolPermissionRegistry)->passportScopes())];

        app()->instance(
            McpServerFactory::class,
            new McpServerFactory([app(ProcessMcpCapabilityRegistrar::class)]),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sessionPath);
        parent::tearDown();
    }

    public function test_process_tools_are_registered_with_closed_schemas_and_direct_write_boundaries(): void
    {
        $this->grant(['process.index', 'process.create', 'process.update', 'process.delete']);
        $tools = $this->mcpPost(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass], $this->initialize())->assertOk()->json('result.tools');
        $expected = ['get_processes', 'get_process_details', 'get_process_steps', 'get_process_tasks', 'create_process', 'update_process', 'delete_process'];
        $this->assertEqualsCanonicalizing($expected, array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertFalse($tool['inputSchema']['additionalProperties'] ?? true);
            $permission = (new McpToolPermissionRegistry)->forTool($tool['name']);
            if ($permission->operationType === 'write') {
                $this->assertSame('direct', $permission->executionMode);
                $this->assertFalse($permission->requiresApproval);
                $this->assertContains('idempotency_key', $tool['inputSchema']['required']);
            }
        }

    }

    public function test_process_tools_reject_a_different_authenticated_principal(): void
    {
        $this->grant(['process.index']);
        $other = User::factory()->create(['email' => 'not-the-mcp-principal@example.test']);
        $session = $this->initialize($other);

        $response = $this->callToolAs($other, 'get_processes', [], $session)->assertOk();

        $this->assertArrayHasKey('error', $response->json());
        $this->assertSame(0, Process::query()->count());
    }

    public function test_process_tools_require_their_domain_permission_and_dedicated_oauth_scope(): void
    {
        $this->grant(['task.index']);
        $session = $this->initialize();

        $missingPermission = $this->callTool('get_processes', [], $session)->assertOk();
        $this->assertArrayHasKey('error', $missingPermission->json());

        $role = HexaRole::query()->where('name', 'Assistant')->firstOrFail();
        DB::table('hexa_roles')->where('id', $role->getKey())->update([
            'access' => json_encode(['Process tools' => ['process.index']], JSON_THROW_ON_ERROR),
        ]);
        $this->scopes = array_values(array_diff($this->scopes, ['mcp.processes.list.read']));
        $missingScope = $this->callTool('get_processes', [], $session)->assertOk();
        $this->assertArrayHasKey('error', $missingScope->json());
    }

    public function test_process_reads_enforce_role_visibility_and_include_soft_deleted_definitions(): void
    {
        $role = $this->grant(['process.index', 'process.update', 'process.delete']);
        $otherRole = HexaRole::query()->create([
            'name' => 'Other role',
            'created_by_name' => 'Test',
            'access' => [],
            'guard' => (string) config('mcp.domain_permission_guard', 'web'),
        ]);
        $employee = Employee::factory()->create();
        $visible = $this->makeProcess($employee, [$role->getKey()], [
            'title' => 'Visible documented process',
            'steps' => $this->steps(),
        ]);
        $hidden = $this->makeProcess($employee, [$otherRole->getKey()], [
            'title' => 'Hidden documented process',
        ]);
        $trashed = $this->makeProcess($employee, [$role->getKey()], [
            'title' => 'Archived documented process',
        ]);
        $trashed->delete();

        $session = $this->initialize();
        $listResponse = $this->callTool('get_processes', ['limit' => 10], $session)->assertOk();
        $list = $listResponse->json('result.structuredContent');
        $this->assertIsArray($list, json_encode($listResponse->json()));

        $this->assertSame(2, $list['total_count']);
        $this->assertEqualsCanonicalizing(
            ['Archived documented process', 'Visible documented process'],
            array_column($list['processes'], 'title'),
        );
        $this->assertNotEmpty(collect($list['processes'])->firstWhere('id', $trashed->getKey())['deleted_at']);

        $trashedDetails = $this->callTool('get_process_details', ['process_id' => $trashed->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertNotEmpty($trashedDetails['process']['deleted_at']);

        $trashedUpdate = $this->callTool('update_process', [
            'process_id' => $trashed->getKey(),
            'expected_updated_at' => $trashed->updated_at->toIso8601String(),
            'title' => 'Must not update trashed definition',
            'idempotency_key' => 'process-trashed-update-001',
        ], $session)->assertOk();
        $this->assertArrayHasKey('error', $trashedUpdate->json());

        $trashedDelete = $this->callTool('delete_process', [
            'process_id' => $trashed->getKey(),
            'expected_updated_at' => $trashed->updated_at->toIso8601String(),
            'idempotency_key' => 'process-trashed-delete-001',
        ], $session)->assertOk();
        $this->assertArrayHasKey('error', $trashedDelete->json());
        $this->assertSame(0, McpApproval::query()->count());

        $details = $this->callTool('get_process_details', ['process_id' => $visible->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('Visible documented process', $details['process']['title']);
        $this->assertSame([['name' => 'Assistant']], $details['process']['visible_to_roles']);

        $steps = $this->callTool('get_process_steps', ['process_id' => $visible->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame($this->steps(), $steps['steps']);

        $hiddenDetails = $this->callTool('get_process_details', ['process_id' => $hidden->getKey()], $session)
            ->assertOk()->json('result.structuredContent');
        $this->assertSame('unavailable_process_not_found', $hiddenDetails['availability']);

        $content = (string) $this->callTool('get_process_details', ['process_id' => $hidden->getKey()], $session)->getContent();
        $this->assertStringNotContainsString('Hidden documented process', $content);
    }

    public function test_process_task_reads_apply_the_existing_task_record_scope(): void
    {
        $role = $this->grant(['process.index', 'task.index']);
        $employee = Employee::factory()->create();
        $process = $this->makeProcess($employee, [$role->getKey()]);
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->getKey()]);
        $other = User::factory()->create();

        $this->actingAs($this->principal);
        $visibleTask = $process->tasks()->create([
            'action' => 'Visible associated task',
            'description' => 'Private task details',
            'assigned_to' => $this->principal->getKey(),
            'created_by' => $this->principal->getKey(),
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'status' => 'pending',
            'due_date' => '2026-12-01',
        ]);
        $this->actingAs($other);
        $hiddenTask = $process->tasks()->create([
            'action' => 'Hidden associated task',
            'description' => 'Another private task',
            'assigned_to' => $other->getKey(),
            'created_by' => $other->getKey(),
            'project_id' => $project->getKey(),
            'project_phase_id' => $phase->getKey(),
            'status' => 'pending',
            'due_date' => '2026-12-02',
        ]);
        $this->actingAs($this->principal);

        $taskResponse = $this->callTool('get_process_tasks', ['process_id' => $process->getKey()], $this->initialize())->assertOk();
        $result = $taskResponse->json('result.structuredContent');
        $this->assertIsArray($result, json_encode($taskResponse->json()));

        $this->assertSame(1, $result['total_count']);
        $this->assertSame($visibleTask->getKey(), $result['tasks'][0]['id']);
        $this->assertArrayNotHasKey('description', $result['tasks'][0]);
        $this->assertNotSame($visibleTask->getKey(), $hiddenTask->getKey());
    }

    public function test_process_writes_execute_directly_with_idempotent_replay(): void
    {
        $role = $this->grant(['process.index', 'process.create', 'process.update', 'process.delete']);
        $employee = Employee::factory()->create();
        $second = Employee::factory()->create();
        $session = $this->initialize();
        $args = ['title' => 'New documented process', 'description' => 'A descriptive procedure only.', 'steps' => $this->steps(), 'owner_id' => $employee->getKey(), 'main_topic' => 'Operations', 'visible_to' => [$role->getKey()], 'idempotency_key' => 'process-create-direct-1'];
        $created = $this->callTool('create_process', $args, $session)->assertOk()->json('result.structuredContent');
        $process = Process::query()->findOrFail($created['process_id']);
        $this->assertSame('New documented process', $process->title);
        $this->assertSame($employee->getKey(), $process->owner_id);
        $this->assertEquals($this->steps(), $process->steps);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame($created, $this->callTool('create_process', $args, $session)->assertOk()->json('result.structuredContent'));

        $updated = $this->callTool('update_process', ['process_id' => $process->getKey(), 'expected_updated_at' => $process->fresh()->updated_at->toIso8601String(), 'title' => 'Updated documented process', 'owner_id' => $second->getKey(), 'idempotency_key' => 'process-update-direct-1'], $session)->assertOk()->json('result.structuredContent');
        $this->assertSame($process->getKey(), $updated['process_id']);
        $this->assertSame('Updated documented process', $process->fresh()->title);
        $deleted = $this->callTool('delete_process', ['process_id' => $process->getKey(), 'expected_updated_at' => $process->fresh()->updated_at->toIso8601String(), 'idempotency_key' => 'process-delete-direct-1'], $session)->assertOk()->json('result.structuredContent');
        $this->assertSame($process->getKey(), $deleted['process_id']);
        $this->assertSoftDeleted('processes', ['id' => $process->getKey()]);
        $this->assertSame(3, McpDirectWriteExecution::query()->count());
        $this->assertSame(0, McpApproval::query()->count());

    }

    public function test_process_update_is_blocked_when_visibility_is_revoked(): void
    {
        $role = $this->grant(['process.index', 'process.update']);
        $employee = Employee::factory()->create();
        $process = Process::factory()->create(['owner_id' => $employee->getKey(), 'visible_to' => [$role->getKey()], 'steps' => $this->steps(), 'title' => 'Visible process']);
        $other = HexaRole::query()->create(['name' => 'Other role', 'created_by_name' => 'Test', 'access' => [], 'guard' => (string) config('mcp.domain_permission_guard', 'web')]);
        $process->forceFill(['visible_to' => [$other->getKey()]])->save();
        $response = $this->callTool('update_process', ['process_id' => $process->getKey(), 'expected_updated_at' => $process->fresh()->updated_at->toIso8601String(), 'title' => 'Must not execute', 'idempotency_key' => 'process-scope-direct-1'], $this->initialize())->assertOk();
        $this->assertArrayHasKey('error', $response->json());
        $this->assertSame('Visible process', $process->fresh()->title);

    }

    public function test_stale_process_update_is_blocked_without_overwriting_newer_record(): void
    {
        $role = $this->grant(['process.index', 'process.update']);
        $employee = Employee::factory()->create();
        $process = Process::factory()->create(['owner_id' => $employee->getKey(), 'visible_to' => [$role->getKey()], 'steps' => $this->steps(), 'title' => 'Original process title']);
        $stale = $process->fresh()->updated_at->toIso8601String();
        $this->travel(2)->seconds();
        $process->forceFill(['title' => 'Concurrent process update'])->save();
        $response = $this->callTool('update_process', ['process_id' => $process->getKey(), 'expected_updated_at' => $stale, 'title' => 'Must not overwrite', 'idempotency_key' => 'process-stale-direct-1'], $this->initialize())->assertOk();
        $this->assertArrayHasKey('error', $response->json());
        $this->assertSame('Concurrent process update', $process->fresh()->title);

    }

    public function test_process_factory_matches_the_persisted_process_definition_schema(): void
    {
        $role = $this->grant(['process.index']);

        $this->assertTrue(Schema::hasColumn('processes', 'owner_id'));
        $this->assertFalse(Schema::hasColumn('processes', 'owner'));

        $process = Process::factory()->create();

        $this->assertSame($process->owner_id, $process->owner->getKey());
        $this->assertSame([], $process->steps);
        $this->assertSame([(int) $role->getKey()], $process->visible_to);
    }

    public function test_process_owner_does_not_bypass_role_visibility(): void
    {
        $role = $this->grant(['process.index']);
        $otherRole = HexaRole::query()->create([
            'name' => 'Restricted process role',
            'created_by_name' => 'Test',
            'access' => [],
            'guard' => (string) config('mcp.domain_permission_guard', 'web'),
        ]);
        $employee = Employee::factory()->create(['user_id' => $this->principal->getKey()]);
        $process = $this->makeProcess($employee, [$otherRole->getKey()], ['title' => 'Owner-hidden process']);

        $result = $this->callTool('get_processes', [], $this->initialize())
            ->assertOk()->json('result.structuredContent');

        $this->assertSame(0, $result['total_count']);
        $this->assertSame($this->principal->getKey(), $employee->user_id);
        $this->assertSame($employee->getKey(), $process->owner_id);
        $this->assertDatabaseHas('hexa_role_user', ['user_id' => $this->principal->getKey(), 'role_id' => $role->getKey()]);
    }

    public function test_process_write_validation_rejects_executable_or_malformed_blocks(): void
    {
        $role = $this->grant(['process.create']);
        $employee = Employee::factory()->create();

        $response = $this->callTool('create_process', [
            'title' => 'Invalid process',
            'description' => 'Must remain descriptive.',
            'owner_id' => $employee->getKey(),
            'main_topic' => 'Operations',
            'visible_to' => [$role->getKey()],
            'steps' => [['type' => 'transition', 'data' => ['content' => 'run this']]],
            'idempotency_key' => 'process-invalid-001',
        ], $this->initialize())->assertOk();

        $this->assertArrayHasKey('error', $response->json());
        $this->assertSame(0, McpApproval::query()->count());
        $this->assertSame(0, Process::query()->count());

        $invalidRole = $this->callTool('create_process', [
            'title' => 'Invalid actor process',
            'description' => 'Must remain descriptive.',
            'owner_id' => $employee->getKey(),
            'main_topic' => 'Operations',
            'visible_to' => [$role->getKey()],
            'steps' => [[
                'type' => 'step',
                'data' => ['title' => 'Invalid actor', 'actor' => 999999, 'content' => 'Review.', 'input' => 'Input', 'output' => 'Output'],
            ]],
            'idempotency_key' => 'process-invalid-role-001',
        ], $this->initialize())->assertOk();

        $this->assertArrayHasKey('error', $invalidRole->json());
        $this->assertSame(0, McpApproval::query()->count());
    }

    private function makeProcess(Employee $owner, array $visibleTo, array $overrides = []): Process
    {
        return Process::query()->create([
            'title' => 'Documented process',
            'description' => 'Descriptive process notes.',
            'steps' => [],
            'owner_id' => $owner->getKey(),
            'main_topic' => 'Operations',
            'visible_to' => $visibleTo,
            ...$overrides,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function steps(): array
    {
        return [
            [
                'type' => 'step',
                'data' => [
                    'title' => 'Review request',
                    'actor' => HexaRole::query()->where('name', 'Assistant')->value('id'),
                    'content' => 'Review the submitted request.',
                    'input' => 'Request details',
                    'output' => 'Review decision',
                ],
            ],
            [
                'type' => 'condition',
                'data' => ['content' => 'Continue when the review is complete.'],
            ],
        ];
    }

    private function grant(array $permissions): HexaRole
    {
        $role = HexaRole::query()->create([
            'name' => 'Assistant',
            'created_by_name' => 'Test',
            'access' => ['Process tools' => $permissions],
            'guard' => (string) config('mcp.domain_permission_guard', 'web'),
        ]);
        $this->principal->roles()->attach($role->getKey());

        return $role;
    }

    private function initialize(?User $principal = null): string
    {
        $response = $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'mcp-process-test', 'version' => '1.0'],
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

    /** @param array<string, mixed> $arguments */
    private function callToolAs(User $principal, string $name, array $arguments, string $session): TestResponse
    {
        return $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ], $session, $principal);
    }

    /** @param array<string, mixed> $payload */
    private function mcpPost(array $payload, ?string $session = null, ?User $principal = null): TestResponse
    {
        Passport::actingAs($principal ?? $this->principal, $this->scopes);
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
