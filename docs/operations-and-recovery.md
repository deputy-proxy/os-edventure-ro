# Production operations, observability, and recovery

## Verified state and unresolved production facts

The system owner supplied the following production-readiness answers on 2026-10-18. These are owner-reported statements, not independently verified through the hosting control panel or production telemetry.

| Component | Owner-reported state | Remaining evidence / owner action |
| --- | --- | --- |
| Hosting | Hostinger shared cloud hosting; owner reports it supports long-running processes and monitoring commands | Exact plan/product still needs recording; owner report has not been independently checked against hosting configuration. |
| Deployment | Git-based deployment | Document the actual release steps, migration execution point, rollback procedure, and responsible operator. |
| Application runtime | PHP `^8.4.1`, Laravel 13, Filament 5.11, Livewire 4; Node.js 22 for frontend builds. These are the committed Composer/CI requirements after issue #526. | Verify the actual Hostinger PHP CLI/FPM versions and required extensions before deployment; Composer's PHP 8.4 baseline is not proof of the deployed runtime. |
| Database | MariaDB 11.8.9 (`11.8.9-MariaDB-log`), reported by the owner on 2026-11-24; Laravel uses the `mysql` driver in the deployed environment. | MariaDB 11.8.9 migration/integrity CI coverage was added under issue #587. Migrations now apply MySQL-compatible CHECK constraints for both Laravel `mysql` and `mariadb` driver names; the MariaDB regression suite exercises those constraints and row-lock SQL. Confirm the live driver/configuration and keep a staging migration/integrity run against the deployed version before production use. |
| Persistent files | Local disk on the hosting account | Confirm which paths contain user uploads, how they are included in backups, disk-capacity monitoring, and recovery behavior after redeploy/restore. |
| Queue worker | Hostinger cron inventory checked 2026-11-26 shows `queue:work --stop-when-empty` every minute for this application. The effective queue connection is `database`, with `retry_after=90`. Laravel 13.43.0 defaults the omitted worker flags to `--timeout=60` and `--tries=1`; the 60-second timeout leaves a 30-second margin before reservation expiry. The three current application email jobs declare `$tries=3` and do not declare a job-specific timeout. | The latest captured worker output (2026-11-26 21:34:05) was `Worker STOPPED Queue empty`, so it did not demonstrate a live job completion or provide job-duration evidence. CI now exercises a representative database-queued customer-contract email job on MariaDB 11.8.9, asserts that worker processing completes within 60 seconds, and verifies that the job is removed without entering `failed_jobs`; mail delivery is faked in this isolated test. This is not a measurement of production provider/network latency. Measure representative deployed job durations and reconcile them with the 60-second worker timeout before changing settings. |
| Scheduler | Hostinger cron inventory checked 2026-11-26 confirms `schedule:run` every minute. Captured output at 21:34:02 showed `ops:scheduler-heartbeat`, `evaluations:process-approval-timeouts`, and `mcp:expire-approvals` completing successfully. | Test the missing/stale heartbeat alert path with confirmed receipt, observe a scheduled payslip run and reconcile expected payslips. Monthly completion remains unverified. |
| Mail provider | Resend configured as Laravel mailer; a test send returned `Illuminate\\Mail\\SentMessage` after setup. | Confirm the test message and a real monitoring alert are received at the intended inbox; a successful send call alone is not proof of inbox delivery. |
| Cache backend | Effective cache driver was switched to `database`. Separate Tinker invocations successfully wrote/read `ops:cache-check`, and basic cache-lock acquisition returned true. | Cross-process persistence and basic lock acquisition are verified. Run contention/overlap behavior checks before relying on them for monthly payslip generation. |
| Monitoring and alert delivery | Owner reports Laravel logging with notifications sent to their email; test receipt and exact routing mechanism not evidenced | Confirm which monitor invokes `/up`, `/ready`, heartbeat and failed-job checks, and retain evidence of a test notification reaching the owner. Requested coverage includes database/readiness, queue/failed jobs, scheduler heartbeat, monthly payslip generation, backup failures, disk/storage, and mail/integration failures. Scheduler and payslip implementation remains tracked separately in issue #273. |
| Operational ownership | The owner is the admin; no backup incident contact is designated | Record the owner’s operational contact route in the restricted runbook and explicitly document that no backup contact is currently designated. |
| Backup provider and restore | Backups are reported to include MySQL and required files and to be stored separately from the hosting environment. An isolated restore reportedly restored and validated both database and files in about 30 minutes on 2026-10-18. | The result is owner-reported. Record the backup identifier, isolated target, validation/command results, start/end times, recovered timestamp/data loss, measured RTO, reviewer sign-off and cleanup evidence in restricted operational storage. |
| Backup policy | Daily backups, 14-day retention; owner reports database and required files are included and copies are stored separately | Verify actual provider configuration, storage region, coverage and retention. Backups are not encrypted, and no separate deletion/immutability safeguard is enabled. |
| RPO / RTO | Owner-approved RPO 1 day; RTO 4 hours | Verify that daily backup configuration can meet the one-day RPO. The reported restore took about 30 minutes, but retain restricted evidence before treating the 4-hour RTO as demonstrated. |
| Encryption and key ownership | Backups are not encrypted | Record acceptance of this risk or implement encryption; do not store secrets in this repository. |
| Backup access/deletion protection | Backup access is restricted to the owner; no separate deletion safeguard is enabled | Verify actual permissions and auditability, and assess a safeguard against accidental or malicious deletion. |

