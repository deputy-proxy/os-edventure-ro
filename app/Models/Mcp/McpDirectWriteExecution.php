<?php

namespace App\Models\Mcp;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property \Illuminate\Support\Carbon $started_at @property \Illuminate\Support\Carbon|null $completed_at */
final class McpDirectWriteExecution extends Model
{
    protected $fillable = [
        'ai_principal_id', 'oauth_client_id', 'tool_name', 'tool_version',
        'request_id', 'idempotency_key', 'payload_hash', 'target_type',
        'target_id', 'status', 'result', 'result_code', 'error_code',
        'audit_id', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'encrypted:array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $execution): void {
            foreach (['ai_principal_id', 'oauth_client_id', 'tool_name', 'tool_version', 'request_id', 'idempotency_key', 'payload_hash', 'target_type', 'target_id', 'started_at', 'created_at'] as $field) {
                if ($execution->getRawOriginal($field) !== ($execution->getAttributes()[$field] ?? null)) {
                    throw new LogicException('MCP direct-write identity and payload fields are immutable.');
                }
            }

            if ($execution->getRawOriginal('audit_id') !== null
                && $execution->getRawOriginal('audit_id') !== ($execution->getAttributes()['audit_id'] ?? null)) {
                throw new LogicException('The MCP direct-write audit reference cannot be overwritten.');
            }

            $from = $execution->getRawOriginal('status');
            $to = $execution->getAttributes()['status'] ?? null;
            if ($from !== $to && ($from !== 'executing' || ! in_array($to, ['executed', 'failed', 'uncertain'], true))) {
                throw new LogicException('The MCP direct-write lifecycle transition is not permitted.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('MCP direct-write execution history cannot be deleted.');
        });
    }

    public function aiPrincipal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ai_principal_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(McpExecutionAudit::class, 'audit_id');
    }
}
