<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const RETIRED_MORPHS = [
        'competitor' => 'App\\Models\\Competitor',
        'competitor_offer' => 'App\\Models\\CompetitorOffer',
        'competitor_offer_observation' => 'App\\Models\\CompetitorOfferObservation',
        'newsletter' => 'App\\Models\\Newsletter',
        'newsletter_subscriber' => 'App\\Models\\NewsletterSubscriber',
        'paid_campaign' => 'App\\Models\\PaidCampaign',
        'partner' => 'App\\Models\\Partner',
        'potential_partner' => 'App\\Models\\PotentialPartner',
        'social_post' => 'App\\Models\\SocialPost',
    ];

    public function up(): void
    {
        if (! config('domain_retirement.allow_destructive_migration')) {
            throw new RuntimeException(
                'Marketing/partner domain retirement is destructive. Set DOMAIN_RETIREMENT_DATA_SIGNOFF=true only after the required export, cr8or transfer/retention sign-off, and production approval are recorded.'
            );
        }

        $morphTypes = array_merge(array_keys(self::RETIRED_MORPHS), array_values(self::RETIRED_MORPHS));

        // Keep tasks and their operational details, but detach them from models
        // that are being retired. Both aliases and legacy FQCNs are covered.
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'taskable_type')) {
            DB::table('tasks')->whereIn('taskable_type', $morphTypes)->update([
                'taskable_type' => null,
                'taskable_id' => null,
            ]);
        }

        // Comments and subscriptions belong to the retired domains. Hard-delete
        // those comments so FK cascades remove mentions, reactions and attachments;
        // unrelated comments and the activity log remain untouched.
        $commentsTable = config('comments.table_names.comments', 'comments');
        $subscriptionsTable = config('comments.table_names.subscriptions', 'comment_subscriptions');

        if (Schema::hasTable($commentsTable) && Schema::hasColumn($commentsTable, 'commentable_type')) {
            DB::table($commentsTable)->whereIn('commentable_type', $morphTypes)->delete();
        }

        if (Schema::hasTable($subscriptionsTable) && Schema::hasColumn($subscriptionsTable, 'commentable_type')) {
            DB::table($subscriptionsTable)->whereIn('commentable_type', $morphTypes)->delete();
        }

        // Preserve allocation history, but detach current allocation rules from
        // the retired PaidCampaign model so they cannot resolve as active targets.
        if (Schema::hasTable('product_resource_allocations') && Schema::hasColumn('product_resource_allocations', 'targetable_type')) {
            DB::table('product_resource_allocations')->whereIn('targetable_type', ['paid_campaign', self::RETIRED_MORPHS['paid_campaign']])->update([
                'targetable_type' => null,
                'targetable_id' => null,
            ]);
        }

        // Retire any persisted definition of the removed competitor snapshot KPI.
        // Keep the definition and append an immutable audit event rather than
        // deleting its configuration history.
        if (Schema::hasTable('kpi_definitions') && Schema::hasColumn('kpi_definitions', 'key')) {
            $legacyDefinitions = DB::table('kpi_definitions')
                ->where('key', 'competitor_offer_snapshot_count')
                ->get(['id', 'version', 'status', 'effective_from']);

            foreach ($legacyDefinitions as $definition) {
                if ($definition->status === 'retired') {
                    continue;
                }

                $retiredOn = max(now()->toDateString(), (string) $definition->effective_from);
                DB::table('kpi_definitions')->where('id', $definition->id)->update([
                    'status' => 'retired',
                    'effective_until' => $retiredOn,
                    'retired_by' => null,
                    'retired_at' => now(),
                    'updated_at' => now(),
                ]);

                if (Schema::hasTable('kpi_definition_audits')) {
                    DB::table('kpi_definition_audits')->insert([
                        'kpi_definition_id' => $definition->id,
                        'actor_id' => null,
                        'event' => 'retired',
                        'effective_version' => $definition->version,
                        'change_summary' => 'Retired by issue #578 because the competitor-offer snapshot source was removed.',
                        'changes' => json_encode(['status' => ['before' => $definition->status, 'after' => 'retired']], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // Observation history must be dropped before its parent offer table.
        foreach ([
            'competitor_offer_observations',
            'competitor_offers',
            'competitors',
            'newsletter_subscribers',
            'newsletters',
            'social_posts',
            'paid_campaigns',
            'potential_partners',
            'partners',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This retirement migration is irreversible. Recreating empty tables would not restore retired production data, task links, comments, or subscriptions.'
        );
    }
};
