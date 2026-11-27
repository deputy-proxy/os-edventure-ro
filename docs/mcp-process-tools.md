# MCP process tools (#575)

## Scope

The process capability exposes existing process definitions as descriptive management content. It does not execute process steps, evaluate conditions, advance a process state, create tasks automatically, or run a workflow engine.

The registry adds four read tools and three autonomous direct-write tools.

## Tools and permissions

| Tool | OAuth scope | Required domain permissions |
| --- | --- | --- |
| `get_processes` | `mcp.processes.list.read` | `process.index` |
| `get_process_details` | `mcp.processes.detail.read` | `process.index` |
| `get_process_steps` | `mcp.processes.steps.read` | `process.index` |
| `get_process_tasks` | `mcp.processes.tasks.read` | `process.index`, `task.index` |
| `create_process` | `mcp.processes.create` | `process.create` |
| `update_process` | `mcp.processes.update` | `process.update` |
| `delete_process` | `mcp.processes.delete` | `process.delete` |

The AI principal receives no permissions automatically. Operators must explicitly review and assign only the permissions appropriate to the deployment. `get_process_tasks` additionally applies the existing task scope; `task.view_all` expands that task scope only when granted.

## Process visibility and ownership

The Filament form stores `visible_to` as a JSON list of HexaLite role IDs. MCP applies a dedicated record scope before filtering, counting, pagination, and direct record lookup:

- A process is visible only when at least one of the principal's current role IDs for `MCP_DOMAIN_PERMISSION_GUARD` is present in `visible_to`.
- An empty or malformed visibility list is deny-by-default.
- Process ownership does not bypass `visible_to`.
- The check reads the current role membership on every tool invocation; it does not trust a caller-supplied role ID.

The canonical owner relationship is `owner_id`, which belongs to an Employee. The original non-nullable `owner` column remains in the legacy schema but is no longer the relationship used by the form/model. New process creation and owner changes keep the legacy column synchronized to `owner_id` so older schema requirements remain satisfied. MCP returns the employee's display name, not internal owner IDs.

The Filament resource explicitly includes soft-deleted definitions and provides a trashed filter. MCP list/detail/steps reads therefore include soft-deleted definitions and expose `deleted_at`. Update and delete requests target active definitions only. Delete uses Laravel soft deletion; MCP does not restore or force-delete definitions.

## Stored step format

The existing Filament Builder stores an ordered JSON list of blocks:

- `step`: `data.title`, `data.actor` (HexaLite role ID), `data.content`, `data.input`, and `data.output`.
- `condition`: `data.content`.

The `get_process_steps` tool returns these stored blocks without interpreting them. Writes validate the same two block types and reject unsupported keys or block types. These definitions remain documentation; no process execution semantics are introduced.

## Read behavior

- `get_processes` supports bounded pagination, title search, topic/owner filters, and allowlisted sorting. List output omits descriptions and step content.
- `get_process_details` returns the process description, topic, owner display name, visibility role names, and timestamps.
- `get_process_steps` returns the stored ordered blocks.
- `get_process_tasks` reads only tasks already attached through the process polymorphic relationship. It applies `McpTaskRecordScope` so the caller sees tasks assigned to or created by the principal, unless `task.view_all` is granted. Task descriptions and internal relationship IDs are excluded.

Restricted or nonexistent process IDs return the same unavailable response; counts and pagination metadata are calculated only after process visibility has been applied.

## Direct process writes

All three process writes execute in one MCP call. The caller supplies the complete business arguments and a required idempotency_key; no approval request or approval ID is involved.

Create validates the employee owner, visible role IDs, and stored step-block structure. Update accepts only title, description, steps, owner, main topic, and visibility. Update and delete require expected_updated_at; the workflow locks the record and rejects stale versions. Every write re-checks the principal's current role visibility. Delete is soft-delete only.

The direct-write ledger stores the canonical payload hash and encrypted result. Matching completed retries return the original result, changed payloads are rejected, and executing or uncertain keys cannot be replayed automatically. Audit events contain safe operation metadata, never raw process descriptions or step contents.

## Regression coverage

Run:

~~~sh
php artisan test tests/Feature/McpProcessToolsTest.php
~~~

The feature suite covers tool registration and closed schemas, role visibility, owner non-bypass, soft-deleted reads, stored step fidelity, task-level scoping, direct one-call execution, idempotent replay, visibility enforcement, and rejection of unsupported process block types.


## Representative calls

Read a bounded page of visible definitions:

~~~json
{
  "name": "get_processes",
  "arguments": {
    "query": "onboarding",
    "limit": 25,
    "offset": 0
  }
}
~~~

Read the stored blocks for an already-visible definition:

~~~json
{
  "name": "get_process_steps",
  "arguments": {
    "process_id": 123
  }
}
~~~

Create a process definition directly. The example uses illustrative IDs only:

~~~json
{
  "name": "create_process",
  "arguments": {
    "title": "New staff onboarding",
    "description": "Documented onboarding procedure.",
    "main_topic": "People operations",
    "owner_id": 42,
    "visible_to": [7],
    "steps": [
      {
        "type": "step",
        "data": {
          "title": "Review onboarding documents",
          "actor": 7,
          "content": "Review the submitted documents.",
          "input": "Onboarding documents",
          "output": "Review notes"
        }
      },
      {
        "type": "condition",
        "data": {
          "content": "Continue after the review is complete."
        }
      }
    ],
    "idempotency_key": "process-create-001"
  }
}
~~~

The direct call returns the created process ID and a safe result summary. Updates and deletes are also one-call operations; include the current updated_at value as expected_updated_at and a fresh idempotency_key:

~~~json
{
  "name": "update_process",
  "arguments": {
    "process_id": 456,
    "expected_updated_at": "2026-11-27T10:00:00+02:00",
    "title": "Updated onboarding procedure",
    "idempotency_key": "process-update-001"
  }
}
~~~

For updates and deletes, include the process's current updated_at value as expected_updated_at in the request. The direct update call accepts the business fields and the current expected_updated_at value.

## Related application code

- [Process model](../app/Models/Process.php)
- [Process Filament form](../app/Filament/Resources/Processes/Schemas/ProcessForm.php)
- [Process resource](../app/Filament/Resources/Processes/ProcessResource.php)
- [Task model](../app/Models/Task.php)
- [Task/project relationship notes](tasks-and-projects.md)
- [MCP approval and audit controls](mcp-approvals-and-audit.md)
- [MCP operations tools](mcp-operations-tools.md)

## Direct execution

Each process write is a single MCP call with the complete validated business arguments and a required `idempotency_key`. No approval request or execution companion is published. The direct-write ledger prevents duplicate creation, rejects payload collisions, and blocks automatic replay of uncertain outcomes. Process definitions remain descriptive only; these tools never execute process steps or create tasks.
