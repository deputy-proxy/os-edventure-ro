<?php

namespace App\Services\Mcp;

use App\Mcp\Authorization\McpToolPermission;
use App\Models\Mcp\McpDirectWriteExecution;
use App\Models\Mcp\McpExecutionAudit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class McpDirectWriteService
{
    public function __construct(
        private readonly McpPayloadCanonicalizer $canonicalizer,
        private readonly McpExecutionAuditRecorder $audit,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @param callable(): mixed $validator
     * @param callable(): array<string, mixed> $executor
     * @return array<string, mixed>
     */
    public function execute(
        User $principal,
        McpToolPermission $permission,
        array $arguments,
        callable $validator,
        callable $executor,
    ): array {
        $key = $arguments['idempotency_key'] ?? null;
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid idempotency_key is required for MCP writes.']);
        }

        $clientId = request()->attributes->get('oauth_client_id');
        if (! is_string($clientId) || ! \Illuminate\Support\Str::isUuid($clientId)) {
            throw ValidationException::withMessages(['oauth_client_id' => 'The authenticated OAuth client could not be established.']);
        }

        $encodedArguments = json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encodedArguments) > (int) config('mcp.direct_write_max_payload_bytes', 65536)) {
            throw ValidationException::withMessages(['arguments' => 'The MCP direct-write payload exceeds its configured size limit.']);
        }

        $toolVersion = (string) config('mcp.permission_matrix_version', '1.0.0');
        $payloadHash = $this->canonicalizer->hash($permission->toolName, $toolVersion, null, null, $arguments);
        $existing = $this->findExisting($principal, $clientId, $permission->toolName, $key);
        if ($existing !== null) {
            return $this->replayOrReject($existing, $payloadHash);
        }

        $validator();

        try {
            $execution = DB::transaction(fn (): McpDirectWriteExecution => McpDirectWriteExecution::query()->create([
                'ai_principal_id' => $principal->getKey(),
                'oauth_client_id' => $clientId,
                'tool_name' => $permission->toolName,
                'tool_version' => $toolVersion,
                'request_id' => $this->requestAttribute('request_id', 100),
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
                'target_type' => $this->targetType($arguments),
                'target_id' => $this->targetId($arguments),
                'status' => 'executing',
                'started_at' => now(),
            ]));
        } catch (QueryException $exception) {
            $existing = $this->findExisting($principal, $clientId, $permission->toolName, $key);
            if ($existing === null) {
                throw $exception;
            }

            return $this->replayOrReject($existing, $payloadHash);
        }

        $audit = $this->audit->recordEvent('direct_write_execution', [
            'ai_principal_id' => $principal->getKey(),
            'oauth_client_id' => $clientId,
            'tool_name' => $permission->toolName,
            'tool_version' => $toolVersion,
            'request_id' => $execution->request_id,
            'idempotency_key' => $key,
            'payload_hash' => $payloadHash,
            'target_type' => $execution->target_type,
            'target_id' => $execution->target_id,
            'status' => 'started',
            'started_at' => $execution->started_at,
            'completed_at' => null,
        ]);
        $execution->forceFill(['audit_id' => $audit->getKey()])->save();

        try {
            return DB::transaction(function () use ($execution, $executor, $audit): array {
                $lockedExecution = McpDirectWriteExecution::query()
                    ->whereKey($execution->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedExecution->status !== 'executing') {
                    throw ValidationException::withMessages([
                        'execution' => 'This execution claim is no longer active and cannot mutate domain data.',
                    ]);
                }

                $result = $executor();
                $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (strlen($encoded) > (int) config('mcp.direct_write_max_result_bytes', 65536)) {
                    throw new \LengthException('The MCP direct-write result exceeds its configured size limit.');
                }

                $lockedExecution->forceFill([
                    'status' => 'executed',
                    'result' => $result,
                    'result_code' => 'success',
                    'completed_at' => now(),
                ])->save();
                $audit->forceFill([
                    'status' => 'succeeded',
                    'result_code' => 'success',
                    'domain_event_reference' => $this->safeReference($result['domain_event_reference'] ?? null),
                    'completed_at' => now(),
                ])->save();

                return $result;
            });
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $exception) {
            $execution->refresh();
            $audit->refresh();
            if ($execution->status === 'executing') {
                $this->finish($execution, $audit, 'failed', 'domain_validation_or_authorization_failed');
            }
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('MCP direct-write execution outcome is uncertain.', [
                'execution_id' => $execution->getKey(),
                'audit_id' => $audit->getKey(),
                'tool_name' => $permission->toolName,
                'exception_class' => $exception::class,
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
            ]);
            $execution->refresh();
            $audit->refresh();
            if ($execution->status === 'executed') {
                return (array) $execution->result;
            }
            if ($execution->status === 'executing') {
                $this->finish($execution, $audit, 'uncertain', 'execution_outcome_uncertain');
            }
            throw ValidationException::withMessages([
                'execution' => 'The write outcome is uncertain. Automatic retry is blocked; reconcile the domain record and audit history first.',
            ]);
        }
    }

    private function finish(McpDirectWriteExecution $execution, McpExecutionAudit $audit, string $status, string $code): void
    {
        DB::transaction(function () use ($execution, $audit, $status, $code): void {
            $execution->forceFill(['status' => $status, 'error_code' => $code, 'completed_at' => now()])->save();
            $audit->forceFill([
                'status' => $status === 'uncertain' ? 'uncertain' : 'failed',
                'error_code' => $code,
                'completed_at' => now(),
            ])->save();
        });
    }

    public function markStaleExecutionsUncertain(): int
    {
        $marked = 0;
        $staleBefore = now()->subMinutes(max(1, (int) config('mcp.direct_write_stale_minutes', 5)));

        McpDirectWriteExecution::query()
            ->where('status', 'executing')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$marked, $staleBefore): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$marked, $staleBefore): void {
                        $locked = McpDirectWriteExecution::query()->whereKey($row->getKey())->lockForUpdate()->first();
                        if ($locked === null || $locked->status !== 'executing' || $locked->updated_at->isAfter($staleBefore)) return;

                        $locked->forceFill([
                            'status' => 'uncertain',
                            'error_code' => 'execution_interrupted',
                            'completed_at' => now(),
                        ])->save();

                        if ($locked->audit_id !== null) {
                            $audit = \App\Models\Mcp\McpExecutionAudit::query()->whereKey($locked->audit_id)->first();
                            if ($audit !== null && $audit->status === 'started') {
                                $audit->forceFill([
                                    'status' => 'uncertain',
                                    'error_code' => 'execution_interrupted',
                                    'completed_at' => now(),
                                ])->save();
                            }
                        }

                        $marked++;
                    });
                }
            });

        return $marked;
    }

    private function findExisting(User $principal, string $clientId, string $tool, string $key): ?McpDirectWriteExecution
    {
        return McpDirectWriteExecution::query()
            ->where('ai_principal_id', $principal->getKey())
            ->where('oauth_client_id', $clientId)
            ->where('tool_name', $tool)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function replayOrReject(McpDirectWriteExecution $existing, string $payloadHash): array
    {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This idempotency_key was already used with a different payload.']);
        }

        if ($existing->status === 'executed') {
            return (array) $existing->result;
        }

        throw ValidationException::withMessages([
            'execution' => 'This idempotency_key has a non-replayable execution state ['.$existing->status.']. Reconcile it before taking further action.',
        ]);
    }

    private function requestAttribute(string $key, int $max): ?string
    {
        $value = request()->attributes->get($key);
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /** @param array<string, mixed> $arguments */
    private function targetType(array $arguments): ?string
    {
        foreach (['task_id' => 'task', 'process_id' => 'process', 'customer_id' => 'customer'] as $key => $type) {
            if (isset($arguments[$key])) return $type;
        }
        return null;
    }

    /** @param array<string, mixed> $arguments */
    private function targetId(array $arguments): ?string
    {
        foreach (['task_id', 'process_id', 'customer_id'] as $key) {
            if (isset($arguments[$key]) && (is_int($arguments[$key]) || ctype_digit((string) $arguments[$key]))) {
                return mb_substr((string) $arguments[$key], 0, 191);
            }
        }
        return null;
    }

    private function safeReference(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) return null;
        $value = trim((string) $value);
        return preg_match('/^[A-Za-z0-9._:-]{1,191}$/D', $value) === 1 ? $value : null;
    }
}
