# MCP approval and execution audit controls

> **Historical compatibility only:** This page documents the legacy per-operation approval workflow retained for historical records and compatibility tests. The active MCP write tools use autonomous direct execution. New write tools must use execution_mode => direct; no per-operation human approval is requested in the active MCP write runtime.

## Status and boundary

Issue #532 implemented the historical approval and audit infrastructure. At that time, the registry contained 48 read-only tools and nine approval-gated operations. The six #537 operations were create_task, update_task, assign_task, complete_task, create_customer_interaction and create_customer_lead. Issue #575 added create_process, update_process and delete_process. Each was exposed as a request tool plus an execution companion; the request never mutated business data. The active runtime has since migrated to one-call direct writes as documented below.

The approval service is not used by active direct-write tools. Active writes execute in one call through existing authorized domain workflows, guarded by the current OAuth scope, AI principal, live domain permissions, record/field constraints, domain validation, idempotency ledger, and privacy-safe audit. Historical approval records remain available for reconciliation; they are not execution gates.

## Persistence

Two dedicated tables separate operation payloads from the audit trail:

- `mcp_approvals` stores the AI principal, optional trusted human initiator, OAuth client, tool and permission-matrix version, target, request ID, idempotency key, canonical payload hash, encrypted arguments, encrypted human-readable preview, lifecycle state, decision rationale, encrypted result, and expiry/review/execution timestamps.
- `mcp_execution_audits` stores the AI principal, OAuth client, tool/version, target, request ID, idempotency key, payload hash, approval reference, human initiator/approver where known, event type, status, safe result/error code, timestamps, and a domain-event reference where available.

The audit table deliberately has no argument or result payload column. Raw arguments, encrypted approval arguments, result bodies, tokens, credentials, and exception messages are not copied into audit events. The approval model encrypts its argument, preview, and result arrays at rest using Laravel's application encryption key. Protect `APP_KEY` and backups accordingly.

Approval and audit identity/payload fields are immutable through their Eloquent models. Application-level deletion is denied. Foreign keys restrict deletion of principals and human reviewers while retained records refer to them. The approval-to-execution pointer is intentionally nullable and does not create a cyclic foreign key.

## Approval lifecycle

The current states are:

| State | Meaning |
| --- | --- |
| `pending` | Validated request is awaiting an independent os-edventure-ro human reviewer |
| `approved` | A permitted human reviewer approved the exact payload |
| `rejected` | A permitted human reviewer rejected the request |
| `expired` | The approval deadline passed before review or execution |
| `cancelled` | The requesting AI principal cancelled a pending or approved request |
| `executing` | A single worker claimed the approved operation |
| `executed` | The domain callback returned a confirmed result and it was persisted |
| `execution_blocked` | Authorization or domain validation failed before a confirmed mutation; the operation was not committed |
| `execution_uncertain` | Execution returned no confirmed outcome or was interrupted; automatic retry is prohibited |

Pending and approved requests expire after 15 minutes by default. Configure the duration with `MCP_APPROVAL_TTL_MINUTES` (bounded to 1–1,440 minutes). The maximum encoded argument/result size is 64 KiB by default (bounded between 1 KiB and 1 MiB); configure it with `MCP_APPROVAL_MAX_PAYLOAD_BYTES`.

A request requires a non-empty human-readable preview of up to 20 short scalar fields. Field names that indicate credentials, contact details, banking, identity or medical data are rejected. Target metadata is restricted to safe record types and non-sensitive identifiers; email addresses, IBAN-like values and long numeric personal identifiers are not copied into the audit. Preview values are encrypted at rest and only the preview, not the raw arguments, is shown in the os-edventure-ro list.

The human reviewer must have the explicit `mcp_approval.approve` permission and must be distinct from the AI principal and, when known, the human initiator. The AI principal is denied all approval UI permissions even if a role is misconfigured. Review actions require a concise rationale. Approval and rejection are separate audited events.

The OAuth consent flow currently does not persist the human who initiated the ChatGPT connection as an operation initiator. `human_initiator_id` therefore remains null unless a trusted server-side integration sets the `mcp_human_initiator_id` request attribute. Never accept this identity from MCP arguments.

## Payload binding, idempotency and execution

The SHA-256 payload hash covers canonicalized arguments, preview, tool name, permission-matrix version, and target type/ID. Associative object keys are sorted; list order is preserved. Approval fails if the stored payload no longer hashes to the original value or if the permission-matrix version changed.

The approval request requires the OAuth client ID extracted from the validated Passport JWT. Execution and cancellation are bound to that same client ID; a different ChatGPT OAuth client cannot reuse or execute another client’s approval. The client identifier is also included in the invocation and approval audit records.

The idempotency key is unique per AI principal and OAuth client:

- Same principal + same client + same key + same canonical payload returns the original approval and, after execution, its stored result. The request boundary checks for an existing idempotent payload before rerunning mutable domain preview validation, so a replay after lead creation does not fail merely because the email is now present.
- Same principal + same key + different client or different payload is rejected.
- A database uniqueness constraint resolves simultaneous duplicate submissions.

Approval is single-use. Execution claims the approval row under a database lock and moves it to `executing` before the callback can run. A second worker cannot claim an already executing operation. The service re-checks the current AI principal, OAuth scopes, domain permissions, payload integrity, expiry, and distinct human approver. It performs a second authorization check immediately before invoking the domain callback.

