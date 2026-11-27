<?php

namespace Tests\Feature;

use App\Mcp\Authorization\McpToolPermission;
use App\Mcp\Authorization\Scopes\McpGlobalRecordScope;
use App\Models\Mcp\McpApproval;
use App\Models\Mcp\McpDirectWriteExecution;
use App\Models\Mcp\McpExecutionAudit;
use App\Models\Mcp\McpRetentionHold;
use App\Models\User;
use App\Services\Mcp\McpDirectWriteService;
use App\Services\Mcp\McpHistoryRetentionService;
use App\Services\Mcp\McpPayloadCanonicalizer;
use App\Services\Mcp\McpRetentionHoldService;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class McpHistoryRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'mcp.audit_retention_days' => 365,
            'mcp.ai_principal_email' => 'chatgpt@edventure.ro',
            'mcp.direct_writes_enabled' => true,
        ]);
    }

    public function test_prunes_only_terminal_records_at_or_before_the_365_day_cutoff(): void
    {
        $principal = User::factory()->create();
        $old = $this->approval($principal, McpApproval::STATUS_EXECUTED, now()->subDays(366));
        $oldAudit = $this->audit(['approval_id' => $old->getKey()], now()->subDays(366));
        DB::table('mcp_approvals')->where('id', $old->getKey())->update(['execution_audit_id' => $oldAudit->getKey()]);

        $boundary = $this->approval($principal, McpApproval::STATUS_REJECTED, now()->subDays(365));
        $boundaryAudit = $this->audit(['approval_id' => $boundary->getKey()], now()->subDays(365));
        DB::table('mcp_approvals')->where('id', $boundary->getKey())->update(['execution_audit_id' => $boundaryAudit->getKey()]);

        $recent = $this->approval($principal, McpApproval::STATUS_CANCELLED, now()->subDays(364));
        $recentAudit = $this->audit(['approval_id' => $recent->getKey()], now()->subDays(364));
        DB::table('mcp_approvals')->where('id', $recent->getKey())->update(['execution_audit_id' => $recentAudit->getKey()]);

        $pending = $this->approval($principal, McpApproval::STATUS_PENDING, now()->subDays(500));
        $pendingAudit = $this->audit(['approval_id' => $pending->getKey(), 'status' => 'started'], now()->subDays(500));

        $result = app(McpHistoryRetentionService::class)->prune(now());

        $this->assertSame(2, $result['approvals_deleted']);
        $this->assertSame(2, $result['audits_deleted']);
        $this->assertDatabaseMissing('mcp_approvals', ['id' => $old->getKey()]);
        $this->assertDatabaseMissing('mcp_execution_audits', ['id' => $oldAudit->getKey()]);
        $this->assertDatabaseMissing('mcp_approvals', ['id' => $boundary->getKey()]);
        $this->assertDatabaseMissing('mcp_execution_audits', ['id' => $boundaryAudit->getKey()]);
        $this->assertDatabaseHas('mcp_approvals', ['id' => $recent->getKey()]);
        $this->assertDatabaseHas('mcp_execution_audits', ['id' => $recentAudit->getKey()]);
        $this->assertDatabaseHas('mcp_approvals', ['id' => $pending->getKey()]);
        $this->assertDatabaseHas('mcp_execution_audits', ['id' => $pendingAudit->getKey()]);
    }

    public function test_standalone_uncertain_audits_and_nonterminal_approvals_are_preserved(): void
    {
        $principal = User::factory()->create();
        $uncertainApproval = $this->approval($principal, McpApproval::STATUS_EXECUTION_UNCERTAIN, now()->subDays(500));
        $uncertainAudit = $this->audit(['status' => 'uncertain'], now()->subDays(500));
        $startedAudit = $this->audit(['status' => 'started', 'completed_at' => null], now()->subDays(500));

        $result = app(McpHistoryRetentionService::class)->prune(now());

        $this->assertSame(0, $result['approvals_deleted']);
        $this->assertSame(0, $result['audits_deleted']);
        $this->assertDatabaseHas('mcp_approvals', ['id' => $uncertainApproval->getKey()]);
        $this->assertDatabaseHas('mcp_execution_audits', ['id' => $uncertainAudit->getKey()]);
        $this->assertDatabaseHas('mcp_execution_audits', ['id' => $startedAudit->getKey()]);
    }

    public function test_legal_and_incident_holds_block_retention_until_explicitly_released(): void
    {
        $principal = User::factory()->create();
        $actor = $this->retentionManager();
        $approval = $this->approval($principal, McpApproval::STATUS_EXECUTED, now()->subDays(500));
        $audit = $this->audit(['approval_id' => $approval->getKey()], now()->subDays(500));
        DB::table('mcp_approvals')->where('id', $approval->getKey())->update(['execution_audit_id' => $audit->getKey()]);

        $holds = app(McpRetentionHoldService::class);
        $legal = $holds->place('approval', (int) $approval->getKey(), 'legal', $actor, 'Litigation hold');
        $incident = $holds->place('execution_audit', (int) $audit->getKey(), 'incident', $actor, 'Incident investigation');

        $blocked = app(McpHistoryRetentionService::class)->prune(now());
        $this->assertSame(0, $blocked['approvals_deleted']);
        $this->assertSame(0, $blocked['audits_deleted']);
        $this->assertDatabaseHas('mcp_approvals', ['id' => $approval->getKey()]);
        $this->assertDatabaseHas('mcp_execution_audits', ['id' => $audit->getKey()]);

        $holds->release((int) $legal->getKey(), $actor, 'Legal hold cleared');
        $holds->release((int) $incident->getKey(), $actor, 'Incident closed');

        $released = app(McpHistoryRetentionService::class)->prune(now());
        $this->assertSame(1, $released['approvals_deleted']);
        $this->assertSame(1, $released['audits_deleted']);
        $this->assertDatabaseMissing('mcp_approvals', ['id' => $approval->getKey()]);
        $this->assertDatabaseMissing('mcp_execution_audits', ['id' => $audit->getKey()]);
        $this->assertDatabaseCount('mcp_retention_holds', 2);
        $this->assertNotNull(McpRetentionHold::query()->findOrFail($legal->getKey())->released_at);
        $this->assertNotNull(McpRetentionHold::query()->findOrFail($incident->getKey())->released_at);
    }

    public function test_completed_direct_write_ledger_survives_audit_retention_and_keeps_replay_guarantees(): void
    {
        $principal = User::factory()->create();
        $clientId = (string) Str::uuid();
        request()->attributes->set('oauth_client_id', $clientId);
        $arguments = ['value' => 'retained operation', 'idempotency_key' => 'retention-ledger-replay-1'];
        $permission = $this->directPermission();
        $payloadHash = app(McpPayloadCanonicalizer::class)
            ->hash('create_task', '1.8.0', null, null, $arguments);

        $audit = $this->audit(['event_type' => 'direct_write_execution'], now()->subDays(500));
        $execution = McpDirectWriteExecution::query()->create([
            'ai_principal_id' => $principal->getKey(),
            'oauth_client_id' => $clientId,
            'tool_name' => 'create_task',
            'tool_version' => '1.8.0',
            'idempotency_key' => $arguments['idempotency_key'],
            'payload_hash' => $payloadHash,
            'status' => 'executed',
            'result' => ['record_id' => 42],
            'result_code' => 'success',
            'audit_id' => $audit->getKey(),
            'started_at' => now()->subDays(500),
            'completed_at' => now()->subDays(500),
        ]);

        $result = app(McpHistoryRetentionService::class)->prune(now());

        $this->assertSame(1, $result['audits_deleted']);
        $this->assertDatabaseMissing('mcp_execution_audits', ['id' => $audit->getKey()]);
        $this->assertDatabaseHas('mcp_direct_write_executions', [
            'id' => $execution->getKey(),
            'status' => 'executed',
            'audit_id' => null,
            'idempotency_key' => $arguments['idempotency_key'],
            'payload_hash' => $payloadHash,
        ]);

        $calls = 0;
        $replay = app(McpDirectWriteService::class)->execute(
            $principal,
            $permission,
            $arguments,
            static function () use (&$calls): void {
                $calls++;
            },
            static function () use (&$calls): array {
                $calls++;

                return ['record_id' => 99];
            },
        );

        $this->assertSame(['record_id' => 42], $replay);
        $this->assertSame(0, $calls);

        try {
            app(McpDirectWriteService::class)->execute(
                $principal,
                $permission,
                ['value' => 'changed payload', 'idempotency_key' => $arguments['idempotency_key']],
                static fn () => null,
                static fn (): array => ['record_id' => 99],
            );
            $this->fail('The retained ledger must continue rejecting idempotency payload conflicts.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('mcp_direct_write_executions', 1);
    }

    public function test_hold_evidence_is_immutable_and_reason_text_is_not_stored(): void
    {
        $principal = User::factory()->create();
        $actor = $this->retentionManager();
        $approval = $this->approval($principal, McpApproval::STATUS_EXECUTED, now()->subDays(500));
        $reason = 'Confidential legal investigation reference';

        $hold = app(McpRetentionHoldService::class)->place(
            'approval',
            (int) $approval->getKey(),
            'legal',
            $actor,
            $reason,
        );

        $this->assertSame(hash_hmac('sha256', $reason, (string) config('app.key')), $hold->reason_hash);
        $this->assertArrayNotHasKey('reason', $hold->getAttributes());
        $this->assertDatabaseHas('mcp_retention_holds', [
            'id' => $hold->getKey(),
            'reason_hash' => hash_hmac('sha256', $reason, (string) config('app.key')),
        ]);

        try {
            $hold->hold_type = 'incident';
            $hold->save();
            $this->fail('Hold placement evidence must be immutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('mcp_retention_holds', ['id' => $hold->getKey(), 'hold_type' => 'legal']);
        }

        try {
            $hold->delete();
            $this->fail('Retention hold history must not be deletable through Eloquent.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('mcp_retention_holds', ['id' => $hold->getKey()]);
        }
    }

    public function test_users_without_the_retention_permission_cannot_place_holds(): void
    {
        $principal = User::factory()->create();
        $actor = User::factory()->create(['email' => 'unauthorized@example.test']);
        $approval = $this->approval($principal, McpApproval::STATUS_EXECUTED, now()->subDays(500));

        $this->expectException(AuthorizationException::class);

        app(McpRetentionHoldService::class)->place('approval', (int) $approval->getKey(), 'legal', $actor, 'Not authorized');
    }

    public function test_unresolved_direct_write_ledger_prevents_its_audit_from_being_pruned(): void
    {
        $principal = User::factory()->create();
        $audit = $this->audit(['event_type' => 'direct_write_execution'], now()->subDays(500));
        $execution = McpDirectWriteExecution::query()->create([
            'ai_principal_id' => $principal->getKey(),
            'oauth_client_id' => (string) Str::uuid(),
            'tool_name' => 'create_task',
            'tool_version' => '1.8.0',
            'idempotency_key' => 'retention-uncertain-1',
            'payload_hash' => str_repeat('b', 64),
            'status' => 'uncertain',
            'audit_id' => $audit->getKey(),
            'started_at' => now()->subDays(500),
            'completed_at' => now()->subDays(500),
        ]);

        $result = app(McpHistoryRetentionService::class)->prune(now());

        $this->assertSame(0, $result['audits_deleted']);
        $this->assertDatabaseHas('mcp_execution_audits', ['id' => $audit->getKey()]);
        $this->assertDatabaseHas('mcp_direct_write_executions', ['id' => $execution->getKey(), 'audit_id' => $audit->getKey()]);
    }

    private function approval(User $principal, string $status, $createdAt): McpApproval
    {
        $approval = McpApproval::query()->create([
            'ai_principal_id' => $principal->getKey(),
            'oauth_client_id' => (string) Str::uuid(),
            'tool_name' => 'create_task',
            'tool_version' => '1.8.0',
            'idempotency_key' => Str::uuid()->toString(),
            'payload_hash' => str_repeat('a', 64),
            'arguments' => ['title' => 'Retention test'],
            'preview' => ['action' => 'Create task'],
            'status' => $status,
            'expires_at' => $createdAt->copy()->addDay(),
            'created_at' => $createdAt,
        ]);
        DB::table('mcp_approvals')->where('id', $approval->getKey())->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $approval->refresh();
    }

    private function audit(array $attributes, $startedAt): McpExecutionAudit
    {
        $audit = McpExecutionAudit::query()->create([
            'event_type' => 'tool_invocation',
            'status' => 'completed',
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
            'created_at' => $startedAt,
            ...$attributes,
        ]);
        DB::table('mcp_execution_audits')->where('id', $audit->getKey())->update([
            'started_at' => $startedAt,
            'completed_at' => $attributes['completed_at'] ?? $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        return $audit->refresh();
    }

    private function retentionManager(): User
    {
        $actor = User::factory()->create();
        $role = HexaRole::query()->create([
            'name' => 'MCP Retention Manager',
            'created_by_name' => 'Test',
            'access' => ['system' => ['mcp_execution_audit.retention_manage']],
            'guard' => hexa()->guard(),
        ]);
        $actor->roles()->attach($role->getKey());

        return $actor;
    }

    private function directPermission(): McpToolPermission
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
