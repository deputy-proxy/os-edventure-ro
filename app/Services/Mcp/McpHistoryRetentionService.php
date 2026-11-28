<?php

namespace App\Services\Mcp;

use App\Models\Mcp\McpApproval;
use App\Models\Mcp\McpExecutionAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class McpHistoryRetentionService
{
    /**
     * @return array{cutoff: string, approvals_deleted: int, audits_deleted: int, approvals_skipped: int, audits_skipped: int}
     */
    public function prune(?Carbon $asOf = null): array
    {
        $cutoff = ($asOf ?? now())->copy()->subDays(max(1, (int) config('mcp.audit_retention_days', 365)));
        $approvalCount = 0;
        $auditCount = 0;
        $approvalSkipped = 0;
        $auditSkipped = 0;

        $this->eligibleApprovals($cutoff)->select('id')->chunkById(100, function ($rows) use ($cutoff, &$approvalCount, &$auditCount, &$approvalSkipped): void {
            foreach ($rows as $row) {
                $result = DB::transaction(function () use ($row, $cutoff): array {
                    $approval = McpApproval::query()->whereKey($row->getKey())->lockForUpdate()->first();
                    if ($approval === null || ! $this->approvalIsEligible($approval, $cutoff)) {
                        return ['deleted' => false, 'audits' => 0];
                    }

                    if ($this->hasActiveHold('approval', (int) $approval->getKey())) {
                        return ['deleted' => false, 'audits' => 0];
                    }

                    $auditIds = McpExecutionAudit::query()
                        ->where('approval_id', $approval->getKey())
                        ->orWhere('id', $approval->execution_audit_id)
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all();

                    $audits = McpExecutionAudit::query()->whereIn('id', $auditIds)->orderBy('id')->lockForUpdate()->get();

                    foreach ($audits as $audit) {
                        if (! $this->auditIsEligible($audit, $cutoff)) {
                            return ['deleted' => false, 'audits' => 0];
                        }
                    }

                    $this->detachAndDelete($approval, $audits);

                    return ['deleted' => true, 'audits' => $audits->count()];
                });

                if ($result['deleted']) {
                    $approvalCount++;
                    $auditCount += $result['audits'];
                } else {
                    $approvalSkipped++;
                }
            }
        });

        $this->eligibleAudits($cutoff)->select('id')->chunkById(100, function ($rows) use ($cutoff, &$auditCount, &$auditSkipped): void {
            foreach ($rows as $row) {
                $deleted = DB::transaction(function () use ($row, $cutoff): bool {
                    $audit = McpExecutionAudit::query()->whereKey($row->getKey())->lockForUpdate()->first();
                    if ($audit === null || ! $this->auditIsEligible($audit, $cutoff)) {
                        return false;
                    }

                    $linkedApproval = McpApproval::query()
                        ->where('execution_audit_id', $audit->getKey())
                        ->lockForUpdate()
                        ->first();

                    if ($linkedApproval !== null) {
                        if (! $this->approvalIsEligible($linkedApproval, $cutoff)
                            || $this->hasActiveHold('approval', (int) $linkedApproval->getKey())) {
                            return false;
                        }

                        DB::table('mcp_approvals')
                            ->where('id', $linkedApproval->getKey())
                            ->update(['execution_audit_id' => null]);
                        DB::table('mcp_approvals')
                            ->where('id', $linkedApproval->getKey())
                            ->delete();
                    }

                    DB::table('mcp_execution_audits')->where('id', $audit->getKey())->delete();

                    return true;
                });

                if ($deleted) {
                    $auditCount++;
                } else {
                    $auditSkipped++;
                }
            }
        });

        return [
            'cutoff' => $cutoff->toDateTimeString(),
            'approvals_deleted' => $approvalCount,
            'audits_deleted' => $auditCount,
            'approvals_skipped' => $approvalSkipped,
            'audits_skipped' => $auditSkipped,
        ];
    }

    /** @return Builder<McpApproval> */
    private function eligibleApprovals(Carbon $cutoff): Builder
    {
        return McpApproval::query()
            ->whereIn('status', [
                McpApproval::STATUS_REJECTED,
                McpApproval::STATUS_EXPIRED,
                McpApproval::STATUS_CANCELLED,
                McpApproval::STATUS_EXECUTED,
                McpApproval::STATUS_EXECUTION_BLOCKED,
            ])
            ->where('created_at', '<=', $cutoff);
    }

    /** @return Builder<McpExecutionAudit> */
    private function eligibleAudits(Carbon $cutoff): Builder
    {
        return McpExecutionAudit::query()
            ->whereNull('approval_id')
            ->whereIn('status', ['completed', 'succeeded', 'failed'])
            ->where('started_at', '<=', $cutoff);
    }

    private function approvalIsEligible(McpApproval $approval, Carbon $cutoff): bool
    {
        return in_array($approval->status, [
            McpApproval::STATUS_REJECTED,
            McpApproval::STATUS_EXPIRED,
            McpApproval::STATUS_CANCELLED,
            McpApproval::STATUS_EXECUTED,
            McpApproval::STATUS_EXECUTION_BLOCKED,
        ], true) && $approval->created_at->lte($cutoff);
    }

    private function auditIsEligible(McpExecutionAudit $audit, Carbon $cutoff): bool
    {
        if (! in_array($audit->status, ['completed', 'succeeded', 'failed'], true)
            || $audit->started_at->gt($cutoff)
            || $this->hasActiveHold('execution_audit', (int) $audit->getKey())) {
            return false;
        }

        $executions = DB::table('mcp_direct_write_executions')
            ->where('audit_id', $audit->getKey())
            ->lockForUpdate()
            ->get(['status']);

        return $executions->every(static fn (object $execution): bool => in_array($execution->status, ['executed', 'failed'], true));
    }

    private function hasActiveHold(string $recordType, int $recordId): bool
    {
        return DB::table('mcp_retention_holds')
            ->where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->whereNull('released_at')
            ->exists();
    }

    /**
     * @param  EloquentCollection<int, McpExecutionAudit>  $audits
     */
    private function detachAndDelete(McpApproval $approval, $audits): void
    {
        // The retention worker is the sole privileged path that bypasses model-level deletion guards.
        $ids = $audits->modelKeys();

        if ($ids !== []) {
            DB::table('mcp_execution_audits')->whereIn('id', $ids)->update(['approval_id' => null]);
        }

        DB::table('mcp_approvals')->where('id', $approval->getKey())->update(['execution_audit_id' => null]);
        DB::table('mcp_approvals')->where('id', $approval->getKey())->delete();

        if ($ids !== []) {
            DB::table('mcp_execution_audits')->whereIn('id', $ids)->delete();
        }
    }
}
