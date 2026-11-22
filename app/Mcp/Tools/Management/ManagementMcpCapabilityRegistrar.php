<?php

namespace App\Mcp\Tools\Management;

use App\Mcp\Authorization\AuthorizedMcpCapabilityRegistrar;
use App\Mcp\Authorization\McpAuthorizationService;
use App\Mcp\Authorization\McpFieldFilter;
use App\Mcp\Authorization\McpToolPermissionRegistry;
use App\Mcp\Tools\Executive\ExecutiveMcpCapabilityRegistrar;
use App\Mcp\Tools\Finance\FinanceMcpCapabilityRegistrar;
use App\Models\User;
use App\Services\Mcp\McpExecutionAuditRecorder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Server\Builder;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;

final class ManagementMcpCapabilityRegistrar extends AuthorizedMcpCapabilityRegistrar
{
    public function __construct(
        McpToolPermissionRegistry $permissions,
        McpAuthorizationService $authorization,
        McpFieldFilter $fieldFilter,
        McpExecutionAuditRecorder $audit,
        private readonly ExecutiveMcpCapabilityRegistrar $executive,
        private readonly FinanceMcpCapabilityRegistrar $finance,
    ) {
        parent::__construct($permissions, $authorization, $fieldFilter, $audit);
    }

    public function register(Builder $builder): void
    {
        $this->addAuthorizedResource(
            $builder,
            'get_executive_overview',
            'management://overview',
            'management_overview',
            'Permission-filtered executive overview from the existing management overview service.',
            fn (User $principal): array => $this->executive->resourceExecutiveOverview($principal),
        );
        $this->addAuthorizedResource(
            $builder,
            'get_management_kpis',
            'management://kpis',
            'management_kpis',
            'Current KPI catalogue, including source, period, freshness, unit and approval metadata.',
            fn (User $principal): array => $this->executive->resourceManagementKpis($principal),
        );
        $this->addAuthorizedResource(
            $builder,
            'get_financial_overview',
            'finance://current-position',
            'finance_current_position',
            'Current and previous reporting-month finance overview, preserving source permissions, currencies and unresolved values.',
            fn (User $principal): array => $this->finance->resourceFinancialOverview($principal),
        );
        $this->addAuthorizedResource(
            $builder,
            'get_financial_overview',
            'finance://reporting-rules',
            'finance_reporting_rules',
            'Read-only finance reporting semantics and known data limitations. This resource contains policy metadata, not live financial figures.',
            fn (User $principal): array => $this->financeReportingRules(),
            [
                'status' => true,
                'resource_type' => true,
                'source' => true,
                'timezone' => true,
                'rules' => [
                    'currency' => true,
                    'unknown_values' => true,
                    'legacy_units' => true,
                    'receivables' => true,
                    'payables' => true,
                    'actuals_and_forecasts' => true,
                    'budget_comparisons' => true,
                    'profitability' => true,
                    'reconciliation' => true,
                    'invoice_details' => true,
                ],
            ],
        );
        $this->addAuthorizedResource(
            $builder,
            'get_management_priorities',
            'operations://priorities',
            'operations_priorities',
            'Visible overdue and upcoming tasks, ordered by due date under existing task and project visibility rules.',
            fn (User $principal): array => $this->executive->resourceManagementPriorities($principal),
        );

        foreach ($this->prompts() as $name => $definition) {
            $builder->add(
                new Prompt(
                    name: $name,
                    title: $definition['title'],
                    description: $definition['description'],
                    arguments: [
                        new PromptArgument('reporting_period', 'Reporting period to review; the underlying source tools remain authoritative.', false),
                        new PromptArgument('timezone', 'IANA timezone for the requested review. Preserve source timezone metadata.', false),
                        new PromptArgument('context', 'Optional focus or management context for the review.', false),
                    ],
                ),
                new class($definition) implements PromptHandlerInterface
                {
                    public function __construct(private readonly array $definition) {}

                    public function get(array $arguments, ClientGateway $gateway): array
                    {
                        $period = trim((string) ($arguments['reporting_period'] ?? 'current reporting period'));
                        $timezone = trim((string) ($arguments['timezone'] ?? 'the timezone reported by each tool'));
                        $context = trim((string) ($arguments['context'] ?? ''));

                        $body = $this->definition['instructions']
                        ."\n\nReporting period: {$period}.\nReporting timezone: {$timezone}.\n";
                        if ($context !== '') {
                            $body .= "\nRequested context: {$context}.\n";
                        }

                        $body .= "\nUse only live results returned by the available os-edventure-ro MCP tools. Call each relevant tool before making factual claims. Tool availability and authorization are determined at call time; do not infer that a tool is permitted merely because this prompt is available. Report denied, missing, stale, unsupported or unresolved sources explicitly. Do not substitute zero for unknown, combine currencies, reconstruct unsupported historical periods, infer an approval, or invent a score. Clearly separate source facts from analysis and identify each source, as-of time, period, timezone, unit and freshness where supplied.";

                        return [
                            'role' => 'user',
                            'content' => $body,
                        ];
                    }
                },
            );
        }
    }

