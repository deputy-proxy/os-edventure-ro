<?php

namespace Tests\Feature;

use App\Mcp\Authorization\McpToolPermission;
use App\Mcp\Authorization\Scopes\McpGlobalRecordScope;
use App\Models\Mcp\McpDirectWriteExecution;
use App\Models\Mcp\McpExecutionAudit;
use App\Models\User;
use App\Services\Mcp\McpDirectWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class McpDirectWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'mcp.direct_writes_enabled' => true]);
        request()->attributes->set('oauth_client_id', (string) Str::uuid());
        request()->attributes->set('request_id', 'direct-write-test-request');
    }

    public function test_completed_execution_is_replayed_without_invoking_the_executor_again(): void
    {
        $principal = User::factory()->create();
        $permission = $this->permission();
        $calls = 0;
        $arguments = ['value' => 'original', 'idempotency_key' => 'direct-write-replay-1'];

        $first = app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            $arguments,
            static fn () => null,
            static function () use (&$calls): array {
                $calls++;

                return ['record_id' => 42];
            },
        );
        $second = app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            $arguments,
            static fn () => null,
            static function () use (&$calls): array {
                $calls++;

                return ['record_id' => 99];
            },
        );

        $this->assertSame(['record_id' => 42], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('mcp_direct_write_executions', 1);
        $this->assertDatabaseHas('mcp_direct_write_executions', ['status' => 'executed']);
        $this->assertDatabaseHas('mcp_execution_audits', ['event_type' => 'direct_write_execution', 'status' => 'succeeded']);
    }

    public function test_completed_replay_survives_a_permission_matrix_version_change(): void
    {
        $principal = User::factory()->create();
        $permission = $this->permission();
        $arguments = ['value' => 'same request', 'idempotency_key' => 'direct-write-version-replay-1'];
        config(['mcp.permission_matrix_version' => '1.8.0']);

        $first = app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            $arguments,
            static fn () => null,
            static fn (): array => ['record_id' => 42],
        );

        config(['mcp.permission_matrix_version' => '1.9.0']);
        $second = app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            $arguments,
            static fn () => null,
            static fn (): array => ['record_id' => 99],
        );

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('mcp_direct_write_executions', 1);
    }

    public function test_idempotency_key_cannot_be_reused_with_a_different_payload(): void
    {
        $principal = User::factory()->create();
        $permission = $this->permission();

        app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            ['value' => 'first', 'idempotency_key' => 'direct-write-collision-1'],
            static fn () => null,
            static fn (): array => ['record_id' => 42],
        );

        try {
            app(McpDirectWriteService::class)->execute(
                $principal,
                $permission,
                ['value' => 'changed', 'idempotency_key' => 'direct-write-collision-1'],
                static fn () => null,
                static fn (): array => ['record_id' => 99],
            );
            $this->fail('A changed payload must not reuse an idempotency key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('mcp_direct_write_executions', 1);
    }

    public function test_unexpected_failure_is_marked_uncertain_and_cannot_be_retried(): void
    {
        $principal = User::factory()->create();
        $permission = $this->permission();
        $calls = 0;
        $arguments = ['value' => 'possibly committed', 'idempotency_key' => 'direct-write-uncertain-1'];

        try {
            app(McpDirectWriteService::class)->execute(
                $principal,
                $permission,
                $arguments,
                static fn () => null,
                static function () use (&$calls): array {
                    $calls++;
                    throw new RuntimeException('sensitive exception detail must not be persisted');
                },
            );
            $this->fail('An unexpected execution failure must be reported as uncertain.');
        } catch (ValidationException) {
            // Expected: the service exposes a safe reconciliation message.
        }

        $execution = McpDirectWriteExecution::query()->sole();
        $audit = McpExecutionAudit::query()->sole();
        $this->assertSame('uncertain', $execution->status);
        $this->assertSame('uncertain', $audit->status);
        $this->assertNull($execution->result);
        $this->assertNotSame('sensitive exception detail must not be persisted', $audit->error_code);

        try {
            app(McpDirectWriteService::class)->execute(
                $principal,
                $permission,
                $arguments,
                static fn () => null,
                static function () use (&$calls): array {
                    $calls++;

                    return ['record_id' => 42];
                },
            );
            $this->fail('An uncertain execution must never be replayed automatically.');
        } catch (ValidationException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_stale_claims_are_marked_uncertain_by_the_existing_expiry_command_service(): void
    {
        $principal = User::factory()->create();
        $execution = McpDirectWriteExecution::query()->create([
            'ai_principal_id' => $principal->getKey(),
            'oauth_client_id' => (string) Str::uuid(),
            'tool_name' => 'create_task',
            'tool_version' => '1.8.0',
            'idempotency_key' => 'direct-write-stale-1',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'executing',
            'started_at' => now()->subMinutes(10),
        ]);
        DB::table('mcp_direct_write_executions')->where('id', $execution->getKey())->update(['updated_at' => now()->subMinutes(10)]);
        $this->travel(10)->minutes();

        $this->assertSame(1, app(McpDirectWriteService::class)->markStaleExecutionsUncertain());
        $this->assertSame('uncertain', $execution->fresh()->status);
    }

    public function test_direct_writes_are_disabled_when_the_environment_switch_is_off(): void
    {
        $principal = User::factory()->create();
        config(['mcp.direct_writes_enabled' => false]);

        try {
            app(McpDirectWriteService::class)->execute(
                $principal,
                $this->permission(),
                ['value' => 'must not persist', 'idempotency_key' => 'direct-write-disabled-1'],
                static fn () => null,
                static fn (): array => ['record_id' => 42],
            );
            $this->fail('A disabled direct-write environment must reject execution.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseCount('mcp_direct_write_executions', 0);
            $this->assertDatabaseCount('mcp_execution_audits', 0);
        }
    }

    public function test_approval_gated_permissions_are_rejected_by_the_direct_write_service(): void
    {
        $principal = User::factory()->create();
        $permission = new McpToolPermission(
            toolName: 'create_task',
            mcpScope: 'mcp.test.direct-write',
            domainPermissions: ['task.create'],
            recordScope: McpGlobalRecordScope::class,
            allowedFields: ['record_id' => true],
            operationType: 'write',
            requiresApproval: true,
            executionMode: 'approval',
        );

        $this->expectException(\LogicException::class);

        app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            ['value' => 'must not persist', 'idempotency_key' => 'direct-write-approval-mode-1'],
            static fn () => null,
            static fn (): array => ['record_id' => 42],
        );
    }

    public function test_validator_denial_does_not_create_execution_or_audit_records(): void
    {
        $principal = User::factory()->create();

        try {
            app(McpDirectWriteService::class)->execute(
                $principal,
                $this->permission(),
                ['value' => 'out of scope', 'idempotency_key' => 'direct-write-denied-1'],
                static fn () => throw ValidationException::withMessages(['record' => 'denied']),
                static fn (): array => ['record_id' => 42],
            );
            $this->fail('A denied validation must stop before persistence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('record', $exception->errors());
        }

        $this->assertDatabaseCount('mcp_direct_write_executions', 0);
        $this->assertDatabaseCount('mcp_execution_audits', 0);
    }

    public function test_raw_write_arguments_are_not_copied_into_audit_records(): void
    {
        $principal = User::factory()->create();
        $privateValue = 'PRIVATE-CONTENT-DO-NOT-LOG';

        app(McpDirectWriteService::class)->execute(
            $principal,
            $this->permission(),
            ['value' => $privateValue, 'idempotency_key' => 'direct-write-audit-redaction-1'],
            static fn () => null,
            static fn (): array => ['record_id' => 42],
        );

        $auditRows = DB::table('mcp_execution_audits')->get()->toJson(JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($privateValue, $auditRows);
        $this->assertDatabaseHas('mcp_execution_audits', [
            'event_type' => 'direct_write_execution',
            'status' => 'succeeded',
        ]);
    }

    private function permission(): McpToolPermission
    {
        return new McpToolPermission(
            toolName: 'create_task',
            mcpScope: 'mcp.test.direct-write',
            domainPermissions: ['task.create'],
            recordScope: McpGlobalRecordScope::class,
            allowedFields: ['record_id' => true],
            operationType: 'write',
            requiresApproval: false,
            executionMode: 'direct',
        );
    }
}
