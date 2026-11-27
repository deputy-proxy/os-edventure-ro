<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpHistoryRetentionService;
use Illuminate\Console\Command;

final class PruneMcpHistory extends Command
{
    protected $signature = 'mcp:prune-history';

    protected $description = 'Delete eligible MCP audit and terminal approval history beyond the configured retention period';

    public function handle(McpHistoryRetentionService $retention): int
    {
        $result = $retention->prune();

        $this->components->info(sprintf(
            'MCP retention cutoff %s: deleted %d approval(s) and %d audit record(s); skipped %d old approval candidate(s) and %d standalone audit candidate(s) because they were not eligible.',
            $result['cutoff'],
            $result['approvals_deleted'],
            $result['audits_deleted'],
            $result['approvals_skipped'],
            $result['audits_skipped'],
        ));

        return self::SUCCESS;
    }
}
