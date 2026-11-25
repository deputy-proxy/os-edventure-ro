<?php

namespace App\Mcp\Tools\Extended;

use App\Mcp\Authorization\AuthorizedMcpCapabilityRegistrar;
use App\Models\User;
use App\Services\Mcp\ExtendedDomainMcpData;
use Illuminate\Support\Facades\Validator;
use Mcp\Server\Builder;

final class ExtendedDomainMcpCapabilityRegistrar extends AuthorizedMcpCapabilityRegistrar
{
    public function register(Builder $builder): void
    {
        $data = app(ExtendedDomainMcpData::class);
        $tools = [
            'get_hr_overview' => fn (User $user): array => $this->read($user, fn (): array => $data->hrOverview()),
            'get_approved_evaluation_summary' => fn (User $user): array => $this->read($user, fn (): array => $data->evaluationSummary()),
            'get_timesheet_status' => fn (User $user, array $arguments): array => $this->timesheets($user, $data, $arguments),
            'get_payroll_period_status' => fn (User $user): array => $this->read($user, fn (): array => $data->payrollPeriodStatus()),
            'get_payroll_validation_exceptions' => fn (User $user): array => $this->read($user, fn (): array => $data->payrollValidationExceptions()),
            'get_product_catalogue' => fn (User $user): array => $this->read($user, fn (): array => $data->productCatalogue()),
            'get_product_cost_summary' => fn (User $user): array => $this->read($user, fn (): array => $data->productCosts()),
            'get_product_profitability' => fn (User $user): array => $this->read($user, fn (): array => $data->productProfitability()),
            'get_campaign_performance' => fn (User $user): array => $this->read($user, fn (): array => $data->campaignPerformance()),
            'get_google_ads_import_status' => fn (User $user): array => $this->read($user, fn (): array => $data->googleAdsImportStatus()),
            'get_market_observations' => fn (User $user): array => $this->read($user, fn (): array => $data->marketObservations()),
            'get_academic_overview' => fn (User $user): array => $this->read($user, fn (): array => $data->academicOverview()),
            'get_attendance_summary' => fn (User $user, array $arguments): array => $this->attendance($user, $data, $arguments),
            'get_group_operations' => fn (User $user): array => $this->read($user, fn (): array => $data->groupOperations()),
        ];

        $descriptions = [
            'get_hr_overview' => 'Aggregate employee counts only. No employee names, identifiers, contact details or HR notes.',
            'get_approved_evaluation_summary' => 'Approved employee evaluation counts and aggregate scores by year. No employee-level evaluations or comments.',
            'get_timesheet_status' => 'Aggregate timesheet entries and recorded hours by category for a bounded date range.',
            'get_payroll_period_status' => 'Read-only payroll period and approval-state counts. No pay amounts or payslip line details.',
            'get_payroll_validation_exceptions' => 'Read-only payroll validation exception metadata grouped by period, severity, status and code. Messages and employee identifiers are excluded.',
            'get_product_catalogue' => 'Approved product catalogue with effective-dated prices, currency and explicit missing or ambiguous price states.',
            'get_product_cost_summary' => 'Monthly allocated product costs grouped by currency using canonical decimal values; legacy unverified amounts are excluded.',
            'get_product_profitability' => 'Aggregate profitability from the latest immutable calculation run, preserving currency boundaries and completeness.',
            'get_campaign_performance' => 'Imported Google Ads performance, retaining reporting periods and currency-specific totals.',
            'get_google_ads_import_status' => 'Google Ads import freshness and batch status without account identifiers or credentials.',
            'get_market_observations' => 'Stored Market Trends observations with source, period, units, quality, verification and freshness metadata; no ingestion is triggered.',
            'get_academic_overview' => 'Aggregate student enrollment, active group membership and scheduled-session operations. No student or teacher-level records.',
            'get_attendance_summary' => 'Aggregate attendance counts by status for a bounded period. Student identifiers and free-text feedback are excluded.',
            'get_group_operations' => 'Aggregate group and scheduled-session counts by status and session type. No individual student or teacher records.',
        ];

        foreach ($tools as $name => $handler) {
            $this->addAuthorizedTool($builder, $name, $handler, $descriptions[$name], $this->schema($name));
        }
    }

    private function read(User $user, callable $reader): array
    {
        return $this->withPrincipalContext($user, $reader);
    }

    private function timesheets(User $user, ExtendedDomainMcpData $data, array $arguments): array
    {
        $values = Validator::make($arguments, [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ])->validate();

        return $this->read($user, fn (): array => $data->timesheetSummary($values['start_date'], $values['end_date']));
    }

    private function attendance(User $user, ExtendedDomainMcpData $data, array $arguments): array
    {
        $values = Validator::make($arguments, [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ])->validate();

        return $this->read($user, fn (): array => $data->attendanceSummary($values['start_date'], $values['end_date']));
    }

    private function schema(string $name): array
    {
        $properties = in_array($name, ['get_timesheet_status', 'get_attendance_summary'], true)
            ? [
                'start_date' => ['type' => 'string', 'format' => 'date'],
                'end_date' => ['type' => 'string', 'format' => 'date'],
            ]
            : [];

        return [
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $properties === [] ? [] : ['start_date', 'end_date'],
            'additionalProperties' => false,
        ];
    }
}
