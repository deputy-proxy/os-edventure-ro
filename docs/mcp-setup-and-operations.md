# MCP setup and operations guide

Last reconciled with the repository implementation: 2026-11-22.

This is the operational entry point for the os-edventure-ro MCP integration. It describes committed application behavior and the currently documented ChatGPT connection flow. It is not evidence that the production endpoint, reverse proxy, or a live ChatGPT workspace has passed end-to-end acceptance.

## 1. Current capability and safety boundary

The remote MCP endpoint is **/mcp** and uses Streamable HTTP. The current registry contains 56 tools:

- 44 read-only tools covering executive reporting, finance, CRM, operations, HR/payroll, products, marketing, and academic operations;
- 12 approval-request/execution tools representing six controlled task/CRM operations: create, update, assign, and complete tasks; create customer interactions; and create customer leads.

The server also exposes five permission-aware read-only resources and five prompt templates. Prompts do not fetch data or grant permissions; each tool/resource call is independently authorized.

Every tool requires its dedicated OAuth scope and the configured AI principal. Dynamic client registration advertises and allows the complete scope set from the permission matrix so ChatGPT can request the scopes needed by discovered tools; the issued token must still contain the individual tool scope. Required/optional HexaLite permissions, record scopes, and nested output-field allowlists are enforced server-side. The AI principal must never receive Owner, superadmin, or blanket administrator privileges.

The six task/CRM operations are not direct writes from the initial request. A request creates a pending approval containing an immutable encrypted payload. A separate eligible human reviewer must approve the exact payload in the os-edventure-ro panel. The execution tool accepts only the approval ID and rechecks authorization before calling the existing domain action. ChatGPT's conversational confirmation is not a substitute for this approval.

