# Marketing and partner domain retirement runbook

Issue: #578

The retirement migration `2026_11_25_000000_retire_marketing_partner_domains` is destructive. It detaches tasks from retired model types, removes comments/subscriptions attached to those types, detaches current product-cost allocations from PaidCampaign targets, and drops the retired domain tables. It retires any persisted `competitor_offer_snapshot_count` KPI definition with an immutable audit event, and deliberately preserves historical activity-log rows and product-cost snapshots.

## Required pre-deployment sign-off

Do not enable the migration in any deployed environment until all of the following are recorded:

1. An export/backup of all eight domains, including soft-deleted rows, subscriber consent evidence, and competitor-offer observation history.
2. The cr8or import result or an explicit owner-approved retention/deletion decision for each domain.
3. The replacement destination decision for the public newsletter signup. The external Mailchimp footer embed remains unchanged until a replacement is approved.
4. Owner approval for the destructive production deployment and a verified backup/restore plan.

The production runtime has historically reported `APP_ENV=local`, so the migration does **not** rely on Laravel's environment name to distinguish production from development. It is blocked in every environment unless `DOMAIN_RETIREMENT_DATA_SIGNOFF=true` is explicitly configured.

## Execution gate

- Default: `DOMAIN_RETIREMENT_DATA_SIGNOFF=false` (or unset). The migration aborts before modifying data.
- CI: `phpunit.xml` sets the flag only for the isolated in-memory test database so the migration and its data cleanup behavior can be exercised.
- Deployment: set `DOMAIN_RETIREMENT_DATA_SIGNOFF=true` only for the approved migration run after all sign-off evidence above exists. Remove/reset the variable after the migration completes.

The migration is intentionally irreversible. Its `down()` method throws because recreating empty tables cannot restore deleted production records, comments, subscriptions, or task links.

## Verification after an approved run

- Confirm all nine retired tables are absent: `newsletters`, `newsletter_subscribers`, `paid_campaigns`, `social_posts`, `competitors`, `competitor_offers`, `competitor_offer_observations`, `partners`, and `potential_partners`.
- Confirm tasks formerly attached to retired models remain present with null `taskable_type` and `taskable_id`.
- Confirm unrelated comments/subscriptions and all activity-log rows remain intact.
- Confirm the competitor-offer snapshot KPI definition is retired and its immutable KPI audit event exists.
- Confirm current product-resource allocations no longer reference PaidCampaign; historical allocation snapshots remain unchanged.
- Confirm CRM `campaign_name`, `referral_partner`, source taxonomy and immutable attribution history remain available.
- Confirm Google Ads reporting uses `google_ads_metrics.view` and Market Trends uses `market_trend.index` independently.
- Confirm the external Mailchimp footer signup remains functional.
