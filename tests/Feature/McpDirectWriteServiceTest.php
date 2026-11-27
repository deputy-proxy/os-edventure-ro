<?php

namespace Tests\Feature;

use App\Mcp\Authorization\McpToolPermission;
use App\Mcp\Authorization\Scopes\McpGlobalRecordScope;
use App\Models\Mcp\McpDirectWriteExecution;
use App\Models\Mcp\McpExecutionAudit;
use App\Models\User;
use App\Services\Mcp\McpDirectWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class McpDirectWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
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