No MCP write is available for payroll, invoices, financial corrections/reversals, lead conversion, or user/role administration. HR and academic outputs remain aggregate-only. Production enablement and any expansion of sensitive fields remain subject to the field, audience, and retention review recorded in [issue #539](https://github.com/deputy-proxy/os-edventure-ro/issues/539).

## 2. Configuration reference

The source of truth is **config/mcp.php** and **.env.example**.

| Variable | Default / example | Purpose |
| --- | --- | --- |
| **MCP_AI_PRINCIPAL_EMAIL** | chatgpt@edventure.ro in .env.example | Dedicated AI Assistant principal email. Set the approved environment-specific identity. |
| **MCP_DOMAIN_PERMISSION_GUARD** | web in .env.example; otherwise Laravel default guard | Guard used for HexaLite permission checks. |
| **MCP_AUTHORIZATION_SERVER** | Unset; falls back to application URL | Canonical OAuth issuer. Set only when it differs from the application URL. |
| **MCP_OAUTH_REDIRECT_DOMAINS** | https://chatgpt.com | Comma-separated exact OAuth redirect origins. Never use wildcards. |
| **MCP_ALLOWED_HOSTS** | Unset; defaults to hostname parsed from APP_URL | Host allowlist for DNS-rebinding protection. |
| **MCP_ALLOWED_ORIGINS** | Unset; defaults to empty list | Browser CORS allowlist. Cross-origin browser access is disabled by default. |
| **MCP_MAX_BODY_BYTES** | 1048576 | Maximum request body size (1 MiB in the example). |
| **MCP_REQUEST_TIMEOUT_SECONDS** | 25 | Application timeout cap on non-CLI SAPIs; never raises a lower host timeout. |
| **MCP_SESSION_PATH** | storage/framework/cache/mcp-sessions | File-backed legacy protocol session directory. |
| **MCP_SESSION_TTL_SECONDS** | 3600 | Legacy protocol session lifetime. |
| **MCP_SESSION_LOCK_TIMEOUT_SECONDS** | 5 | File-session lock wait limit. |
| **MCP_PAGINATION_LIMIT** | 50 | Default bounded pagination limit. |
| **MCP_ACCESS_TOKEN_TTL_MINUTES** | 60 | Passport access-token lifetime. |
| **MCP_REFRESH_TOKEN_TTL_DAYS** | 30 | Passport refresh-token lifetime. |
| **MCP_APPROVAL_TTL_MINUTES** | 15 | Pending/approved operation lifetime. |
| **MCP_APPROVAL_MAX_PAYLOAD_BYTES** | 65536 | Maximum encoded approval payload size (64 KiB). |
| **MCP_EXECUTION_STALE_MINUTES** | 5 | Age after which interrupted execution is marked uncertain. |
| **MCP_SERVER_NAME** | os-edventure-ro | MCP server identity shown to clients. |
| **MCP_SERVER_VERSION** | 0.1.0 | MCP server version metadata. |
| **APP_DEBUG** | `false` in .env.example | Must remain `false` in production so exception traces and filesystem paths are not disclosed. |

APP_URL, APP_KEY, database settings, and Passport signing keys are also required by Laravel. Keep .env, APP_KEY, storage/oauth-private.key, and all environment credentials outside source control. Protect the Passport private key and include it in the environment's approved secure key-backup process. Keep `APP_DEBUG=false` in production; the committed `.env.example` uses this safe default.

The `/ready` endpoint checks database connectivity and validates both Passport signing keys, including their file permissions; it returns `503 not_ready` when either dependency is unhealthy. Composer's `post-autoload-dump` hook applies mode `0600` to existing file-backed Passport private/public keys after dependency installation. It never generates, rotates, reads, or changes key contents. If a deployment still reports a Passport key permission error, repair the existing files over SSH from the application root with `chmod 600 storage/oauth-private.key storage/oauth-public.key`, then refresh Laravel's cached configuration with `php artisan config:clear && php artisan config:cache`. Verify modes with `stat -c '%a %n' storage/oauth-private.key storage/oauth-public.key`; both should report `600`. Do not run `passport:keys --force` as a permissions repair because it replaces the signing keys and invalidates existing tokens.
The MCP route is limited to 60 requests per minute per source IP. The default request-body limit is 1 MiB and the non-CLI application timeout cap is 25 seconds. These application limits do not override lower reverse-proxy, PHP-FPM, CDN, or hosting limits.

## 3. Local development and protocol inspection

From a clean checkout, use the committed lockfiles. Keep local values in .env; never commit them.

~~~sh
cp .env.example .env
composer install --no-interaction --prefer-dist --no-progress
npm ci
php artisan key:generate --force
php artisan passport:keys --force
mkdir -p database && touch database/database.sqlite
php artisan migrate --force
npm run build
~~~

Set APP_URL to the same local origin used by the client, for example http://127.0.0.1:8000, and allow that host:

~~~sh
MCP_ALLOWED_HOSTS=127.0.0.1,localhost php artisan serve --host=127.0.0.1 --port=8000
~~~

The local endpoint is http://127.0.0.1:8000/mcp. OAuth discovery is exposed at:

- /.well-known/oauth-authorization-server
- /.well-known/oauth-protected-resource

MCP POST requests require a Passport bearer token with mcp:use and the tool-specific scope. Dynamic registration returns the full set of matrix scopes; ChatGPT can then request each tool scope during authorization. Metadata advertises authorization-code and refresh-token grants. The implementation requires PKCE S256 and validates the OAuth resource parameter against /mcp. Existing clients registered before this behavior retain their previous scope allowlist and must be disconnected and registered again to obtain the expanded scopes.

For the web MCP Inspector, run **npx @modelcontextprotocol/inspector**, select Streamable HTTP, and enter the local endpoint. Use its OAuth flow. Add only the exact callback origin shown by the Inspector to MCP_OAUTH_REDIRECT_DOMAINS in local .env; never use a wildcard. The Inspector's unauthenticated CLI commands are not suitable for this protected endpoint.

Focused transport/OAuth regression tests:

~~~sh
php artisan test tests/Feature/McpTransportTest.php tests/Feature/McpOAuthTest.php
~~~

## 4. Connect a ChatGPT workspace

OpenAI's current [custom MCP server connection guide](https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt) documents custom MCP connections on ChatGPT web for Business and Enterprise/Edu workspaces. Business workspaces restrict creation and publication to admins/owners. Enterprise/Edu admins/owners and members granted the connection permission through RBAC can create/test privately; only admins/owners can publish. Pro users have read/fetch-only custom MCP access; full MCP write/modify support requires Business or Enterprise/Edu. Custom MCP connections are web-only, not mobile. UI and plan availability can change; check the current OpenAI guide and workspace permissions before rollout.

Current Help Center flow:

1. Open **Plugins** in ChatGPT web. Developer Mode does not need to be enabled first.
2. Select the plus button and **Add custom MCP server**.
3. Enter a name and optional description.
4. Enter the public HTTPS MCP endpoint ending in **/mcp**.
5. Configure OAuth authentication.
6. Review and acknowledge the risk warning, then create the connection as a plugin.
7. Wait for tool discovery and review the discovered names and metadata before testing.

The Help Center also documents a legacy Apps flow. Follow the current workspace UI rather than assuming legacy navigation remains present. Custom MCP connections are web-only.

During OAuth, an authenticated os-edventure-ro user completes consent. The Passport token subject is the dedicated AI Assistant principal, not the human who approved the connection. The default redirect allowlist contains https://chatgpt.com; add another callback origin only if OpenAI documents it for the selected workspace flow and it has been reviewed. Never use a wildcard.
OpenAI's connection guide requires an OAuth provider that issues refresh tokens and recommends that OpenID Connect discovery advertise offline_access (or an equivalent refresh capability). This application currently advertises authorization_code and refresh_token in grant_types_supported, but its scopes_supported list does not include offline_access. The refresh-token grant is implemented and tested locally, but whether ChatGPT accepts that metadata as the equivalent capability has not been verified. Treat this as an explicit interoperability gate: inspect the live discovery metadata and complete a real workspace OAuth test; if ChatGPT requires offline_access, track and implement that scope before claiming the connection is supported.

Test a low-risk read tool first and confirm output is limited to the AI principal's permissions. A successful tool scan does not prove every tool is authorized or production-ready. Test task/CRM writes only in a controlled environment with a designated independent human reviewer and the os-edventure-ro approval workflow enabled.

ChatGPT may ask users to confirm write actions, but that UI confirmation does not approve the os-edventure-ro operation. A human with mcp_approval.approve must review the pending operation in the authenticated os-edventure-ro UI, distinct from the AI principal and, when known, the human initiator.

Workspace apps use a reviewed snapshot of discovered tool definitions. When server schemas or metadata change, an administrator must refresh/review the tool list and publish the updated version as required by the workspace UI. Server-side changes do not automatically become enabled workspace actions.

**Interoperability status:** automated tests cover OAuth discovery, public-client registration, callback allowlisting, PKCE S256, resource/audience binding, authorization-code exchange, refresh-token rotation, replay rejection, and token revocation. A live ChatGPT workspace connection and the deployed public HTTPS/reverse-proxy request lifecycle have not been recorded as end-to-end verified. Do not describe them as production-validated until evidence is retained.

## 5. Provision and manage the AI principal

The configured identity must be a dedicated user with exactly one global HexaLite role named Assistant for the active guard. The role must not be tenant-scoped. Grant only domain permissions explicitly mapped by the runtime MCP permission matrix and approved for that environment. OAuth scopes do not replace underlying domain permissions.

After migrations and role setup:

~~~sh
php artisan mcp:provision-principal
~~~

The command creates the configured user with a random undisclosed password and attaches the global Assistant role, or verifies an existing AI Assistant account. It refuses unexpected roles, panel access, missing/duplicate Assistant roles, and permissions not declared by the matrix. It does not copy a human user's permissions. Keep the Assistant role attached even when no domain permissions are assigned; the application's legacy permission helper treats users without roles as implicitly authorized, while the explicit role preserves deny-by-default behavior.

Passport access tokens default to 60 minutes and refresh tokens to 30 days. The OAuth refresh flow is covered by automated tests. The AI principal's random password is not a routine ChatGPT credential and must not be distributed.

For suspected token compromise or emergency disconnection:

~~~sh
php artisan mcp:revoke-principal-tokens
~~~

Run this from an authorized operator shell. It revokes all active access and refresh tokens for the configured AI principal in one database transaction. This is principal-wide, not per ChatGPT client. After containment and review, reauthorize the ChatGPT connection only when the owner approves restoration. There is no separate MCP password-rotation command in the current application.


## 6. Permission matrix and tool contracts

The versioned runtime permission matrix is config/mcp.php (currently version 1.6.0). It is the source of truth for each tool's OAuth scope, required/optional HexaLite permissions, record scope, nested output-field allowlist, read/write classification, and approval requirement.

The AI principal receives no domain permissions automatically. Assigning a permission to its Assistant role must be deliberate and limited to keys declared by the matrix. Handlers check optional source permissions independently. List/report handlers apply record scopes before filters, counts, aggregation, and pagination.

Use the domain contract documents for exact schemas, output fields, date semantics, units, currencies, provenance, and privacy limits:

- [Executive and management tools](mcp-executive-tools.md)
- [Finance tools](mcp-finance-tools.md)
- [CRM and sales tools](mcp-crm-tools.md)
- [Operations tools](mcp-operations-tools.md)
- [Extended HR, payroll, products, marketing, and academic tools](mcp-extended-domain-tools.md)
- [Complete permission matrix](mcp-permissions.md)

Do not infer missing data as zero, combine unlike currencies or units, widen record scope for convenience, or return fields merely because they exist on an Eloquent model.

## 7. Human approvals and execution audit

The os-edventure-ro Filament panel exposes:

- MCP Approvals: /admin/mcp-approvals, permissions mcp_approval.index to view and mcp_approval.approve to approve/reject with a rationale;
- MCP Execution Audit: /admin/mcp-execution-audits, permission mcp_execution_audit.index to inspect audit history.

Grant these permissions only to designated human reviewers/auditors. The AI principal is denied these UI permissions even if misconfigured. A reviewer must be independent of the AI principal and, when a trusted initiator is known, distinct from that initiator.

Approval requests are bound to a canonical SHA-256 payload hash, encrypted at rest, single-use, and expiring. The default approval TTL is 15 minutes. Execution rechecks the AI principal, OAuth client, scope, domain permissions, payload integrity, expiry, and reviewer eligibility. The execution companion accepts only the approval ID; callers cannot replace the approved arguments.

The audit trail is stored separately from encrypted operation payloads. Audit rows contain safe identifiers, state, result/error codes, timestamps, approval references, and domain-event references where available; raw arguments, result bodies, access tokens, and exception messages are not copied into audit events.

The scheduler runs **mcp:expire-approvals** every minute. It expires pending/approved requests and marks stale executing claims uncertain after the configured stale interval. For execution_uncertain, inspect the domain record and its domain-event history before manual recovery. Never retry an uncertain operation merely because ChatGPT did not receive a response.

**Retention gap:** no MCP-specific audit retention duration has been approved by the policy owner/legal reviewer. The application schedules no automatic MCP audit deletion. Do not assume the separate Spatie Activity Log retention setting applies to MCP approval/audit tables. Production enablement remains subject to a documented retention decision.

See [MCP approval and execution audit controls](mcp-approvals-and-audit.md) for the full lifecycle and incident procedure.

## 8. Deployment and production-readiness gates

Before enabling the endpoint for a real workspace, verify in the target environment:

- public HTTPS, certificate validity, canonical APP_URL, OAuth issuer, host allowlist, and redirect-origin allowlist;
- Passport signing keys, APP_KEY, and environment secrets are stored securely, Passport key files use mode `0600`, and production `APP_DEBUG=false` is verified;
- deployed PHP/database versions and required extensions satisfy the committed manifests;
- the reverse proxy/CDN passes Streamable HTTP responses without buffering or truncation that breaks the protocol;
- effective PHP-FPM, web-server, CDN, and platform timeouts are compatible with the application request cap;
- request-size limits and rate limiting are consistent at the edge and application;
- the file-backed MCP session path is writable and persistent; if requests can reach multiple hosts with non-shared filesystems, use a shared PSR-16 session store and distributed lock before routing across nodes;
- the scheduler invokes Laravel schedule:run every minute so approval expiry/recovery runs;
- the AI principal has only reviewed permissions and designated human reviewers have separate approval/audit permissions;
- audit access, retention, monitoring, incident response, backup, and recovery procedures are approved and tested;
- the live ChatGPT workspace connection and safe read/write approval flow have been exercised and recorded.

The repository records a read-only Hostinger runtime snapshot from 2026-11-19, but it does not prove the deployed /mcp request lifecycle. CDN/proxy buffering, effective PHP-FPM timeout behavior, multi-instance topology, and live ChatGPT interoperability remain staging verification items. Follow [production operations and recovery](operations-and-recovery.md) for general release, health, queue, scheduler, backup, incident, and recovery procedures. Green CI does not prove hosting compatibility.

Deployment must follow the approved release process: reviewed PR, green required CI, verified backup, migration preflight, one authorized migration execution, cache refresh, queue-worker restart where applicable, and post-deploy health/workflow checks. Never run migrations concurrently from multiple replicas or roll back schema changes without reviewing the migration's data-preservation behavior.

## 9. Monitoring, incident response, and recovery

Use existing application and infrastructure controls rather than exposing a new public MCP diagnostics endpoint:

- Review authentication/authorization failures and transport errors in restricted application logs using the request ID; do not log bearer tokens or request bodies.
- Review MCP approval and execution audit records for tool failures, approval backlog, rejected/expired requests, and uncertain executions.
- Use the existing [monitoring alert matrix](monitoring-alert-matrix.md) for platform-level coverage. No MCP-specific external alert thresholds or delivery route are recorded as verified; establish and test them with the system owner before claiming operational alert coverage.

- /up is the framework liveness endpoint;
- /ready verifies a trivial query against the default database connection;
- X-Request-ID correlates HTTP requests with restricted application logs;
- existing operations monitoring commands check queue/scheduler heartbeats and failed-job counts.

For MCP-specific incidents:

1. Contain access by removing the AI principal's mapped domain permission or, when token compromise is suspected, revoking its Passport tokens with **php artisan mcp:revoke-principal-tokens**.
2. Search the MCP execution audit by request ID, approval ID, OAuth client ID, principal, tool/version, target, idempotency key, and time window.
3. Verify approval status, payload hash, reviewer identity/rationale, and domain-event reference. Retrieve decrypted approval data only through the protected os-edventure-ro workflow when strictly necessary.
4. For execution_uncertain, reconcile the underlying domain state and event history before any manual action. Do not automatically retry.
5. Preserve approval/audit records, record the incident decision in the relevant restricted change/incident record, and restore permissions or reauthorize only after review.

Do not paste decrypted approval payloads, customer/student/employee data, tokens, secrets, or raw exception details into issues or chat. The audit table has no approved automatic retention/pruning policy; preserve its records until a policy owner/legal reviewer approves a retention schedule.

## 10. Validation and troubleshooting

Run the focused protocol suite when changing transport or OAuth behavior:

~~~sh
php artisan test tests/Feature/McpTransportTest.php tests/Feature/McpOAuthTest.php
~~~

Before a PR, follow the current .github/workflows/tests.yml and [AI development rules](../.github/AI_DEVELOPMENT_RULES.md). Configured checks include Composer validation and documented-script verification, PHP/SQLite migrations and tests, Pint, PHPStan/Larastan, Composer audit, frontend build and npm audit, plus MySQL 8.0 migration/integrity regression tests. Inspect every required GitHub Actions job; local success is not a substitute for CI.

| Symptom | Checks |
| --- | --- |
| OAuth discovery fails | Check public APP_URL, MCP_AUTHORIZATION_SERVER, HTTPS/reverse-proxy forwarding, and both /.well-known metadata endpoints. |
| Redirect URI rejected | Compare the exact callback origin with MCP_OAUTH_REDIRECT_DOMAINS; do not use a wildcard. |
| OAuth/MCP endpoint returns HTTP 500 mentioning Passport key permissions | Check `storage/oauth-private.key` and `storage/oauth-public.key` modes; set both to `0600`, then clear and rebuild Laravel config cache. Do not regenerate keys as a permissions fix. |
| Production error response contains a stack trace | Set `APP_DEBUG=false` in the production environment and rebuild Laravel config cache. |
| Token rejected | Confirm the token is current, contains mcp:use and the tool-specific scope, and has the /mcp resource audience. |
| Tool absent after a server update | Refresh/review the ChatGPT app's discovered actions; workspace definitions may be a frozen snapshot. |
| Tool call denied | Check the dedicated OAuth scope, configured AI principal, Assistant role, required domain permissions, record scope, and current token state. Do not solve denial by granting Owner/superadmin. |
| Approval cannot be reviewed | Confirm the human reviewer has mcp_approval.approve and is distinct from the AI principal and known initiator. |
| Session/handshake errors | Check MCP_SESSION_PATH ownership/permissions, persistence, TTL, and whether requests reach different hosts without shared session storage/locking. |
| HTTP 413 | Check MCP_MAX_BODY_BYTES and any upstream request-body limit. |
| Request times out | Compare MCP_REQUEST_TIMEOUT_SECONDS with PHP-FPM, web server, CDN, and platform limits; the application cap cannot raise a lower upstream timeout. |
| Approval remains executing | Check the scheduled expiry command and MCP_EXECUTION_STALE_MINUTES; reconcile the domain event before manual recovery. |

## 11. Related documentation and tracking

- [MCP server and transport implementation](mcp-server.md)
- [Permission matrix](mcp-permissions.md)
- [Approval and execution audit controls](mcp-approvals-and-audit.md)
- [Production operations and recovery](operations-and-recovery.md)
- [MCP integration architecture and release plan](mcp-integration-plan.md)
- [MCP roadmap issue #528](https://github.com/deputy-proxy/os-edventure-ro/issues/528)
- [Documentation issue #540](https://github.com/deputy-proxy/os-edventure-ro/issues/540)

The shared engineering workflow is [osingle.md](../.chatgpt/osingle.md); repository-specific commands and runtime facts are maintained in [repository.md](../.chatgpt/repository.md).
