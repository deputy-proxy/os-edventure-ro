<?php

namespace App\Models\Mcp;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class McpRetentionHold extends Model
{
    protected $fillable = [
        'record_type',
        'record_id',
        'hold_type',
        'reason_hash',
        'placed_by',
        'placed_at',
        'released_by',
        'released_at',
        'release_reason_hash',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $hold): void {
            if (! in_array($hold->record_type, ['approval', 'execution_audit'], true)
                || ! in_array($hold->hold_type, ['legal', 'incident'], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string) $hold->reason_hash) !== 1
                || $hold->released_by !== null
                || $hold->released_at !== null
                || $hold->release_reason_hash !== null) {
                throw new LogicException('MCP retention holds require valid immutable placement evidence.');
            }
        });

        self::updating(static function (self $hold): void {
            foreach (['record_type', 'record_id', 'hold_type', 'reason_hash', 'placed_by', 'placed_at', 'created_at'] as $field) {
                if ($hold->getRawOriginal($field) !== ($hold->getAttributes()[$field] ?? null)) {
                    throw new LogicException('MCP retention hold identity and placement evidence are immutable.');
                }
            }

            $releaseFields = ['released_by', 'released_at', 'release_reason_hash'];
            $releaseValues = array_map(fn (string $field): mixed => $hold->getAttributes()[$field] ?? null, $releaseFields);
            $releaseCount = count(array_filter($releaseValues, static fn (mixed $value): bool => $value !== null));
            if ($releaseCount !== 0 && $releaseCount !== count($releaseFields)) {
                throw new LogicException('MCP retention hold release evidence must be complete.');
            }

            foreach ($releaseFields as $field) {
                $original = $hold->getRawOriginal($field);
                $current = $hold->getAttributes()[$field] ?? null;
                if ($original !== null && $original !== $current) {
                    throw new LogicException('MCP retention hold release evidence cannot be overwritten.');
                }
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('MCP retention hold history cannot be deleted.');
        });
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
