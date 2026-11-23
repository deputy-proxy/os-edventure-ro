# Repository-specific development configuration

Last verified: 2026-11-22. Exact resolved versions are recorded in the cross-repository matrix at [Cr8or](https://github.com/deputy-proxy/cr8or/blob/main/.chatgpt/repository-versions.md). Re-check manifests, lockfiles, and workflows before each implementation.

## Runtime and resolved framework versions
- PHP constraint: `^8.4.1`; CI uses PHP 8.4.
- Laravel 13.43.0; Filament 5.11.4; Livewire 4.4.8.
- PHPUnit 13.4.7; Larastan 3.15.0; Pint 1.32.3.
- Node.js 22; npm lockfile resolves Vite 8.3.8, Tailwind CSS 4.3.5, Laravel Vite plugin 3.2.1.
- Composer manifest now requires Laravel `^13.0`; the previous Laravel 12 migration has been reflected in the current manifest and lockfile. Verify the actual default branch before assuming older issue descriptions remain current.

## Current CI validation
Source: `.github/workflows/tests.yml`.
- PHP job: Composer validation, documented-script verification, install, environment setup, frontend build for Vite-dependent feature tests, SQLite migrations, PHP syntax parsing, Pint, `composer test`, and `composer audit --locked`.
- Static analysis: `composer analyse` using PHPStan 2/Larastan 3 and the reviewed baseline.
- MySQL job: MySQL 8.0 migrations plus database-sensitive regression tests.
- Frontend job: Composer install for Filament theme assets, `npm ci`, `npm run build`, and `npm audit --audit-level=high`.

## Local validation
Follow the actual current workflow and Composer scripts. At minimum, validate Composer configuration, npm build, SQLite migrations, PHP syntax, Pint, tests, PHPStan, Composer audit, npm audit, and documented Composer scripts. Run MySQL 8.0 regression tests for database-sensitive changes.

## Security and migration notes
- Re-run `composer audit --locked` against the current lockfile. Earlier audit findings were against a previous Laravel 12 dependency tree and must not be presented as current findings without reproducing them.
- Verify both SQLite and MySQL-specific tests when changing database behavior.
- Review and reduce the PHPStan baseline when fixing findings; do not add suppressions without justification.


## MCP implementation state
- Streamable HTTP transport at /mcp, Passport authorization-code OAuth with mandatory PKCE S256, resource/audience binding, dynamic client registration, a dedicated AI principal, and server-side tool authorization are implemented. The registry contains 56 tools: 44 read-only tools across executive, finance, CRM, operations, HR/payroll, products, marketing and academic domains, plus 12 approval-request/execution tools for six task/CRM operations. Five permission-aware resources and five prompt templates are implemented. The runtime permission matrix is version 1.6.0 and defines dedicated OAuth scopes, required/optional HexaLite permissions, record scopes and nested output-field allowlists.
- Controlled writes are limited to create/update/assign/complete task, create customer interaction, and create customer lead. Requests create pending approvals; execution companions accept only approval IDs and execute immutable encrypted payloads through existing domain actions. Independent human review, execution-time authorization, idempotency, expiry and uncertain-execution recovery are required. No payroll, invoice, financial correction/reversal, lead-conversion, or user/role administration write is exposed.
- Issue #538 delivered five permission-aware resources and five prompt templates. Issue #539 delivered fourteen aggregate-only read tools for HR/payroll, products, marketing and academic operations. Production expansion remains gated on review of exact fields, audience and retention; the development authorization recorded in #539 is not unrestricted production disclosure approval.
- Issue #532 adds mcp_approvals and mcp_execution_audits, encrypted operation arguments/previews/results, payload-bound independent human review, single-use execution claims, idempotency, expiry, and stale-execution recovery. Human-only Filament resources are MCP Approvals (mcp_approval.index, mcp_approval.approve) and MCP Execution Audit (mcp_execution_audit.index); the AI principal cannot review its own operations.
- mcp:provision-principal creates or verifies the dedicated AI Assistant account with exactly one global Assistant HexaLite role and only permissions declared by the MCP matrix. mcp:revoke-principal-tokens revokes all access and refresh tokens for that principal. mcp:expire-approvals is scheduled every minute. No MCP-specific audit retention period has been approved; no MCP audit deletion is scheduled.
- The operator/developer entry point is docs/mcp-setup-and-operations.md. Supporting references: docs/mcp-server.md, docs/mcp-permissions.md, docs/mcp-approvals-and-audit.md, and the executive, finance, CRM, operations, and extended-domain tool contracts. A live ChatGPT workspace connection, tool discovery, and tool invocation have been demonstrated. The deployed reverse-proxy/streaming lifecycle remains unverified.