The repository has no production credentials and does not infer undocumented settings. Do not treat owner-reported restore success as a fully evidenced drill until the restricted operational record contains the evidence above. Never put secrets, backup contents, personal data or credential-bearing connection strings in this repository or issue comments.

## Health endpoints

- `GET /up` is Laravel's configured framework health endpoint. Use it as a basic liveness signal: the application process can respond.
- `GET /ready` checks a trivial query against the configured default database connection. It returns only `{"status":"ready"}` with HTTP 200 or `{"status":"not_ready"}` with HTTP 503. It does not return connection names, exception messages, credentials, or topology. Use it as a readiness/dependency signal.
- Neither endpoint proves queue workers or the scheduler are alive. The private `ops:check-heartbeats` Artisan command checks database connectivity and recent queue-worker/scheduler heartbeat timestamps. It is not exposed as a public HTTP endpoint. Keep detailed health and infrastructure dashboards behind operator authentication; do not add environment/configuration dumps to public endpoints.

Every HTTP response includes `X-Request-ID`. A syntactically valid incoming value of up to 100 ASCII letters, digits, dots, underscores, colons or hyphens is preserved; invalid values are replaced with a generated UUID. The ID is added to Laravel log context. Do not log request bodies, authorization headers, cookies, tokens, passwords, personal records, assessment answers, or arbitrary model JSON. For JSON logs, set `LOG_CHANNEL=stderr_json` and `LOG_LEVEL=info` (or a stricter operationally appropriate level) only after confirming the host collects stderr and retains it securely. The default remains configurable; the existence of a JSON channel alone does not prove logs are exported or alerts are configured.

## Queue operations

The deployed Hostinger cron inventory was checked on 2026-11-26. It runs `/usr/bin/php /home/u583712040/domains/scl.ro/public_html/edv/artisan queue:work --stop-when-empty` every minute. The command omits explicit timeout/tries flags. Laravel 13.43.0 `queue:work` defaults are `--timeout=60` and `--tries=1`; the effective database queue `retry_after` is 90 seconds, leaving 30 seconds between the default worker timeout and job reservation expiry. The current application email jobs declare `$tries=3`, which overrides the worker attempt default for those jobs; none declares a job-specific timeout. This establishes the configured timeout/retry relationship, not that every real job finishes within 60 seconds. Measure actual job durations from representative deployed work before adjusting either value. Do not copy these values blindly to Redis/SQS or long-running jobs.

