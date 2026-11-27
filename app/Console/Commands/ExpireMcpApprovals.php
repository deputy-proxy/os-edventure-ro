<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpApprovalService;
use App\Services\Mcp\McpDirectWriteService;
use Illuminate\Console\Command;

final class ExpireMcpApprovals extends Command
{
    protected $signature = 'mcp:expire-approvals';

    protected $description = 'Expire legacy MCP approvals and mark interrupted approval/direct executions uncertain';

    public function handle(McpApprovalService $approvals, McpDirectWriteService $directWrites): int
    {
        $expired = $approvals->expirePending();
        $uncertain = $approvals->markStaleExecutionsUncertain();
        $directUncertain = $directWrites->markStaleExecutionsUncertain();

        $this->components->info("Expired {$expired} pending MCP approval(s); marked {$uncertain} legacy approval execution(s) and {$directUncertain} direct write(s) uncertain.");

        return self::SUCCESS;
    }
}
