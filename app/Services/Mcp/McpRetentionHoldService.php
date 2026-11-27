<?php

namespace App\Services\Mcp;

use App\Models\Mcp\McpApproval;
use App\Models\Mcp\McpExecutionAudit;
use App\Models\Mcp\McpRetentionHold;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class McpRetentionHoldService
{
    public function __construct(private readonly McpApprovalAccess $access) {}

    public function place(string $recordType, int $recordId, string $holdType, User $actor, string $reason): McpRetentionHold
    {
        $this->authorize($actor);
        $this->validateInput($recordType, $holdType, $reason);

        return DB::transaction(function () use ($recordType, $recordId, $holdType, $actor, $reason): McpRetentionHold {
            $this->lockRecord($recordType, $recordId);

            $existing = McpRetentionHold::query()
                ->where('record_type', $recordType)
                ->where('record_id', $recordId)
                ->whereNull('released_at')
                ->where('hold_type', $holdType)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages(['hold' => 'An active hold of this type already exists for the record.']);
            }

            return McpRetentionHold::query()->create([
                'record_type' => $recordType,
                'record_id' => $recordId,
                'hold_type' => $holdType,
                'reason_hash' => hash_hmac('sha256', trim($reason), (string) config('app.key')),
                'placed_by' => $actor->getKey(),
                'placed_at' => now(),
            ]);
        });
    }

    public function release(int $holdId, User $actor, string $reason): McpRetentionHold
    {
        $this->authorize($actor);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to release a retention hold.']);
        }

        $reference = McpRetentionHold::query()->findOrFail($holdId);

        return DB::transaction(function () use ($reference, $holdId, $actor, $reason): McpRetentionHold {
            $this->lockRecord($reference->record_type, (int) $reference->record_id);
            $hold = McpRetentionHold::query()->whereKey($holdId)->lockForUpdate()->firstOrFail();

            if ($hold->released_at !== null) {
                throw ValidationException::withMessages(['hold' => 'This retention hold has already been released.']);
            }

            $hold->forceFill([
                'released_by' => $actor->getKey(),
                'released_at' => now(),
                'release_reason_hash' => hash_hmac('sha256', trim($reason), (string) config('app.key')),
            ])->save();

            return $hold->refresh();
        });
    }

    private function authorize(User $actor): void
    {
        if (! $this->access->canManageRetention($actor)) {
            throw new AuthorizationException('The user is not authorized to manage MCP retention holds.');
        }
    }

    private function validateInput(string $recordType, string $holdType, string $reason): void
    {
        if (! in_array($recordType, ['approval', 'execution_audit'], true)) {
            throw ValidationException::withMessages(['record_type' => 'Only MCP approval and execution audit records can be held.']);
        }

        if (! in_array($holdType, ['legal', 'incident'], true)) {
            throw ValidationException::withMessages(['hold_type' => 'The hold type must be legal or incident.']);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to place a retention hold.']);
        }
    }

    private function lockRecord(string $recordType, int $recordId): Model
    {
        $model = match ($recordType) {
            'approval' => McpApproval::class,
            'execution_audit' => McpExecutionAudit::class,
            default => throw ValidationException::withMessages(['record_type' => 'Unsupported MCP retention record type.']),
        };

        return $model::query()->whereKey($recordId)->lockForUpdate()->firstOrFail();
    }
}