Useful commands, run from the deployed release with its approved environment:

The application records queue-worker heartbeat timestamps while a worker loop runs and records a scheduler heartbeat every minute. The heartbeat values use Laravel's configured cache store, so the worker, scheduler and monitoring invocation must share a durable cache backend. Run the following from the monitoring system or a restricted operator shell:

```sh
php artisan ops:check-heartbeats --max-age=180
php artisan ops:check-failed-jobs --max-failed=0
```

Exit code `0` means the database is reachable and both heartbeat timestamps are recent. Exit code `1` means at least one check failed; configure the external monitor to alert on that result. `ops:check-failed-jobs` returns a non-zero exit if the failed-job count exceeds `--max-failed` (default `0`), reports only the count and threshold, and never prints job payloads or exception text. A missing/unavailable failed-jobs table also fails the check. Keep the failed-job threshold at zero unless the owner explicitly approves a different policy and has a process to inspect/clear failures safely. The command intentionally reports only healthy/unhealthy labels, not hostnames, credentials or exception messages. It does not replace queue-depth, failed-job, integration, or payslip-completion monitoring.


```sh
php artisan queue:failed
php artisan queue:monitor default --max=100
php artisan queue:retry <failed-job-uuid>
php artisan queue:forget <failed-job-uuid>
php artisan queue:restart
```

`queue:monitor` must be scheduled/triggered by the actual monitoring setup to be useful. Alert on any unexpected failed job for critical workflows and on queue depth/oldest-job age exceeding an owner-approved threshold. The application records a queue-worker heartbeat on queue loop iterations in the configured shared cache. Check it with `php artisan ops:check-heartbeats`; this only proves a worker loop has run recently, not that every queue is draining or every job succeeds. The worker and scheduler must use the same shared cache backend as the command. Use `php artisan ops:check-failed-jobs --max-failed=0` from the external monitor to detect unreviewed failed jobs without leaking payloads. Inspect a failed job using restricted operator access before retrying it; retries can repeat external side effects unless that job is idempotent. Use `queue:retry` only after fixing the cause and checking whether the operation already succeeded externally. Use `queue:forget` only after documenting an approved decision to discard it. Never paste raw failed-job payloads or exception dumps into tickets or public chat; they can contain personal data or integration details. Queue driver `sync` does not provide an asynchronous worker or failed-job queue, so production configuration must be verified. The last documented failed-job inspection (2026-10-18) reported 78 failures: 48 `TimeoutExceededException` failures for new-device authentication notifications and 30 `PusherException` failures associated with legacy Article comment-channel names. These figures are historical only and must not be represented as the current failed-job count. Issue #587 also records an owner-supplied failed-job count of 0, but that check has no timestamp. It is not sufficient as the required fresh, timestamped count. As of the 2026-11-26 evidence update, a timestamped failed-job count has not yet been captured. Run the following from the approved deployed release and record the timestamp/count only; do not print failed-job payloads or exception text:

```sh
php artisan tinker --execute='$count = DB::table("failed_jobs")->count(); dump(["checked_at" => now()->toIso8601String(), "failed_jobs" => $count]);'
php artisan ops:check-failed-jobs --max-failed=0
```

Inspect failures under restricted access, classify/reconcile effects, and document an approved retry/discard decision. Do not bulk-retry. The authentication-log config enables new-device notifications by default and has GeoIP enabled. Mail/network latency, GeoIP work, worker runtime limits and host-level termination remain investigation hypotheses unless supported by current job-level evidence. Do not disable security notifications or change timeout/retry values without a controlled reproduction and evidence.

## Scheduler and payslip recovery

