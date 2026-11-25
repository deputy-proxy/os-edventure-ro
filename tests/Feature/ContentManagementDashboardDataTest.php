<?php

namespace Tests\Feature;

use App\Filament\Pages\ContentManagement;
use App\Models\Article;
use App\Models\CompetitionEntry;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\ContentManagementDashboardData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ContentManagementDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_inventory_and_review_queues_are_kept_separate(): void
    {
        $today = CarbonImmutable::parse('2026-10-18 12:00:00', config('app.timezone'));
        Article::factory()->create(['title' => 'Published article', 'status' => 'published', 'scheduled_on' => $today->addDay()->toDateString(), 'published_on' => $today->toDateString()]);
        Article::factory()->create(['title' => 'Draft article', 'status' => 'draft', 'scheduled_on' => $today->addDays(2)->toDateString()]);
        Article::factory()->create(['title' => 'Deleted article', 'status' => 'published', 'scheduled_on' => $today->addDays(2)->toDateString()])->delete();
        ProductReview::factory()->create(['status' => 'pending_review']);
        CompetitionEntry::factory()->create(['status' => 'pending_review']);

        $summary = app(ContentManagementDashboardData::class)->summary($this->permissions(), $today);

        $this->assertSame(['articles'], array_keys($summary['content_types']));
        $this->assertSame(2, $summary['content_types']['articles']['current_count']);
        $this->assertSame(1, $summary['content_types']['articles']['soft_deleted_count']);
        $this->assertSame(2, $summary['content_types']['articles']['upcoming_count']);
        $this->assertSame(1, $summary['content_types']['articles']['recent_published_count']);
        $this->assertSame(1, $summary['review_queues']['product_reviews']['pending_count']);
        $this->assertSame(1, $summary['review_queues']['competition_entries']['pending_count']);
    }

    public function test_unavailable_article_inventory_is_not_reported_as_empty(): void
    {
        $summary = app(ContentManagementDashboardData::class)->summary([], CarbonImmutable::parse('2026-10-18 12:00:00', config('app.timezone')));

        $this->assertFalse($summary['content_types']['articles']['can_view']);
        $this->assertNull($summary['content_types']['articles']['current_count']);
        $this->assertNull($summary['review_queues']['product_reviews']['pending_count']);
        $this->assertNull($summary['review_queues']['competition_entries']['pending_count']);
    }

    public function test_quick_links_require_their_own_resource_permissions(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::before(fn ($user, string $ability): ?bool => match ($ability) {
            'article.index', 'product_review.index' => true,
            default => null,
        });

        $data = (new ContentManagement)->getDashboardData();

        $this->assertNotNull($data['content_types']['articles']['index_url']);
        $this->assertNull($data['content_types']['articles']['create_url']);
        $this->assertNotNull($data['review_queues']['product_reviews']['index_url']);
        $this->assertNull($data['review_queues']['competition_entries']['index_url']);
    }

    public function test_dashboard_keeps_its_dedicated_page_permission(): void
    {
        $page = new ContentManagement;
        $this->assertSame(['content-management.view' => __('Allows viewing the content management page')], $page->defineGates());

        $this->actingAs(User::factory()->create());
        Gate::before(fn ($user, string $ability): ?bool => $ability === 'content-management.view' ? true : null);
        $this->assertTrue(ContentManagement::canAccess());
    }

    public function test_dashboard_denies_access_without_its_dedicated_page_permission(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::before(fn ($user, string $ability): ?bool => $ability === 'content-management.view' ? false : null);
        $this->assertFalse(ContentManagement::canAccess());
    }

    /** @return array<string, bool> */
    private function permissions(): array
    {
        return ['article.index' => true, 'article.create' => true, 'product_review.index' => true, 'competition_entry.index' => true];
    }
}
