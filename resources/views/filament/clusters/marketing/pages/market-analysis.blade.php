<x-filament-panels::page>
    @php($dashboard = $this->getDashboardData())

    <div class="space-y-6">
        <div>
            <p class="text-sm text-gray-600 dark:text-gray-300">Imported Google Ads performance and stored Market Trends observations as of {{ $dashboard['as_of'] }} ({{ $dashboard['timezone'] }}). No live market data is fetched.</p>
        </div>

        <section aria-labelledby="google-ads-heading" class="space-y-3">
            <h2 id="google-ads-heading" class="text-lg font-semibold text-gray-950 dark:text-white">Google Ads campaign performance</h2>
            <x-filament::section>
                @if (! $dashboard['google_ads']['can_view'])
                    <p class="text-sm text-gray-600 dark:text-gray-300">Unavailable without paid-campaign list permission.</p>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">Imported daily metrics for {{ $dashboard['google_ads']['from'] }} through {{ $dashboard['google_ads']['to'] }}. Latest import: {{ $dashboard['google_ads']['latest_imported_at'] ?? 'none' }}. This section reads stored imports and does not call Google Ads live.</p>
                    @if ($dashboard['google_ads']['row_count'] === 0)
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No imported campaign metrics are available for this period. Configure credentials and run the documented dry-run/import command.</p>
                    @else
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div><p class="text-sm text-gray-600 dark:text-gray-300">Campaign-day rows</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($dashboard['google_ads']['row_count']) }}</p></div>
                            <div><p class="text-sm text-gray-600 dark:text-gray-300">Campaigns represented</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($dashboard['google_ads']['campaign_count']) }}</p></div>
                            <div><p class="text-sm text-gray-600 dark:text-gray-300">Currency totals</p><p class="mt-1 text-sm">{{ implode(', ', array_map(fn ($total) => $total['currency_code'], $dashboard['google_ads']['currency_totals'])) }}</p></div>
                        </div>
                        <h3 class="mt-4 font-medium">Totals by account currency</h3>
                        <div class="mt-2 space-y-2">
                            @foreach ($dashboard['google_ads']['currency_totals'] as $total)
                                <div class="grid grid-cols-2 gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700 sm:grid-cols-5">
                                    <div><p class="text-gray-500">Currency</p><p class="font-semibold">{{ $total['currency_code'] }}</p></div>
                                    <div><p class="text-gray-500">Cost</p><p class="font-semibold tabular-nums">{{ number_format(((float) $total['cost_micros']) / 1000000, 2) }} {{ $total['currency_code'] }}</p></div>
                                    <div><p class="text-gray-500">Impressions</p><p class="font-semibold tabular-nums">{{ number_format($total['impressions']) }}</p></div>
                                    <div><p class="text-gray-500">Clicks</p><p class="font-semibold tabular-nums">{{ number_format($total['clicks']) }}</p></div>
                                    <div><p class="text-gray-500">Reported conversions</p><p class="font-semibold tabular-nums">{{ number_format((float) $total['conversions'], 2) }}</p></div>
                                </div>
                            @endforeach
                        </div>
                        <h3 class="mt-4 font-medium">Campaign detail, grouped by currency</h3>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400"><tr><th scope="col" class="py-3 pr-4">Campaign</th><th scope="col" class="px-3 py-3">Currency</th><th scope="col" class="px-3 py-3 text-right">Cost</th><th scope="col" class="px-3 py-3 text-right">Clicks</th><th scope="col" class="px-3 py-3 text-right">Impressions</th><th scope="col" class="px-3 py-3 text-right">Reported conversions</th><th scope="col" class="px-3 py-3 text-right">Cost / conversion</th></tr></thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($dashboard['google_ads']['campaign_rows'] as $campaign)
                                        <tr><th scope="row" class="py-3 pr-4 font-medium">{{ $campaign['campaign_name'] }}</th><td class="px-3 py-3">{{ $campaign['currency_code'] }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format(((float) $campaign['cost_micros']) / 1000000, 2) }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($campaign['clicks']) }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($campaign['impressions']) }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format((float) $campaign['conversions'], 2) }}</td><td class="px-3 py-3 text-right tabular-nums">@if ((float) $campaign['conversions'] > 0){{ number_format(((float) $campaign['cost_micros']) / 1000000 / (float) $campaign['conversions'], 2) }} {{ $campaign['currency_code'] }}@else<span class="text-gray-500">Unavailable</span>@endif</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Provider-reported conversions and cost per reported conversion are not unique acquired customers, CAC, revenue, or profit. Currency totals are kept separate. Access requires <code>google_ads_metrics.view</code>.</p>
                @endif
            </x-filament::section>
        </section>

        <section aria-labelledby="market-trends-heading" class="space-y-3">
            <h2 id="market-trends-heading" class="text-lg font-semibold text-gray-950 dark:text-white">Market trends and demand signals</h2>
            <x-filament::section>
                @if (! $dashboard['market_trends']['can_view'])
                    <p class="text-sm text-gray-600 dark:text-gray-300">Unavailable without market-trend list permission.</p>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ number_format($dashboard['market_trends']['count']) }} active observations match the selected filters. Latest observation: {{ $dashboard['market_trends']['latest_observation_at'] ?? 'none' }}. Observations are source-reported measurements, not forecasts or proof of market-wide conditions.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <label class="text-sm">Period start<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="periodStart" class="w-full" /></x-filament::input.wrapper></label>
                        <label class="text-sm">Period end<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="periodEnd" min="{{ $dashboard['market_trends']['filters']['period_start'] ?? '' }}" class="w-full" /></x-filament::input.wrapper></label>
                        <label class="text-sm">Market<x-filament::input.wrapper><x-filament::input.select wire:model.live="marketFilter" class="w-full"><option value="">All markets</option>@foreach ($dashboard['market_trends']['filter_options']['markets'] as $market)<option value="{{ $market }}">{{ $market }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        <label class="text-sm">Segment<x-filament::input.wrapper><x-filament::input.select wire:model.live="segmentFilter" class="w-full"><option value="">All segments</option>@foreach ($dashboard['market_trends']['filter_options']['segments'] as $segment)<option value="{{ $segment }}">{{ $segment }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        <label class="text-sm">Metric<x-filament::input.wrapper><x-filament::input.select wire:model.live="metricFilter" class="w-full"><option value="">All metrics</option>@foreach ($dashboard['market_trends']['filter_options']['metrics'] as $metric)<option value="{{ $metric }}">{{ $metric }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    </div>
                    <div class="mt-2 flex justify-end"><x-filament::button color="gray" size="sm" wire:click="resetFilters">Clear filters</x-filament::button></div>
                    @if (count($dashboard['market_trends']['recent']) === 0)
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No active observations match these filters. Clear filters or widen the period to see available records.</p>
                    @else
                        <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400"><tr><th scope="col" class="py-3 pr-4">Observation</th><th scope="col" class="px-3 py-3">Value</th><th scope="col" class="px-3 py-3">Market / segment</th><th scope="col" class="px-3 py-3">Period</th><th scope="col" class="px-3 py-3">Source and quality</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($dashboard['market_trends']['recent'] as $trend)
                                <tr><th scope="row" class="py-3 pr-4 font-medium">@if ($trend['detail_url'])<a class="underline" href="{{ $trend['detail_url'] }}">{{ $trend['name'] }}</a>@else{{ $trend['name'] }}@endif<p class="text-xs font-normal text-gray-500">{{ $trend['metric_key'] }}@if ($trend['change_pct'] !== null) · {{ $trend['change_pct'] }}% reported change @endif</p></th><td class="px-3 py-3 tabular-nums">{{ $trend['data_status'] === 'missing' ? 'Missing' : $trend['value'] }} {{ $trend['data_status'] === 'missing' ? '' : $trend['unit'] }}<p class="text-xs text-gray-500">Confidence: {{ $trend['confidence'] === null ? 'unknown' : number_format((float) $trend['confidence'] * 100, 1).'%' }}</p></td><td class="px-3 py-3">{{ $trend['market'] }} / {{ $trend['segment'] ?? 'unspecified' }}</td><td class="px-3 py-3">{{ $trend['period_start'] }} – {{ $trend['period_end'] }}</td><td class="px-3 py-3">@if ($trend['source_url'])<a class="underline" href="{{ $trend['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $trend['source_name'] ?: 'Source' }}</a>@else{{ $trend['source_name'] ?: 'Unknown source' }}@endif<p class="text-xs text-gray-500">Observed {{ $trend['observed_at'] ?? 'time unknown' }} · {{ $trend['source_type'] }} · {{ $trend['freshness_status'] }} · {{ $trend['verification_status'] }} · {{ $trend['data_status'] }}@if ($trend['missing_reason']) · {{ $trend['missing_reason'] }}@endif</p></td></tr>
                            @endforeach
                        </tbody></table></div>
                        @if ($dashboard['market_trends']['count'] > count($dashboard['market_trends']['recent']))
                            <p class="mt-2 text-xs text-gray-500">Showing the 50 most recently observed matching records.</p>
                        @endif
                    @endif
                @endif
            </x-filament::section>
        </section>

        <section aria-labelledby="market-data-notes-heading" class="space-y-3">
            <h2 id="market-data-notes-heading" class="text-lg font-semibold text-gray-950 dark:text-white">Data notes</h2>
            <x-filament::section>
                <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    <li>Google Ads totals are imported daily metrics and remain separated by reported currency. Cost is stored in micros of that currency.</li>
                    <li>Market Trends displays stored observations only. Missing, stale, unverified and unknown values are not converted into zero or independently validated.</li>
                    <li>Google Ads access requires <code>google_ads_metrics.view</code>; Market Trends access requires <code>market_trend.index</code>. The <code>market-analysis.view</code> page gate does not grant access to either data source.</li>
                </ul>
            </x-filament::section>
        </section>
    </div>
</x-filament-panels::page>