The scheduler registers `employee-payslips:generate` for day 1 at 00:05 in `Europe/Bucharest`, with `withoutOverlapping()`. Hostinger cron inventory checked on 2026-11-26 confirms `/usr/bin/php /home/u583712040/domains/scl.ro/public_html/edv/artisan schedule:run >> /dev/null 2>&1` every minute. Captured Hostinger output at 21:34:02 showed the scheduler heartbeat and two due commands completing successfully. The deployed `schedule:list` included the payslip task at `5 0 1 * *`. The deployment remains pre-production; a real monthly generation and payroll reconciliation have not yet been observed. The effective cache store is `database`, and cross-process persistence plus basic lock acquisition were verified. `withoutOverlapping()` can leave a stale lock after an interrupted run; investigate before clearing scheduler locks. The alert path still needs end-to-end receipt confirmation.

Inspect the schedule from the deployed release:

```sh
php artisan schedule:list
php artisan schedule:run
```

Run `schedule:run` manually only with the normal scheduler's authorization and change controls; it can execute any due scheduled task. Alert if the scheduler runner stops invoking the application or if the monthly generation has not completed shortly after its expected window. A named scheduler heartbeat is now recorded every minute by the Laravel schedule. Use `php artisan ops:check-heartbeats` from the external monitoring platform to detect missing/stale scheduler and queue-worker heartbeats. This command does not send alerts itself; configure the production scheduler to invoke the heartbeat event and configure an external monitor to run the check and page the named owner on non-zero exit.

Payslip generation is designed to be idempotent and supports explicit month recovery. Before backfill, confirm the month, current payslip counts, existing deleted payslips, and source items with payroll staff:

```sh
php artisan employee-payslips:generate --month=YYYY-MM
```

Replace `YYYY-MM` with the approved calendar month. After execution, verify the command exit status, employee/month payslip count, linked source IDs, saved snapshots and totals. Do not delete/recreate closed or paid payslips to force a rerun; preserve financial history and follow `docs/employee-payslip-workflow.md` for adjustments. Record the operator, period, reason, result and reconciliation approval in the restricted operational record.

## Application resilience tests

The application-level recovery checks use synthetic fixtures and isolated test databases. Run the focused suites from a clean checkout with the repository's locked dependencies installed:

```sh
php artisan test tests/Feature/Operations/HealthAndObservabilityTest.php
php artisan test tests/Feature/GoogleAdsRefreshRecoveryTest.php
php artisan test tests/Feature/DatabaseIntegrityAuditTest.php tests/Feature/FinanceReconciliationAuditTest.php
```

These suites cover database-readiness failures, missing/stale worker and scheduler heartbeats, failed-job threshold reporting without payload disclosure, backup artifact digest validation, retryable and non-retryable import failures, duplicate retry delivery, rollback after a partial metric write, a subsequent clean import, and read-only financial/data-integrity audits. The Google Ads retry lineage is constrained to one child batch per failed batch so a duplicated retry request does not repeat the provider operation. A later retry must target the latest failed child batch.

The full PHP suite runs in the `php` CI job. The MySQL CI job also runs `GoogleAdsRefreshRecoveryTest` so retry-lineage constraints and transaction rollback are exercised against MySQL 8.0, in addition to SQLite. No test uses production credentials, live provider requests, customer data, production queue retries, or destructive restore operations.

The repository currently has no provider-specific restore adapter or safe application command capable of restoring a backup into a newly provisioned isolated database and file namespace. Do not add a fake restore implementation around shell commands or infer provider behavior. The supported application hook is the read-only `ops:verify-backup` artifact check; the actual isolated restore, service startup, integrity reconciliation, alert-receipt verification, and cleanup remain authorized operator activities documented below and in the restricted evidence template.

## Backup policy and restore drill

The owner-approved policy is RPO 1 day, RTO 4 hours, daily backups, and 14-day retention. The owner reports that backups include MySQL and required files and are stored separately from the hosting environment. Verify the actual provider configuration, storage region, and retention. Backups are not encrypted, and no separate deletion/immutability safeguard is enabled. Backup access is reported restricted to the owner. The owner approved monthly isolated restore drills. These are owner-reported configuration details and policy decisions; they do not independently prove the provider settings or restore guarantees.

