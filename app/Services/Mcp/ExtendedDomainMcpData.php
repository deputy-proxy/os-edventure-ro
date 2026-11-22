<?php

namespace App\Services\Mcp;

use App\Models\CompetitorOfferObservation;
use App\Models\Employee;
use App\Models\EmployeeEvaluation;
use App\Models\EmployeePaySlip;
use App\Models\EmployeeTimesheet;
use App\Models\GoogleAdsImportBatch;
use App\Models\GoogleAdsImportConfiguration;
use App\Models\Group;
use App\Models\MarketTrend;
use App\Models\PayrollException;
use App\Models\ProductCost;
use App\Models\ProfitabilityCalculationRun;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Services\ContentManagementDashboardData;
use App\Services\MarketAnalysisDashboardData;
use App\Services\ProductsOverviewData;
use App\Services\ProfitabilityReportService;
use App\Services\StudentsDashboardData;
use App\Services\TeachersDashboardData;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ExtendedDomainMcpData
{
    private const TZ = 'Europe/Bucharest';

    public function hrOverview(): array
    {
        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'timezone' => self::TZ,
            'current_employee_count' => Employee::query()->count(),
            'deleted_employee_count' => Employee::onlyTrashed()->count(),
            'scope' => 'aggregate_only; no employee names or identifiers',
        ];
    }

    public function evaluationSummary(): array
    {
        $rows = EmployeeEvaluation::query()
            ->where('status', 'Approved')
            ->select('evaluation_year')
            ->selectRaw('COUNT(*) as approved_count')
            ->selectRaw('AVG(final_score) as average_final_score')
            ->selectRaw('COUNT(DISTINCT employee_id) as employee_count')
            ->groupBy('evaluation_year')
            ->orderByDesc('evaluation_year')
            ->limit(20)
            ->toBase()->get();

        $visible = $rows->filter(fn ($row): bool => (int) $row->employee_count >= 5)->values();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'approved_evaluations' => $visible->map(fn ($row): array => [
                'evaluation_year' => (int) $row->evaluation_year,
                'approved_count' => (int) $row->approved_count,
                'average_final_score' => $row->average_final_score === null ? null : (string) $row->average_final_score,
            ])->all(),
            'suppressed_year_buckets' => $rows->count() - $visible->count(),
            'minimum_cohort_size' => 5,
            'scope' => 'approved aggregate summaries only; small cohorts are suppressed',
        ];
    }

    public function timesheetSummary(string $startDate, string $endDate): array
    {
        $this->assertRange($startDate, $endDate, 90);
        $rows = EmployeeTimesheet::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->select('category')
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw('SUM(number_of_hours) as total_recorded_hours')
            ->selectRaw('COUNT(DISTINCT employee_id) as employee_count')
            ->groupBy('category')
            ->orderBy('category')
            ->toBase()->get();

        $employeeCount = EmployeeTimesheet::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->distinct()->count('employee_id');
        $visible = $rows->filter(fn ($row): bool => (int) $row->employee_count >= 5)->values();

        return [
            'from' => $startDate,
            'to' => $endDate,
            'timezone' => self::TZ,
            'total_entries' => $employeeCount >= 5 ? (int) $rows->sum('entry_count') : null,
            'total_recorded_hours' => $employeeCount >= 5 ? (int) $rows->sum('total_recorded_hours') : null,
            'categories' => $visible->map(fn ($row): array => [
                'category' => (string) $row->category,
                'entry_count' => (int) $row->entry_count,
                'recorded_hours' => (int) $row->total_recorded_hours,
            ])->all(),
            'suppressed_category_count' => $rows->count() - $visible->count(),
            'minimum_cohort_size' => 5,
            'scope' => 'aggregate only; no employee or session identifiers; small cohorts are suppressed',
        ];
    }

    public function payrollPeriodStatus(): array
    {
        $periods = EmployeePaySlip::query()
            ->select('month', 'status', 'approval_status')
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('COUNT(DISTINCT employee_id) as employee_count')
            ->groupBy('month', 'status', 'approval_status')
            ->orderByDesc('month')
            ->limit(120)
            ->toBase()->get();
        $visible = $periods->filter(fn ($row): bool => (int) $row->employee_count >= 5)->values();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'periods' => $visible->map(fn ($row): array => [
                'period' => substr((string) $row->month, 0, 7),
                'status' => (string) $row->status,
                'approval_status' => (string) ($row->approval_status ?? 'unknown'),
                'record_count' => (int) $row->record_count,
            ])->all(),
            'suppressed_period_buckets' => $periods->count() - $visible->count(),
            'minimum_cohort_size' => 5,
        ];
    }

    public function payrollValidationExceptions(): array
    {
        $exceptions = PayrollException::query()
            ->select('period_start', 'period_end', 'severity', 'status', 'code')
            ->selectRaw('COUNT(*) as exception_count')
            ->selectRaw('COUNT(DISTINCT employee_id) as employee_count')
            ->groupBy('period_start', 'period_end', 'severity', 'status', 'code')
            ->orderByDesc('period_start')
            ->limit(200)
            ->toBase()->get();
        $visible = $exceptions->filter(fn ($row): bool => (int) $row->employee_count >= 5)->values();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'exceptions' => $visible->map(fn ($row): array => [
                'period_start' => filled($row->period_start) ? CarbonImmutable::parse($row->period_start)->toDateString() : null,
                'period_end' => filled($row->period_end) ? CarbonImmutable::parse($row->period_end)->toDateString() : null,
                'severity' => (string) $row->severity,
                'status' => (string) $row->status,
                'code' => (string) $row->code,
                'count' => (int) $row->exception_count,
            ])->all(),
            'suppressed_exception_buckets' => $exceptions->count() - $visible->count(),
            'minimum_cohort_size' => 5,
        ];
    }

    public function productCatalogue(): array
    {
        $summary = app(ProductsOverviewData::class)->summary(CarbonImmutable::now(self::TZ));

        $products = $summary['products']->take(100)->values();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'total' => $summary['total'],
            'returned_count' => $products->count(),
            'truncated' => $summary['total'] > $products->count(),
            'with_effective_price' => $summary['with_effective_price'],
            'missing_price' => $summary['missing_price'],
            'ambiguous_price' => $summary['ambiguous_price'],
            'products' => $products->map(fn (array $row): array => [
                'title' => $row['title'],
                'status' => $row['status'],
                'type' => $row['type'],
                'productable_title' => $row['productable_title'],
                'price_state' => $row['price_state'],
                'price' => $row['price'],
                'currency' => $row['currency'],
                'availability' => $row['availability'],
                'valid_from' => $row['valid_from'],
                'valid_until' => $row['valid_until'],
            ])->values()->all(),
            'price_semantics' => 'effective on the as-of date; ambiguous and missing prices are not inferred',
        ];
    }

    public function productCosts(): array
    {
        $rows = ProductCost::query()
            ->whereNotNull('allocated_amount_decimal')
            ->select('month', 'currency_code')
            ->selectRaw('SUM(allocated_amount_decimal) as allocated_cost')
            ->selectRaw('COUNT(*) as allocation_count')
            ->groupBy('month', 'currency_code')
            ->orderByDesc('month')
            ->limit(120)
            ->toBase()->get();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'costs' => $rows->map(fn ($row): array => [
                'month' => filled($row->month) ? CarbonImmutable::parse($row->month)->toDateString() : null,
                'currency_code' => $row->currency_code,
                'allocated_cost' => $row->allocated_cost === null ? null : (string) $row->allocated_cost,
                'allocation_count' => (int) $row->allocation_count,
                'basis' => 'canonical_decimal_allocated_cost',
            ])->all(),
            'legacy_cost_rows' => ProductCost::query()->whereNull('allocated_amount_decimal')->count(),
            'legacy_costs' => 'excluded from monetary totals because canonical decimal amount is unavailable',
        ];
    }

    public function productProfitability(): array
    {
        $run = ProfitabilityCalculationRun::query()->orderByDesc('created_at')->first();
        if (! $run) {
            return ['availability' => 'unavailable_no_profitability_run'];
        }

        $report = app(ProfitabilityReportService::class)->report($run, includeCustomerDetails: false);

        return [
            'availability' => 'available',
            'run_created_at' => CarbonImmutable::parse($run->created_at)->toIso8601String(),
            'run_status' => $run->status,
            'period_start' => CarbonImmutable::parse($run->period_start)->toDateString(),
            'period_end' => CarbonImmutable::parse($run->period_end)->toDateString(),
            'currency_totals' => collect($report['currency_totals'])->map(fn (array $totals, string $currency): array => [
                'currency_code' => $currency, ...$totals,
            ])->values()->all(),
            'exception_count' => count($report['exceptions']),
            'project_dimension' => $report['project_dimension'],
            'customer_details_available' => false,
            'basis' => 'latest immutable profitability calculation run',
        ];
    }

    public function campaignPerformance(): array
    {
        $permissions = ['competitor_offer.index' => false, 'competitor.index' => false, 'market_trend.index' => false, 'google_ads_metrics.view' => true, 'paid_campaign.index' => true];
        $contentPermissions = ['paid_campaign.index' => true];
        $market = app(MarketAnalysisDashboardData::class)->summary($permissions, CarbonImmutable::now(self::TZ));
        $content = app(ContentManagementDashboardData::class)->summary($contentPermissions, CarbonImmutable::now(self::TZ));
        $campaigns = $content['content_types']['paid_campaigns'];

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'paid_campaigns' => [
                'current_count' => $campaigns['current_count'],
                'status_buckets' => $campaigns['status_buckets'],
                'upcoming_count' => $campaigns['upcoming_count'],
                'recent_published_count' => $campaigns['recent_published_count'],
            ],
            'google_ads' => [
                'from' => $market['google_ads']['from'],
                'to' => $market['google_ads']['to'],
                'row_count' => $market['google_ads']['row_count'],
                'campaign_count' => $market['google_ads']['campaign_count'],
                'latest_imported_at' => $market['google_ads']['latest_imported_at'],
                'currency_totals' => $market['google_ads']['currency_totals'],
                'campaign_rows' => $market['google_ads']['campaign_rows'],
                'source' => 'Google Ads API import',
                'unit_notes' => 'cost_micros is in micros of the reported currency; currency totals are not combined',
            ],
        ];
    }

    public function googleAdsImportStatus(): array
    {
        $configurations = GoogleAdsImportConfiguration::query()
            ->withCount('importBatches')
            ->orderBy('name')
            ->get();
        $batches = GoogleAdsImportBatch::query()->orderByDesc('started_at')->limit(50)->get();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'configurations' => $configurations->map(fn ($configuration): array => [
                'active' => (bool) $configuration->is_active,
                'status' => $configuration->configurationStatus(),
                'last_status' => (string) $configuration->last_status,
                'last_checked_at' => $configuration->last_checked_at?->toIso8601String(),
                'last_error_code' => $configuration->last_error_code,
                'batch_count' => (int) $configuration->import_batches_count,
            ])->all(),
            'batches' => $batches->map(fn ($batch): array => [
                'period_start' => $batch->period_start?->toDateString(),
                'period_end' => $batch->period_end?->toDateString(),
                'source' => $batch->source_name,
                'source_version' => $batch->source_version,
                'status' => $batch->status,
                'row_count' => (int) $batch->row_count,
                'error_code' => $batch->error_code,
                'started_at' => $batch->started_at->toIso8601String(),
                'completed_at' => $batch->completed_at?->toIso8601String(),
            ])->all(),
            'privacy_note' => 'Account/customer identifiers and credential material are excluded.',
        ];
    }

    public function marketObservations(): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $trends = MarketTrend::query()->where('status', 'active')
            ->orderByDesc('observed_at')->limit(50)
            ->toBase()->get(['name', 'category', 'metric_key', 'value', 'unit', 'market', 'segment', 'period_start', 'period_end', 'source_name', 'source_url', 'observed_at', 'change_pct', 'confidence', 'data_status', 'source_type', 'freshness_status', 'verification_status', 'source_reference', 'source_revision', 'missing_reason', 'methodology']);
        $offers = CompetitorOfferObservation::query()->orderByDesc('observed_at')->limit(50)
            ->toBase()->get(['title', 'offer_type', 'offer_price', 'currency', 'price_basis', 'source_url', 'valid_from', 'valid_until', 'observed_at', 'source_status']);

        return [
            'as_of' => $now->toIso8601String(),
            'market_trends' => $trends->map(fn ($trend): array => [
                'name' => $trend->name,
                'category' => $trend->category,
                'metric_key' => $trend->metric_key,
                'value' => $trend->value,
                'unit' => $trend->unit,
                'market' => $trend->market,
                'segment' => $trend->segment,
                'period_start' => CarbonImmutable::parse($trend->period_start)->toDateString(),
                'period_end' => CarbonImmutable::parse($trend->period_end)->toDateString(),
                'source_name' => $trend->source_name,
                'source_url' => $trend->source_url,
                'observed_at' => CarbonImmutable::parse($trend->observed_at)->toIso8601String(),
                'change_pct' => $trend->change_pct,
                'confidence' => $trend->confidence,
                'data_status' => $trend->data_status,
                'source_type' => $trend->source_type,
                'freshness_status' => $trend->freshness_status,
                'verification_status' => $trend->verification_status,
                'source_reference' => $trend->source_reference,
                'source_revision' => $trend->source_revision,
                'missing_reason' => $trend->missing_reason,
                'methodology' => $trend->methodology,
            ])->all(),
            'competitor_observations' => $offers->map(fn ($offer): array => [
                'title' => $offer->title,
                'offer_type' => $offer->offer_type,
                'offer_price' => $offer->offer_price,
                'currency' => $offer->currency,
                'price_basis' => $offer->price_basis,
                'source_url' => $offer->source_url,
                'valid_from' => filled($offer->valid_from) ? CarbonImmutable::parse($offer->valid_from)->toDateString() : null,
                'valid_until' => filled($offer->valid_until) ? CarbonImmutable::parse($offer->valid_until)->toDateString() : null,
                'observed_at' => CarbonImmutable::parse($offer->observed_at)->toIso8601String(),
                'source_status' => $offer->source_status,
            ])->all(),
            'market_trends_source_note' => 'Stored observations only; no ingestion or live market source is invoked by MCP',
        ];
    }

    public function academicOverview(): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $students = app(StudentsDashboardData::class)->summary($now, canViewStudents: true);
        $teachers = app(TeachersDashboardData::class)->summary([
            'employee.index' => false,
            'group.index' => true,
            'session.index' => true,
        ], $now);

        return [
            'as_of' => $now->toIso8601String(),
            'timezone' => self::TZ,
            'student_count' => $students['current_students'],
            'current_group_memberships' => $students['current_group_memberships'],
            'groups_with_current_memberships' => $students['groups_with_current_memberships'],
            'enrollment_trend' => $students['enrollment_trend']->map(fn (array $row): array => [
                'date' => $row['date'],
                'count' => $row['count'] >= 5 ? $row['count'] : null,
            ])->all(),
            'group_count' => $teachers['group_total'],
            'scheduled_sessions_30_days' => $teachers['scheduled_sessions'],
            'assigned_sessions_30_days' => $teachers['assigned_sessions'],
            'unassigned_sessions_30_days' => $teachers['unassigned_sessions'],
            'scope' => 'aggregate only; no student, teacher or customer identifiers',
        ];
    }

    public function attendanceSummary(string $startDate, string $endDate): array
    {
        $this->assertRange($startDate, $endDate, 90);
        $rows = SessionAttendance::query()
            ->whereHas('session', fn ($query) => $query
                ->where('starts_at', '>=', $startDate.' 00:00:00')
                ->where('starts_at', '<', CarbonImmutable::parse($endDate, self::TZ)->addDay()->toDateString().' 00:00:00'))
            ->select('status')
            ->selectRaw('COUNT(*) as attendance_count')
            ->selectRaw('COUNT(DISTINCT student_id) as student_count')
            ->groupBy('status')
            ->orderBy('status')
            ->toBase()->get();

        $visible = $rows->filter(fn ($row): bool => (int) $row->student_count >= 5)->values();
        $studentCount = (clone SessionAttendance::query())
            ->whereHas('session', fn ($query) => $query
                ->where('starts_at', '>=', $startDate.' 00:00:00')
                ->where('starts_at', '<', CarbonImmutable::parse($endDate, self::TZ)->addDay()->toDateString().' 00:00:00'))
            ->distinct()->count('student_id');

        return [
            'from' => $startDate,
            'to' => $endDate,
            'timezone' => self::TZ,
            'attendance_count' => $studentCount >= 5 ? (int) $rows->sum('attendance_count') : null,
            'status_buckets' => $visible->map(fn ($row): array => [
                'status' => (string) ($row->status ?? 'unknown'),
                'count' => (int) $row->attendance_count,
            ])->all(),
            'suppressed_status_buckets' => $rows->count() - $visible->count(),
            'minimum_cohort_size' => 5,
            'scope' => 'aggregate only; no student or session identifiers or free-text feedback; small cohorts are suppressed',
        ];
    }

    public function groupOperations(): array
    {
        $groups = Group::query()
            ->select('status')
            ->selectRaw('COUNT(*) as group_count')
            ->groupBy('status')
            ->orderBy('status')
            ->toBase()->get();
        $sessions = Session::query()
            ->select('session_type', 'status')
            ->selectRaw('COUNT(*) as session_count')
            ->groupBy('session_type', 'status')
            ->orderBy('session_type')->orderBy('status')
            ->toBase()->get();

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'groups_by_status' => $groups->map(fn ($row): array => [
                'status' => (string) ($row->status ?? 'unknown'),
                'count' => (int) $row->group_count,
            ])->all(),
            'sessions_by_type_and_status' => $sessions->map(fn ($row): array => [
                'session_type' => (string) $row->session_type,
                'status' => (string) $row->status,
                'count' => (int) $row->session_count,
            ])->all(),
            'scope' => 'aggregate only; no individual student or teacher records',
        ];
    }

    private function assertRange(string $startDate, string $endDate, int $maximumDays): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, self::TZ);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, self::TZ);
        if (! $start || ! $end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate
            || $end->lt($start) || ($start->diffInDays($end) + 1) > $maximumDays) {
            throw ValidationException::withMessages([
                'date_range' => "The date range must be valid, ordered and no longer than {$maximumDays} days.",
            ]);
        }
    }
}
