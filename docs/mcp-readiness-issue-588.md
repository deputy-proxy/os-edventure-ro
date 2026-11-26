# MCP privacy approvals and controlled-write staging record (#588)

**Status: implementation prepared; external approvals and staging sign-off pending.**  
**Last verified:** 2026-11-26  
**Production write enablement:** disabled; this document does not authorize a configuration or permission change.

This record is the evidence index for issue #588. It separates repository-verifiable controls from decisions that only authorized role-holders, the policy owner, staging operator and independent reviewer can make. Approvals are role-based and may be given by any one eligible approver; named individuals do not need to be pre-registered. The authenticated user who makes each decision must still be recorded in the restricted evidence record.

## 1. Domain-owner privacy approvals

No production approval is inferred from issue #539's development authorization. Before any extended-domain production approval, record one signed approval per domain below from any one eligible role-holder. Attach the signed record to a restricted project evidence location; do not put employee/student samples, sensitive values, credentials or private attachments in GitHub.

| Domain | Eligible approver role(s), any one may approve | Approved audience / roles | Approved fields and purpose | Record scope / exclusions | Decision and date | Evidence reference |
| --- | --- | --- | --- | --- | --- | --- |
| HR | Owner OR General Director | Pending | Pending exact field allowlist | Aggregate/record scope and prohibited fields to be explicitly confirmed | Not approved | Not supplied |
| Products | Owner OR General Director | Pending | Pending exact field allowlist | Product scope, cost/price sensitivity and effective-date semantics to be explicitly confirmed | Not approved | Not supplied |
| Marketing | Owner OR General Director | Pending | Pending exact field allowlist | Campaign/observation scope, source provenance and commercial restrictions to be explicitly confirmed | Not approved | Not supplied |
| Academic | Owner OR General Director OR Academic Director | Pending | Pending exact field allowlist | Student/group/session scope, minimum aggregation and student privacy exclusions to be explicitly confirmed | Not approved | Not supplied |

Each domain approval must identify:
- the authenticated approver and their eligible role (Owner, General Director, or for Academic, Academic Director); any one eligible role-holder is sufficient, with no joint-approval requirement;
- the exact tool names and field paths, including nested fields, not a broad domain label;
- the purpose, audience and permitted roles/client identity;
- record scope, aggregation threshold where relevant, and prohibited fields;
- whether the approval is development-only or production, plus effective date and review/expiry date;
- any conditions, source-system constraints and withdrawal contact;
- a durable evidence reference and reviewer identity.

The current implementation's allowlists and aggregate-only HR/academic behavior are documented in docs/mcp-extended-domain-tools.md and config/mcp.php. Approval must review those exact committed paths against the intended deployed permission matrix. It must not silently widen them.

## 2. MCP audit retention and access decision

**Decision: pending.** No MCP-specific retention duration or deletion schedule has been approved. The current safe behavior remains: dedicated MCP approvals and execution audit rows are retained; application-level deletion is denied; no automatic MCP audit pruning is scheduled. The 365-day Spatie Activity Log cleanup setting does not apply to these tables.

The audit-retention decision may be approved by either the Owner or the General Director; joint approval is not required. A legal/privacy reviewer may advise, but is not a mandatory co-approver. Record the authenticated approver and role in the restricted evidence record:

| Decision | Required value | Current status |
| --- | --- | --- |
| Authorized approver | Owner OR General Director (any one) | Pending |
| Legal/privacy advice (if obtained) | Reviewer and advice reference | Pending / optional |
| Retention duration by record class | mcp_approvals, mcp_execution_audits, related backups and exports considered separately | Pending |
| Retention start event | Created, executed, closed, or another explicitly justified event per class | Pending |
| Legal hold / incident preservation | Trigger, authority, release and audit procedure | Pending |
| Access audience | Named roles/permissions, least privilege and periodic access review | Pending |
| Deletion/disposal | Authorized method, approval, backup expiry and evidence | Pending |
| Policy reference/version | Durable approved policy identifier and effective date | Pending |

Do not infer a duration from statutory record classes, generic activity-log configuration, backup defaults or another application's policy. Until approval is recorded, preserve records and do not introduce automated deletion.

## 3. Repository controls already covered by automated tests

The existing tests/Feature/McpApprovalWorkflowTest.php exercises the application-level approval contract, including:
- encrypted payload persistence and canonical payload hashing;
- same-key/same-payload idempotent replay and same-key/different-payload rejection;
- OAuth client binding;
- payload tampering and permission-matrix version invalidation;
- independent human approval, AI self-approval denial and known initiator separation;
- expiry, single-use decisions and rejection/cancellation;
- permission revocation before the domain callback;
- concurrent execution claim rejection;
- uncertain execution without automatic retry and stale-claim recovery;
- immutable approval/audit identity fields and deletion denial;
- sensitive target/preview filtering and reviewer UI authorization.