Historical approval-mode registrations used requires_approval => true and paired request/execution tools. They are not published by the active registry. New write tools must use the direct execution boundary and must not introduce a request/approval/execution companion flow unless the architecture is explicitly changed and reviewed.

The task callbacks use TaskWorkflow; CRM callbacks use CreateCustomerInteraction and CreateCustomerLead. These actions preserve the existing task assignment policy, project/phase relationship, customer-lead validation, status and attribution model events. Future tool callbacks must use an existing application/domain service rather than parallel business logic or direct persistence that bypasses policy and lifecycle checks.

If authorization is revoked before execution or domain validation detects a stale task version/invalid transition before a mutation is committed, the operation becomes `execution_blocked`. If the callback returns no confirmed outcome, throws an unexpected exception, returns an invalid/oversized result, or the process stops before completion is stored, the operation becomes `execution_uncertain`. The service never retries an uncertain operation automatically. Reconcile the domain state and its domain event before taking any manual recovery action.

The scheduled `mcp:expire-approvals` command runs every minute. It expires pending/approved requests and marks stale `executing` claims uncertain after five minutes by default (`MCP_EXECUTION_STALE_MINUTES`). The stale threshold must remain longer than the deployed maximum synchronous tool execution time.

## os-edventure-ro access

The authenticated Filament panel exposes two read-only resources:

- **MCP approvals** at `/admin/mcp-approvals`
  - `mcp_approval.index`: view approval requests and safe previews.
  - `mcp_approval.approve`: approve or reject a pending request with a required rationale.
- **MCP execution audit** at `/admin/mcp-execution-audits`
  - `mcp_execution_audit.index`: view execution and approval audit events.

Grant these permissions only to named staff roles responsible for reviewing MCP activity. The AI Assistant role must not receive them. Resources do not provide create, edit, delete, bulk-delete, or export actions. The service repeats authorization checks when an action is submitted; hiding a UI action is not the security boundary.

## Audit retention and incident review

**Approved retention:** the project owner approved one year (365 days) for MCP audit records on 2026-11-27. This applies to historical approval/audit records and direct-write invocation audit events. The technical purge workflow, backup/export lifecycle, and deletion evidence are not yet implemented or verified. Until then, preserve records and do not delete them manually. The direct-write idempotency ledger is a separate operational record class and must not be pruned in a way that permits duplicate execution or changed-payload key reuse. Preserve unresolved executing and uncertain records until reconciliation. Legal hold and active incident preservation override routine expiry. Do not infer that the Spatie Activity Log cleanup setting applies to these MCP tables.

Access to approval records and execution audit records is permission-gated as described above. Review access should be limited to designated staff and reviewed periodically. Do not export or copy decrypted approval arguments/results into tickets or chat transcripts.

For an MCP incident:

1. Contain access first. Remove the AI principal's mapped domain permission or revoke its tokens with `php artisan mcp:revoke-principal-tokens` where token compromise is suspected.
2. Search the execution audit by request ID, approval ID, OAuth client ID, AI principal, tool/version, target, idempotency key, and time window.
3. Verify the payload hash, reviewer identity, rationale, approval status, and domain-event reference. Retrieve decrypted arguments only from the protected approval UI/service when strictly necessary.
4. For `execution_uncertain`, inspect the domain record and its own event/audit history before any recovery. Never retry simply because the MCP caller did not receive a response.
5. Record the incident decision and any manual reconciliation in the relevant domain incident/change record. Preserve the MCP approval and audit rows.
6. Restore permissions or reauthorize the connector only after containment and review are complete.

## Issue #588 privacy and staging readiness

The domain-owner approvals, one-year retention decision, designated independent reviewer, isolated-staging acceptance protocol, evidence fields and release gate are tracked in [MCP autonomous direct-write readiness](mcp-readiness-issue-588.md). Automated feature coverage does not substitute for staging execution or independent reviewer sign-off. Production controlled writes remain disabled until the separate project-owner decision is recorded.

## Validation

The feature tests cover encrypted payload persistence, canonical hash stability, idempotency collisions, payload tampering, independent human review, AI self-approval denial, known initiator separation, rejection, cancellation, expiry, permission revocation immediately before execution, concurrent claims, uncertain outcomes, stale recovery, immutable audit history, and the Filament review pages. The MCP authorization tests also assert success and denied invocation audit records.

## Autonomous direct-write execution (permission matrix 1.8.0)

The nine supported business writes are exposed as one-call tools: create/update/assign/complete task, create customer interaction, create customer lead, and create/update/delete descriptive process definitions. Their execution mode is explicitly `direct`; the old request and execution companion registrations are not published.

Direct writes retain Passport authentication, OAuth client binding, per-tool scopes, the configured Assistant principal, required domain permissions, record scopes, domain validation, output allowlists and privacy-safe invocation auditing. Each call requires an idempotency key.

The `mcp_direct_write_executions` ledger is separate from historical approval records. Its unique key binds principal, OAuth client, tool and idempotency key. It stores a canonical payload hash and encrypted result. A matching completed replay returns the stored result; a changed payload is rejected; an executing or uncertain key is not replayed. The domain mutation and confirmed result are committed in one database transaction. Unexpected outcomes are marked uncertain and require reconciliation before any new key or manual recovery is used.

Historical mcp_approvals rows, approval-related mcp_execution_audits events, and review pages remain available for reconciliation; they do not gate the active direct-write path. Do not retry or reset existing uncertain operations automatically.
