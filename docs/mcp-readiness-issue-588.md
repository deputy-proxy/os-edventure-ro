# MCP autonomous direct-write readiness (#588)

**Status:** governance decisions recorded; repository evidence and acceptance preparation in progress; staging acceptance and independent sign-off pending.
**Last verified:** 2026-11-27 (GitHub Actions run #844)
**Production write enablement:** disabled. This issue and its PR do not authorize production writes or production data changes.

This document is the evidence index for issue #588. The active MCP runtime exposes nine one-call autonomous direct-write tools. The legacy per-operation approval workflow is retained only for historical records and compatibility tests.

## 1. Governance decisions

### Domain approvals

The project owner approved the five domains for the MCP readiness process in the issue comment dated 2026-11-27 (issue comment 7221127155):

- HR
- Products
- Marketing
- Academic
- MCP audit retention

This is approval to document and validate the approved MCP capability boundary. It does not authorize production writes or broader fields, audiences, record scopes, or tools than those committed in the permission matrix.

### Audit retention

**Project-owner decision, 2026-11-27: retain MCP audit records for one year (365 days).**

The decision applies to the MCP audit record classes, including historical approval records and direct-write invocation audit events. The implementation must preserve unresolved executing and uncertain operations until they have been reconciled; retention must not destroy evidence needed to prevent unsafe retries or investigate an incident.

The direct-write idempotency ledger (mcp_direct_write_executions) is a distinct operational record class because it prevents duplicate execution and payload-key reuse. Its retention/de-identification mechanics must be handled without weakening the idempotency guarantee. Do not delete ledger rows as ordinary audit pruning.

Access remains least-privilege and permission-gated. Legal hold or active incident preservation takes precedence over routine expiry. Backups and exports containing MCP records must be included in the retention lifecycle and must not silently extend retention indefinitely.

The one-year duration is approved. The exact technical purge workflow, treatment of backup copies, and proof of deletion still require implementation and verification. Until that work is approved and tested, do not manually delete MCP records or claim automated expiry is active. The separate Spatie Activity Log cleanup setting is not the MCP retention mechanism.

### Independent reviewer

The project owner designated **Contes (GitHub account)** as the independent reviewer on 2026-11-27, replacing the earlier designation of Diana (os-edventure-ro user ID 3).

**Review status: pending.** Contes must submit the formal GitHub review on PR #608 after the complete staging evidence is available. Before recording sign-off, verify that the Contes GitHub account is authorized for the review and that the reviewer is independent of the test operator and AI principal. The GitHub review must identify the commit reviewed, evidence examined, findings, limitations, and explicit verdict. The designation and review request are not themselves a review or approval. GitHub reviewer request to `contes` was confirmed on PR #608 on 2026-11-27.

### Field, audience, and record-scope evidence

The committed permission matrix is the source of truth for OAuth scopes, required and optional HexaLite permissions, record scopes, operation type, and output-field allowlists:

- [Runtime permission matrix](../config/mcp.php) (version 1.8.0)
- [MCP permission matrix and authorization contract](mcp-permissions.md)
- [Extended HR, payroll, products, marketing, and academic tool contracts](mcp-extended-domain-tools.md)
- [CRM tool contracts](mcp-crm-tools.md)
- [Operations tool contracts](mcp-operations-tools.md)
- [Process-definition tool contracts](mcp-process-tools.md)

The AI Assistant principal is the execution identity. Each tool requires its dedicated OAuth scope and mapped domain permissions; handlers enforce the declared record scope and output allowlist. HR and academic outputs remain aggregate-only. Do not widen any field, audience, or record scope as part of readiness work.

## 2. Staging isolation

The 2026-11-27 staging update records operator-supplied evidence of:

- APP_ENV=staging;
- MAIL_MAILER=log;
- no Google Ads, e-Factura, or Slack credentials configured for the staging runtime;
- the edv.scl.ro queue-worker and scheduler cron jobs removed, with other sites' cron jobs left unchanged.

This evidence is recorded in issue comment 7221525380. A fresh read-only Hostinger account cron inventory check on 2026-11-27 confirmed that no `edv.scl.ro` queue-worker or scheduler entry is currently configured; the listed jobs belong to `cr8or.be`, `scl.ro/ee`, and `easyenglish.ro` and were left unchanged. This verifies the cron portion only. The available hosting API does not expose the running Laravel `APP_ENV`, `MAIL_MAILER`, or the presence/absence of the staging integration credentials in a way that can be safely verified here; those runtime values must be rechecked through an authorized staging operator immediately before the acceptance suite. Do not start shared-account workers or schedulers as part of the test.

The operator separately authorized staging-only testing against the existing edv.scl.ro staging database, asserting that its records are synthetic and backup/restore had been verified (issue comment 7217851320). This is staging-only authorization, not production authorization.

## 3. Autonomous direct-write acceptance suite

Run the suite against the isolated staging runtime with synthetic fixtures, unique idempotency keys, and production writes disabled. Record the build/commit, environment identifier, actor and OAuth client identity (never credentials), request/tool, expected and actual result, execution/audit reference, and cleanup or reconciliation outcome for every case.

| Case | Required result | Evidence |
| --- | --- | --- |
| Authentication and authorization | Missing/invalid identity, missing tool scope, insufficient domain permission, and revoked access are denied without mutation | Pending staging run |
| Record and field scope | Authorized records and fields succeed; out-of-scope records, prohibited fields, and unauthorized audiences are denied without disclosure | Pending staging run |
| Domain validation and state transitions | Invalid payloads and illegal transitions are rejected; valid writes use existing domain workflows | Pending staging run |
| Idempotency and duplicate submission | Same key and payload do not duplicate a write; same key with a changed payload is rejected; executing/uncertain claims are not replayed | Pending staging run |
| Concurrent writes and conflicting updates | Concurrent submissions do not create duplicate or inconsistent state; optimistic-version conflicts are deterministic | Pending staging run |
| Audit attribution and privacy | Successful and denied calls are attributable to the AI principal, OAuth client, tool, and target; raw sensitive arguments, results, tokens, and exception messages are absent | Pending staging run |
| Uncertain outcomes and recovery | Lost/ambiguous outcomes are marked uncertain; no automatic retry occurs; reconciliation precedes any recovery action | Pending staging run |
| Destructive-operation boundaries | Only explicitly supported and authorized operations execute; unsupported destructive actions are denied. Process deletion remains a soft delete | Pending staging run |
| Runtime side-effect isolation | Mail, queues, scheduled work, and external integrations remain isolated for the entire suite | Pending staging run |

The existing tests/Feature/McpApprovalWorkflowTest.php result (22 tests, 69 assertions) covers the historical approval workflow. It does not satisfy these direct-write scenarios. Local feature tests and CI do not substitute for the staging run.

### Staging execution record

- Staging environment/build: **Pending re-verification**
- Isolation evidence reference: **Issue comment 7221525380; account cron inventory rechecked 2026-11-27 (no edv.scl.ro queue/scheduler jobs); APP_ENV, MAIL_MAILER and integration credentials still require operator re-verification**
- Run date and operator: **Pending**
- Acceptance report and per-case evidence: **Pending**
- Failed cases and remediation: **Pending**
- Independent reviewer: **Contes (GitHub account; designated, formal review pending)**
- Reviewer decision/date/evidence reference: **Pending**

Do not mark the suite passed until every case has a recorded result from the isolated staging environment.

## 4. Independent review and release gate

Contes must inspect the complete staging report, verify the separation of duties, confirm that the tests ran only against the isolated synthetic staging environment, and submit a formal GitHub review on PR #608. The review must state the commit SHA, evidence examined, findings, limitations, explicit verdict, and any conditions. Link that review from issue #588.

The project owner must make a separate explicit decision before any production controlled-write enablement, after reviewing the exact tools, permissions, fields, environment change, retention implementation, passing staging report, and independent reviewer decision.

Until that separate owner decision exists, production writes remain disabled. A merged PR, green CI run, successful individual write, or staging authorization is not production authorization.

## 5. CI validation status

GitHub Actions run [#844](https://github.com/deputy-proxy/os-edventure-ro/actions/runs/46071509762), for PR code commit `cd3c8d71d5dfc7f25acbd3eef68016e40a5fb826` (immediately before the subsequent documentation-only reviewer/CI note commit), completed successfully on 2026-11-27. All five jobs passed: PHP tests and migrations (including PHPUnit), MySQL migration/integrity regression, MariaDB 11.8.9 migration/integrity regression, PHP static analysis, and frontend build/dependency audit. This validates repository CI only; it does not replace isolated staging acceptance.

## 6. Current acceptance status

| Acceptance criterion | Status |
| --- | --- |
| Project-owner approval for HR, Products, Marketing, Academic, and MCP audit retention | **Recorded** |
| One-year MCP audit retention decision | **Approved: 365 days; technical purge and backup lifecycle still to be implemented and verified** |
| Field, audience, and record-scope documentation | **Linked to committed permission matrix and domain contracts; test evidence remains part of acceptance** |
| Privacy/access tests for allowed and denied cases | **Repository coverage to be verified by CI; staging evidence pending** |
| All autonomous direct-write scenarios pass in isolated staging | **Pending** |
| Independent authorized human review | **Contes (GitHub account) designated; formal review pending** |
| Separate project-owner decision on production enablement | **Not granted; production writes remain disabled** |

Issue #588 must remain open until the staging suite, independent review, and remaining technical retention evidence are complete. No production data changes or new sensitive-field exposure are authorized by this issue.