    /**
     * @param  Closure(User): array<string, mixed>  $reader
     */
    private function addAuthorizedResource(
        Builder $builder,
        string $sourceTool,
        string $uri,
        string $name,
        string $description,
        Closure $reader,
        ?array $resourceAllowlist = null,
    ): void {
        $permission = $this->permissions->forTool($sourceTool);
        if ($permission->operationType !== 'read') {
            throw new \LogicException('MCP resources may only reuse read-only tool permissions.');
        }

        $authorization = $this->authorization;
        $fieldFilter = $this->fieldFilter;
        $audit = $this->audit;
        $outputAllowlist = $resourceAllowlist ?? $permission->allowedFields;

        $handler = Closure::bind(
            static function (array $arguments) use ($permission, $authorization, $fieldFilter, $audit, $reader, $uri, $outputAllowlist): array {
                $principal = Auth::guard('api')->user();
                $resourceArguments = ['uri' => $uri];
                $auditRecord = $audit->startToolInvocation(
                    $principal instanceof User ? $principal : null,
                    'resource.'.$permission->toolName,
                    $resourceArguments,
                );

                try {
                    if (! $principal instanceof User) {
                        throw new AuthorizationException('An authenticated MCP service identity is required.');
                    }

                    $authorization->authorizeTool($principal, $permission);
                    $result = $reader($principal);
                    $filtered = $fieldFilter->filter(
                        $result,
                        $outputAllowlist,
                    );
                    $audit->completeToolInvocation($auditRecord, $result);

                    return $filtered;
                } catch (\Throwable $exception) {
                    $audit->failToolInvocation($auditRecord, $exception);

                    throw $exception;
                }
            },
            null,
            ReferenceHandler::class,
        );

        $builder->addResource(
            handler: $handler,
            uri: $uri,
            name: $name,
            title: $name,
            description: $description,
            mimeType: 'application/json',
        );
    }

    /** @return array<string, array{title: string, description: string, instructions: string}> */
    private function prompts(): array
    {
        return [
            'daily_ceo_briefing' => [
                'title' => 'Daily CEO briefing',
                'description' => 'Build a concise daily executive briefing from current authorized overview, KPI and operational sources.',
                'instructions' => 'Prepare a daily CEO briefing. Retrieve get_executive_overview, get_management_kpis and get_management_priorities when those tools are available. Summarize material changes and visible overdue/upcoming work. Do not invent a composite business-health score; the KPI catalogue may be provisional. Do not present unauthorized domains as zero.',
            ],
            'weekly_management_review' => [
                'title' => 'Weekly management review',
                'description' => 'Review current management signals and operational delivery using authorized live sources.',
                'instructions' => 'Prepare a weekly management review. Retrieve get_executive_overview, get_management_kpis, get_management_priorities, get_operational_bottlenecks and relevant project/task tools when available. Use get_period_comparison only to disclose its actual returned support status; historical KPI reconstruction may be unavailable. Distinguish seven-day task outlook from an actual historical weekly KPI series.',
            ],
            'financial_review' => [
                'title' => 'Financial review',
                'description' => 'Review live financial position and source coverage without flattening currencies or unresolved amounts.',
                'instructions' => 'Prepare a financial review. Retrieve get_financial_overview and relevant get_transactions, get_receivables, get_payables, get_reconciliation_status, get_budget_vs_actuals and get_profitability_report results when their inputs are available and the tools are permitted. Call get_cash_forecast only when an approved scenario_id is supplied. Preserve per-currency totals, separate actuals from forecasts, identify legacy unverified units, and state explicitly that unsupported vendor payable totals and unresolved legacy amounts remain unavailable.',
            ],
            'operations_review' => [
                'title' => 'Operations review',
                'description' => 'Review visible task, project, workload and delivery signals using existing record scopes.',
                'instructions' => 'Prepare an operations review. Retrieve get_management_priorities, get_projects, get_tasks, get_overdue_tasks, get_operational_bottlenecks and get_quarterly_objectives when available and permitted. Respect assigned-to/created-by task visibility unless task.view_all is granted. Use the due-date ordering returned by the application; do not invent a universal priority score or reveal restricted task descriptions or identities.',
            ],
            'business_growth_review' => [
                'title' => 'Business growth review',
                'description' => 'Review authorized customer, sales-pipeline and commercial performance sources.',
                'instructions' => 'Prepare a business-growth review. Retrieve get_sales_pipeline, get_customer_economics, get_management_kpis and any relevant customer tools only when available and permitted. Respect customer and record scopes. Separate observed sales-pipeline facts from interpretation; do not infer conversion, revenue or profitability values that the tools do not return.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function financeReportingRules(): array
    {
        return [
            'status' => 'available',
            'resource_type' => 'reporting_policy',
            'source' => 'docs/mcp-finance-tools.md',
            'timezone' => 'Europe/Bucharest',
            'rules' => [
                'currency' => 'Keep every amount grouped by its source currency. No exchange rate or cross-currency total is inferred.',
                'unknown_values' => 'Unknown, missing, unresolved, unsupported and unapproved amounts remain null or explicitly unavailable, never zero.',
                'legacy_units' => 'Legacy integer transaction totals are labelled legacy_integer_unverified and must not be represented as decimal currency values.',
                'receivables' => 'Supported issued-customer-invoice balances may be returned. Legacy customer invoice amounts remain unresolved.',
                'payables' => 'Vendor invoice metadata may be available, but vendor payable totals remain unavailable under the current source contract.',
                'actuals_and_forecasts' => 'Actual and projected cash flows remain separate.',
                'budget_comparisons' => 'Budget comparisons are previews and do not persist report snapshots.',
                'profitability' => 'Profitability reports read completed immutable runs. MCP does not calculate new runs.',
                'reconciliation' => 'Reconciliation candidates are suggestions, not approvals. MCP does not accept or reject matches.',
                'invoice_details' => 'Invoice details exclude party identity, postal addresses, tax identifiers, raw descriptions, internal IDs, allocation history and artifact URLs.',
            ],
        ];
    }
}
