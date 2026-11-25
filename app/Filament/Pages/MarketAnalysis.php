<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Marketing\MarketingCluster;
use App\Filament\Resources\MarketTrends\MarketTrendResource;
use App\Services\MarketAnalysisDashboardData;
use Filament\Pages\Page;
use Hexters\HexaLite\HasHexaLite;
use UnitEnum;

class MarketAnalysis extends Page
{
    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected string $view = 'filament.clusters.marketing.pages.market-analysis';

    protected static ?string $cluster = MarketingCluster::class;

    use HasHexaLite;

    public ?string $periodStart = null;

    public ?string $periodEnd = null;

    public ?string $marketFilter = null;

    public ?string $segmentFilter = null;

    public ?string $metricFilter = null;

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        $permissions = [
            'market_trend.index' => hexa()->can('market_trend.index'),
            'google_ads_metrics.view' => hexa()->can('google_ads_metrics.view'),
        ];
        $summary = app(MarketAnalysisDashboardData::class)->summary($permissions, filters: [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'market' => $this->marketFilter,
            'segment' => $this->segmentFilter,
            'metric' => $this->metricFilter,
        ]);

        foreach ($summary['market_trends']['recent'] as &$trend) {
            $trend['detail_url'] = $permissions['market_trend.index']
                ? MarketTrendResource::getUrl('view', ['record' => $trend['id']])
                : null;
        }
        unset($trend);

        return $summary;
    }

    public function resetFilters(): void
    {
        $this->periodStart = null;
        $this->periodEnd = null;
        $this->marketFilter = null;
        $this->segmentFilter = null;
        $this->metricFilter = null;
    }

    public function defineGates(): array
    {
        return [
            'market-analysis.view' => __('Allows viewing the market analysis page'),
            'google_ads_metrics.view' => __('Allows viewing imported Google Ads performance metrics'),
        ];
    }

    public static function canAccess(): bool
    {
        return hexa()->can('market-analysis.view');
    }
}
