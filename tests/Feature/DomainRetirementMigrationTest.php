<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DomainRetirementMigrationTest extends TestCase
{
    private ?string $previousConnection = null;

    protected function tearDown(): void
    {
        DB::disconnect('domain_retirement_test');
        if ($this->previousConnection !== null) {
            config(['database.default' => $this->previousConnection]);
        }
        parent::tearDown();
    }

    public function test_retirement_detaches_tasks_deletes_only_retired_comments_preserves_audit_and_drops_domain_tables(): void
    {
        $this->previousConnection = config('database.default');
        config([
            'database.connections.domain_retirement_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'domain_retirement_test',
        ]);
        DB::purge('domain_retirement_test');

        $schema = Schema::connection('domain_retirement_test');
        $schema->create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('taskable_type')->nullable();
            $table->unsignedBigInteger('taskable_id')->nullable();
        });
        $schema->create('comments', function (Blueprint $table): void {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('commenter_type');
            $table->unsignedBigInteger('commenter_id');
            $table->text('body');
            $table->timestamps();
        });
        $schema->create('comment_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->string('commenter_type');
            $table->unsignedBigInteger('commenter_id');
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('product_resource_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('targetable_type')->nullable();
            $table->unsignedBigInteger('targetable_id')->nullable();
        });
        $schema->create('kpi_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->unsignedSmallInteger('version');
            $table->string('status');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });
        $schema->create('kpi_definition_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('kpi_definition_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->unsignedSmallInteger('effective_version');
            $table->string('change_summary');
            $table->json('changes')->nullable();
            $table->timestamp('created_at');
        });
        $schema->create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
        });

        $retiredTables = [
            'competitor_offer_observations', 'competitor_offers', 'competitors',
            'newsletter_subscribers', 'newsletters', 'social_posts', 'paid_campaigns',
            'potential_partners', 'partners',
        ];
        foreach ($retiredTables as $table) {
            $schema->create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }

        DB::table('tasks')->insert([
            ['taskable_type' => 'newsletter', 'taskable_id' => 7],
            ['taskable_type' => 'App\\Models\\PaidCampaign', 'taskable_id' => 8],
            ['taskable_type' => 'article', 'taskable_id' => 9],
        ]);
        $retiredCommentId = DB::table('comments')->insertGetId([
            'commentable_type' => 'newsletter', 'commentable_id' => 7, 'parent_id' => null,
            'commenter_type' => 'user', 'commenter_id' => 1, 'body' => 'Retired comment',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('comments')->insert([
            'commentable_type' => 'newsletter', 'commentable_id' => 7, 'parent_id' => $retiredCommentId,
            'commenter_type' => 'user', 'commenter_id' => 1, 'body' => 'Retired reply',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('comments')->insert([
            'commentable_type' => 'article', 'commentable_id' => 9, 'parent_id' => null,
            'commenter_type' => 'user', 'commenter_id' => 1, 'body' => 'Keep this comment',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('comment_subscriptions')->insert([
            ['commentable_type' => 'newsletter', 'commentable_id' => 7, 'commenter_type' => 'user', 'commenter_id' => 1],
            ['commentable_type' => 'article', 'commentable_id' => 9, 'commenter_type' => 'user', 'commenter_id' => 1],
        ]);
        DB::table('product_resource_allocations')->insert([
            'targetable_type' => 'paid_campaign', 'targetable_id' => 12,
        ]);
        DB::table('kpi_definitions')->insert([
            'key' => 'competitor_offer_snapshot_count', 'version' => 2, 'status' => 'active',
            'effective_from' => '2026-10-20', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('activity_log')->insert([
            ['subject_type' => 'newsletter', 'subject_id' => 7, 'description' => 'Historical retired-domain event'],
            ['subject_type' => 'article', 'subject_id' => 9, 'description' => 'Unrelated event'],
        ]);

        $migration = require base_path('database/migrations/2026_11_25_000000_retire_marketing_partner_domains.php');
        config(['domain_retirement.allow_destructive_migration' => false]);
        try {
            $migration->up();
            $this->fail('The migration must require explicit data-transfer/retention sign-off.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DOMAIN_RETIREMENT_DATA_SIGNOFF=true', $exception->getMessage());
        }
        config(['domain_retirement.allow_destructive_migration' => true]);
        $migration->up();

        $this->assertSame(3, DB::table('tasks')->count());
        $this->assertDatabaseHas('tasks', ['id' => 1, 'taskable_type' => null, 'taskable_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => 2, 'taskable_type' => null, 'taskable_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => 3, 'taskable_type' => 'article', 'taskable_id' => 9]);
        $this->assertSame(1, DB::table('comments')->count());
        $this->assertDatabaseHas('comments', ['commentable_type' => 'article', 'body' => 'Keep this comment']);
        $this->assertSame(1, DB::table('comment_subscriptions')->count());
        $this->assertDatabaseHas('comment_subscriptions', ['commentable_type' => 'article']);
        $this->assertDatabaseHas('product_resource_allocations', ['targetable_type' => null, 'targetable_id' => null]);
        $this->assertDatabaseHas('kpi_definitions', [
            'key' => 'competitor_offer_snapshot_count', 'status' => 'retired', 'retired_by' => null,
        ]);
        $this->assertDatabaseHas('kpi_definition_audits', [
            'event' => 'retired', 'effective_version' => 2, 'actor_id' => null,
        ]);
        $this->assertSame(2, DB::table('activity_log')->count());
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'newsletter', 'description' => 'Historical retired-domain event']);
        foreach ($retiredTables as $table) {
            $this->assertFalse($schema->hasTable($table), "Expected {$table} to be dropped.");
        }

        try {
            $migration->down();
            $this->fail('The retirement migration must not imply that deleted data can be restored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('irreversible', $exception->getMessage());
        }

    }
}