Back up the database and every persistent upload/object store needed to interpret the records. The owner reports the required files are included and backups are stored separately from the hosting environment; verify this against provider configuration. Backup access is reported restricted to the owner, but there is no separate deletion safeguard and backups are not encrypted. These are material recovery risks to resolve or explicitly accept. Do not assume local `storage/`, application logs, or a provider's default backup feature is durable or restorable.

The repository provides an artifact sanity check:

```sh
php artisan ops:verify-backup /restricted/path/to/backup-artifact
php artisan ops:verify-backup /restricted/path/to/backup-artifact --sha256=<digest-from-trusted-manifest>
```

It fails for missing, unreadable or empty artifacts and prints a SHA-256 digest. When `--sha256` is supplied, it validates the digest format and compares the artifact against the expected digest using a constant-time comparison; a mismatch fails the check. Obtain the expected digest from a separately protected, trusted manifest. This does **not** validate database format, authenticity, completeness, encryption, or restoreability. Do not treat a digest computed from the same untrusted artifact as proof of provenance. Never point a restore command at production during a drill.

### Required isolated restore drill record

1. Create a time-stamped drill record and identify the approved backup, expected point-in-time, operator, reviewer and target RPO/RTO.
2. Verify artifact availability and compare its digest to the trusted backup manifest. Confirm the decryption key is available to the approved operator.
3. Restore into a new isolated database/storage namespace with production network access and outbound integrations disabled. Never overwrite production or send test mail/webhooks.
4. Apply only the documented restore procedure. Record start/end times, errors and actual recovery duration.
5. Run `php artisan migrate:status`, `php artisan database:integrity-audit`, and application smoke tests against the restored target. Reconcile expected record counts and confirm critical records and associated uploaded objects are present. Do not run destructive fixes against the restored copy as part of verification.
6. Record pass/fail, recovered timestamp, measured data loss, measured RTO, discrepancies and corrective actions. Obtain reviewer sign-off.
7. Securely remove the isolated restored data under the approved retention procedure and verify the cleanup.

**Drill status: the owner reports an isolated restore on 2026-10-18 that restored and validated both database and files in about 30 minutes.** The repository change did not perform or independently verify that restore. The backup acceptance criterion remains incomplete until a restricted drill record captures the artifact identifier, isolated target, validation details, `migrate:status`, integrity audit and smoke-test results, recovered timestamp/measured data loss, measured RPO and RTO, start/end times, reviewer sign-off and secure cleanup. The reported 30-minute duration is within the four-hour RTO target, but the complete evidence record has not been confirmed.

## Deployment and migration procedure

1. Confirm the release SHA, reviewed PR, green required CI checks, environment configuration, secrets availability and a fresh verified restorable backup. Verify the effective production environment reports `APP_ENV=production` and **Debug Mode disabled** with `php artisan about`; do not rely on the `.env.example` default to override a hosting-panel environment variable. The committed example keeps `APP_DEBUG=false` to avoid making unsafe debug output the default in newly provisioned environments. Enable debug output only in a local development environment, never in production.
2. Review migrations for locks, generated-column/index support, foreign-key behavior, table size and data preflight. The current CI migration gate uses SQLite and does not prove production MySQL/InnoDB or other-engine behavior. Test risky migrations against a staging clone running the production engine/version.
3. Deploy the immutable release using the actual hosting provider's approved process. Do not run migrations concurrently from multiple replicas. Run `php artisan migrate --force` once under a single authorized deploy step after the backup and preflight are verified.
4. Rebuild/refresh Laravel config, route and view caches using the platform's established release procedure; restart long-lived queue workers with `php artisan queue:restart` after deploy. Confirm worker processes actually restart.
5. Check `/up`, `/ready`, representative authenticated workflows, queue processing, scheduler execution and error rates. Observe the release before closing the deployment.

