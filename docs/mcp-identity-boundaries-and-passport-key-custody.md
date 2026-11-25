# MCP identity boundaries and Passport key custody

## Scope and owner decisions

Issue [#586](https://github.com/deputy-proxy/os-edventure-ro/issues/586) records development identity verification. It does not authorize production configuration, role, permission, key, or data changes.

On 2026-11-25, the owner explicitly decided that the existing development MCP Assistant principal **retains Filament `/admin` panel access**. The existing Assistant role permission grants remain unchanged; the supplied snapshot reports 309 permissions. The owner also directed that the current `APP_ENV` and `APP_DEBUG` values remain unchanged and that the current permission set not be narrowed while development continues. These decisions must be revisited before production-readiness or public-production sign-off.

## Panel admission and domain authorization

`App\Models\User::canAccessPanel()` admits the configured Owner role, or a non-owner account with at least one assigned role containing a non-empty permission. The Assistant's current permission-bearing role therefore admits it to Filament. Admission to `/admin` is not a grant to every resource: resource and page authorization remains independently enforced by their server-side permission checks. `PanelAccessAuthorizationTest` includes a regression case for the owner-approved Assistant panel-access decision.

`php artisan mcp:provision-principal` enforces a different, least-privilege target state. It rejects permissions outside the MCP permission matrix and refuses a principal with Filament panel access. It is not a valid verification or repair command for the current owner-approved development principal; do not run it against that identity while these decisions remain in force. Its constraints must be reconciled only through a separately authorized change.

## Runtime principal and token evidence

The owner-supplied snapshot recorded in issue #586 reported `APP_ENV=local`, `APP_DEBUG=true`, one global Assistant role with 309 permissions, and `canAccessPanel=true`. These are owner-reported runtime values, not a fresh inspection from the repository Sandbox.

Use the sanitized read-only Tinker command in [MCP setup and operations](mcp-setup-and-operations.md#5-provision-and-manage-the-ai-principal) to capture current environment flags, role metadata, permission counts/fingerprints, panel admission, and counts of valid access/refresh tokens. Preserve the output in the restricted operational record. Do not include token identifiers or values, user identifiers, individual permission lists, `.env` contents, or key material in public issues.

The automated tests cover the principal provisioning guard, the panel-access decision, OAuth authorization-code exchange, refresh-token rotation and replay rejection, and principal-wide access/refresh token revocation. The emergency revocation command is `php artisan mcp:revoke-principal-tokens`; it is a state-changing incident-response action, not a verification command. Do not run it during routine inspection.

Focused local regression suite:

```sh
php artisan test tests/Feature/ProvisionMcpPrincipalTest.php tests/Feature/McpOAuthTest.php tests/Feature/Filament/PanelAccessAuthorizationTest.php
```

## Passport key handling and custody

Repository controls currently provide the following evidence:

- `scripts/secure-passport-keys.php`, called by Composer's `post-autoload-dump` hook, applies mode `0600` to existing file-backed Passport private/public keys. It does not generate, rotate, read, or alter key material.
- `GET /ready` checks database connectivity and validates both Passport signing keys through League OAuth strict key validation. It returns only `ready` or `not_ready`; it does not report key contents, owner/group, or backup custody.
- Unit and feature tests cover restrictive file permissions and readiness failure behavior.
- The development Sandbox does not contain the live Hostinger Passport key files. Repository inspection therefore cannot establish live file ownership, PHP runtime readability, backup custody, or restoreability.

From the deployed application root, run the following without reading or copying key contents:

```sh
stat -c '%a %U:%G %n' storage/oauth-private.key storage/oauth-public.key
namei -l storage/oauth-private.key storage/oauth-public.key
curl -fsS -o /dev/null -w '%{http_code}\n' "$PUBLIC_APP_URL/ready"
```

For file-backed keys, both modes must be `600`, and the owner must be the PHP runtime identity that needs to read the files. Check parent-directory traversal permissions using `namei`. Do not broaden file modes to compensate for incorrect ownership. If keys are configured as inline PEM values, record only that fact and do not print the environment values.

Separately confirm in the restricted operational record that the private key is covered by the approved encrypted backup/custody process, identify the authorized custodian group there, and record when a restore procedure was last tested. Do not put vault names, access lists, backup locations, or other sensitive custody details in the repository or a public issue. A successful `/ready` response proves current key usability and database connectivity only; it does not prove backup custody or restoreability.

## Current completion boundary

No environment flags, role grants, Passport key files, tokens, production data, or production configuration were changed for this issue. Repository-side behavior and regression coverage can be validated in CI. The live Passport owner/group/mode and custody checks remain operational evidence that must be supplied by an authorized operator before #586 can be considered fully complete.