These are repository feature tests, not evidence of a deployed isolated-staging run. Their passing result cannot satisfy the staging or independent-review acceptance criteria by itself.

## 4. Isolated staging acceptance protocol

Run only in an isolated staging environment with synthetic records, a staging-only AI principal/client, staging-only credentials, and a separate authorized human reviewer. Confirm the environment identity and that no production database, queue, credentials, OAuth client or external side effect is reachable before testing. Keep all production write permissions and tools disabled.

For each case, retain timestamped evidence containing the test case, build/commit, environment identifier, actor/reviewer role (not credentials), approval/request IDs, expected result, observed result and audit-event references. Redact tokens, payload contents, personal data and secrets.

| Case | Procedure | Required result | Evidence |
| --- | --- | --- | --- |
| Exact-payload binding | Request a synthetic operation, review its preview/hash, then alter one canonical argument before approval/execution in a controlled test fixture | Altered payload is rejected; no domain callback occurs | Pending staging run |
| Approval expiry | Create request, advance beyond configured TTL, attempt review and execution | Request is expired; callback is not invoked; expiry audit is recorded | Pending staging run |
| Replay / single use | Approve and execute once; repeat the execution request and separately attempt a second decision | No second mutation; stored result may be returned idempotently; decision cannot be overwritten | Pending staging run |
| Idempotency | Submit same principal/client/key/payload twice, then same key with changed payload | Same payload resolves to original operation/result; changed payload is rejected | Pending staging run |
| Concurrent approval/execution | Coordinate two workers against one approved synthetic request | Exactly one worker claims and invokes the callback; competing worker is rejected; no duplicate side effect | Pending staging run |
| Audit attribution | Execute with a distinct reviewer and inspect approval/execution events | AI principal, OAuth client, request/tool/target, payload hash, reviewer and timestamps are attributable; no raw sensitive payload/token is logged | Pending staging run |
| Uncertain-execution recovery | Simulate interruption/ambiguous callback outcome after claim; reconcile source domain state before recovery | State becomes uncertain; no automatic retry; operator can reconcile using domain event/audit evidence without duplicate mutation | Pending staging run |
| Denial/privacy paths | Exercise unauthorized reviewer, AI self-review, insufficient scope, revoked permission and out-of-scope/sensitive fields | Deny by default; no disclosure or mutation; safe denial audit evidence | Pending staging run |

### Staging execution record

- Staging environment identifier/build: **Pending**
- Isolation verification (database, queue, credentials, OAuth client, external side effects): **Pending**
- Run date and operator: **Pending**
- Test report/evidence reference: **Not supplied**
- Failed cases and remediation PRs: **Pending**
- Independent authorized reviewer (must be separate from operator and AI principal): **Pending**
- Reviewer decision, date and evidence reference: **Pending**

Do not mark the suite passed until every case above has a recorded result from the isolated staging environment. A local SQLite/MySQL/MariaDB test run is not a substitute.

## 5. Independent review and release gate

The independent reviewer must inspect the complete staging evidence, verify the separation of duties and confirm no production system was touched. Record the independent reviewer's name/role, decision, date, evidence reference and any conditions in the restricted evidence location. This independent staging reviewer is separate from the role-based domain and audit-policy approvers.

The project owner must then make a separate, explicit decision on controlled-write enablement after reviewing:
1. all four domain privacy approvals (where extended domains are proposed for production);
2. the approved MCP audit retention/access/deletion policy;
3. the complete passing staging report;
4. independent reviewer sign-off;
5. the exact production tools, permissions, fields and environment change proposed.

Until that separate owner decision exists, controlled writes remain disabled. This issue, a passing CI run, a merged documentation PR, or conversational confirmation is not authorization to enable them.

## 6. Current issue disposition

| Acceptance criterion | Status |
| --- | --- |
| Named owner approval for each extended domain | **Blocked: approvals not supplied** |
| Explicit MCP audit retention decision | **Blocked: policy-owner/legal decision not supplied** |
| Allowed/denied privacy tests | **Automated application coverage exists; domain-specific approval and staging evidence pending** |
| Complete controlled-write suite in isolated staging | **Blocked: no staging execution evidence supplied** |
| Separate authorized human reviewer sign-off | **Blocked: reviewer evidence not supplied** |
| Separate project-owner enablement decision | **Not requested or authorized; writes stay disabled** |

This issue can be closed only after the missing external decisions and staging evidence are recorded, or the project owner explicitly changes the acceptance scope in a separately documented decision. Do not represent this preparatory implementation as production readiness.