Rollback is not universally a code rollback. A migration may be irreversible or its `down()` method may intentionally refuse to remove historical data/constraints. Inspect the specific migration before acting. Prefer a forward fix when the new schema is compatible with the old release. If data changes have occurred, restore or reverse them only under an approved recovery plan with a verified backup and explicit data-loss decision. Never run `migrate:rollback` blindly in production.

## Incident response and alerting baseline

Use [monitoring-alert-matrix.md](monitoring-alert-matrix.md) as the coverage and verification checklist for each alert class, sanitized payload, deduplication behavior, routing target, and recovery notification. It explicitly separates repository probes from external monitor configuration and recorded alert receipt.

- **Application/HTTP:** alert on sustained 5xx rate, elevated latency, failed `/up` or `/ready`, and repeated errors sharing a request ID. Set thresholds from observed baseline and business hours; do not invent them from development traffic.
- **Database:** alert on failed readiness, connection exhaustion, storage/capacity thresholds, replication/backup failures where applicable, and integrity-audit findings. A healthy `/ready` does not prove backups or integrity are healthy.
- **Queue:** alert on failed critical jobs, growing queue depth/age, worker process absence, and repeated retries. Restrict failed-job payload access.
- **Scheduler:** monitor the host's scheduler invocation and monthly payslip generation completion; reconcile expected payslips after the monthly run.
- **Integrations/mail:** use provider delivery/error metrics and synthetic checks that do not expose real customer data. The application currently has no provider-specific integration alerting configured.
- **Security/privacy:** send structured logs to a restricted, access-controlled sink with retention approved by the owner. Do not include secrets, request bodies, credentials or raw personal records in alerts. Share request IDs and sanitized error categories instead.
- **Thresholds:** the owner has not approved provisional default thresholds. Collect production baselines and obtain owner approval for thresholds and evaluation windows before describing alert thresholds as configured or production-validated.

On incident: assign an incident lead, record UTC/local timestamps and impact, preserve relevant restricted logs, stop unsafe retries or deployments, identify the last known-good release/backup, and follow the approved recovery path. After recovery, validate key workflows and reconcile financial/academic history before declaring resolution. Record cause, impact, corrective actions and owners. The owner is the admin, and no backup incident contact is designated. The owner reports Laravel logging and email notifications to their email, but the precise alert invocation/routing and test-notification evidence remain to be recorded. Thresholds are not approved; collect production baselines before proposing thresholds. Scheduler setup and monthly payslip monitoring are tracked separately in issue #273. The Pusher comment-broadcast failure analysis and compatibility fix are also documented in issue #273.
## Automated resilience tests

The synthetic application-level failure tests, local/CI commands, and explicit infrastructure-test boundaries are documented in [resilience-testing.md](resilience-testing.md). Passing those tests does not substitute for the restricted isolated restore drill or real alert-receipt evidence described above.

## Production-readiness audit

The evidence-based control inventory, status definitions, accountable roles, known gaps and next verification actions are maintained in [production-readiness-audit.md](production-readiness-audit.md). The [monitoring-alert-matrix.md](monitoring-alert-matrix.md) maps failure modes to probes, response actions, and evidence needed to verify external alerts. Treat these matrices as control inventories, not as readiness certification. External monitor configuration, actual provider backup settings, delivered alert evidence and the complete restore-drill record still require restricted operational evidence.

## Recovery and availability objectives

The approved RPO/RTO baseline, critical workflow tiers, isolated restore-drill procedure, measurement method, and remaining approval gaps are documented in [recovery-and-availability-objectives.md](recovery-and-availability-objectives.md). The owner-approved targets are RPO no more than one day and RTO within four hours; they remain unproven until the full restore evidence record is reviewed. No numeric availability SLO is set without a production baseline and owner approval.

For each approved isolated restore drill, capture results in the restricted operational record using [restore-drill-evidence-template.md](restore-drill-evidence-template.md). Do not commit completed records or sensitive backup evidence to this repository. The template distinguishes measured RPO/RTO from policy targets and includes data-integrity checks, sign-off, and verified cleanup.
