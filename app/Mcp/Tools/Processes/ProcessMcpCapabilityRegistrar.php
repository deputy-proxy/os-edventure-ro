<?php

namespace App\Mcp\Tools\Processes;

use App\Actions\Processes\ProcessWorkflow;
use App\Mcp\Authorization\AuthorizedMcpCapabilityRegistrar;
use App\Mcp\Authorization\Scopes\McpTaskRecordScope;
use App\Models\Employee;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mcp\Server\Builder as McpBuilder;

final class ProcessMcpCapabilityRegistrar extends AuthorizedMcpCapabilityRegistrar
{
    public function register(McpBuilder $builder): void
    {
        $tools = [
            'get_processes' => fn (User $user, array $arguments): array => $this->processes($user, $arguments),
            'get_process_details' => fn (User $user, array $arguments): array => $this->processDetails($user, $arguments),
            'get_process_steps' => fn (User $user, array $arguments): array => $this->processSteps($user, $arguments),
            'get_process_tasks' => fn (User $user, array $arguments): array => $this->processTasks($user, $arguments),
        ];
        $descriptions = [
            'get_processes' => 'Lists process definitions visible through the principal current HexaLite roles, including soft-deleted definitions.',
            'get_process_details' => 'Returns a visible process definition, owner and role visibility metadata. Does not execute the process.',
            'get_process_steps' => 'Returns stored ordered step and condition blocks as descriptive content; no transitions or execution are performed.',
            'get_process_tasks' => 'Lists existing associated tasks independently visible to the principal. Does not create or mutate tasks.',
        ];

        foreach ($tools as $name => $handler) {
            $this->addAuthorizedTool($builder, $name, $handler, $descriptions[$name], $this->schema($name));
        }

        $this->registerWriteTools($builder, app(ProcessWorkflow::class));
    }

    private function registerWriteTools(McpBuilder $builder, ProcessWorkflow $workflow): void
    {
        $this->addDirectWriteDefinition(
            $builder,
            'create_process',
            function (User $user, array $arguments) use ($workflow): array {
                return $this->withPrincipalContext($user, function () use ($arguments, $workflow): array {
                    $values = $workflow->validateCreate(Arr::except($arguments, ['idempotency_key']));
                    $owner = Employee::query()->findOrFail($values['owner_id']);

                    return [
                        'preview' => [
                            'operation' => 'Create process definition',
                            'title' => $values['title'],
                            'main_topic' => $values['main_topic'],
                            'owner_name' => (string) $owner->full_name,
                            'step_block_count' => count($values['steps']),
                            'visible_role_count' => count($values['visible_to']),
                        ],
                        'target_type' => 'process',
                    ];
                });
            },
            'Requests independent human approval to create a descriptive process definition. It does not start or execute a process.',
            $this->schema('create_process'),
        );

        $this->addDirectWriteDefinition(
            $builder,
            'update_process',
            function (User $user, array $arguments) use ($workflow): array {
                return $this->withPrincipalContext($user, function () use ($user, $arguments, $workflow): array {
                    $values = $workflow->validateUpdate(Arr::except($arguments, ['idempotency_key']));
                    $process = $workflow->visibleActiveProcess($user, 'update_process', (int) $values['process_id']);
                    $changes = array_keys(Arr::only($values, ['title', 'description', 'steps', 'owner_id', 'main_topic', 'visible_to']));

                    return [
                        'preview' => [
                            'operation' => 'Update process definition',
                            'process_id' => (string) $process->getKey(),
                            'current_title' => (string) $process->title,
                            'new_title' => $values['title'] ?? 'unchanged',
                            'changed_fields' => implode(', ', $changes),
                            'expected_updated_at' => $values['expected_updated_at'],
                        ],
                        'target_type' => 'process',
                        'target_id' => (string) $process->getKey(),
                    ];
                });
            },
            'Requests independent human approval to update process-definition fields. The process version must still match at execution.',
            $this->schema('update_process'),
        );

        $this->addDirectWriteDefinition(
            $builder,
            'delete_process',
            function (User $user, array $arguments) use ($workflow): array {
                return $this->withPrincipalContext($user, function () use ($user, $arguments, $workflow): array {
                    $values = $workflow->validateDelete(Arr::except($arguments, ['idempotency_key']));
                    $process = $workflow->visibleActiveProcess($user, 'delete_process', (int) $values['process_id']);

                    return [
                        'preview' => [
                            'operation' => 'Soft-delete process definition',
                            'process_id' => (string) $process->getKey(),
                            'title' => (string) $process->title,
                            'expected_updated_at' => $values['expected_updated_at'],
                        ],
                        'target_type' => 'process',
                        'target_id' => (string) $process->getKey(),
                    ];
                });
            },
            'Requests independent human approval to soft-delete an active process definition. It cannot force-delete or execute the process.',
            $this->schema('delete_process'),
        );

        $this->addDirectWriteExecutor(
            $builder,
            'execute_create_process',
            'create_process',
            fn (User $user, array $arguments): array => $this->withPrincipalContext(
                $user,
                fn (): array => $workflow->create(Arr::except($arguments, ['idempotency_key'])),
            ),
            'Creates a descriptive process definition in one call. It does not execute process steps.',
            $this->schema('execute_create_process'),
        );

        $this->addDirectWriteExecutor(
            $builder,
            'execute_update_process',
            'update_process',
            fn (User $user, array $arguments): array => $this->withPrincipalContext(
                $user,
                fn (): array => $workflow->update($user, Arr::except($arguments, ['idempotency_key'])),
            ),
            'Updates a descriptive process definition in one call after checking its current version.',
            $this->schema('execute_update_process'),
        );

        $this->addDirectWriteExecutor(
            $builder,
            'execute_delete_process',
            'delete_process',
            fn (User $user, array $arguments): array => $this->withPrincipalContext(
                $user,
                fn (): array => $workflow->delete($user, Arr::except($arguments, ['idempotency_key'])),
            ),
            'Soft-deletes a descriptive process definition in one call after checking its current version.',
            $this->schema('execute_delete_process'),
        );
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function processes(User $user, array $arguments): array
    {
        $values = Validator::make($arguments, [
            'query' => ['sometimes', 'string', 'min:2', 'max:100'],
            'main_topic' => ['sometimes', 'string', 'max:255'],
            'owner_id' => ['sometimes', 'integer', 'min:1'],
            'sort_by' => ['sometimes', Rule::in(['title', 'main_topic', 'created_at', 'updated_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ])->validate();

        /** @var Builder<Process> $query */
        $query = $this->scopeQuery($user, 'get_processes', Process::withTrashed()->with('owner:id,full_name'));
        if (isset($values['query'])) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $values['query']);
            $query->whereRaw("title LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }
        if (isset($values['main_topic'])) {
            $query->where('main_topic', $values['main_topic']);
        }
        if (isset($values['owner_id'])) {
            $query->where('owner_id', $values['owner_id']);
        }

        $query->select(['id', 'title', 'owner_id', 'main_topic', 'steps', 'created_at', 'updated_at', 'deleted_at'])
            ->orderBy($values['sort_by'] ?? 'title', $values['sort_direction'] ?? 'asc')
            ->orderBy('id');

        return [
            'availability' => 'available',
            ...$this->pageQuery($query, $values, 'processes', function (Process $process): array {
                $owner = $process->getRelation('owner');
                $steps = $process->getAttribute('steps');

                return [
                    'id' => (int) $process->getKey(),
                    'title' => (string) $process->title,
                    'main_topic' => (string) $process->main_topic,
                    'owner_name' => $owner instanceof Employee ? (string) $owner->full_name : null,
                    'step_block_count' => is_array($steps) ? count($steps) : 0,
                    'created_at' => $this->dateTimeValue($process->created_at),
                    'updated_at' => $this->dateTimeValue($process->updated_at),
                    'deleted_at' => $this->dateTimeValue($process->deleted_at),
                ];
            }),
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function processDetails(User $user, array $arguments): array
    {
        $values = Validator::make($arguments, [
            'process_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $process = $this->visibleProcess($user, 'get_process_details', (int) $values['process_id']);
        if (! $process instanceof Process) {
            return ['availability' => 'unavailable_process_not_found'];
        }

        $owner = $process->getRelation('owner');
        $visibleTo = $process->getAttribute('visible_to');
        $roleIds = is_array($visibleTo) ? array_map('intval', $visibleTo) : [];
        $roleNames = HexaRole::query()->where('guard', (string) config('mcp.domain_permission_guard', config('auth.defaults.guard', 'web')))->whereIn('id', $roleIds)->orderBy('name')->get(['name'])->map(static fn (HexaRole $role): array => ['name' => (string) $role->getAttribute('name')])->values()->all();

        return [
            'availability' => 'available',
            'process' => [
                'id' => (int) $process->getKey(),
                'title' => (string) $process->title,
                'description' => (string) $process->description,
                'main_topic' => (string) $process->main_topic,
                'owner_name' => $owner instanceof Employee ? (string) $owner->full_name : null,
                'visible_to_roles' => $roleNames,
                'created_at' => $this->dateTimeValue($process->created_at),
                'updated_at' => $this->dateTimeValue($process->updated_at),
                'deleted_at' => $this->dateTimeValue($process->deleted_at),
            ],
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function processSteps(User $user, array $arguments): array
    {
        $values = Validator::make($arguments, [
            'process_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $process = $this->visibleProcess($user, 'get_process_steps', (int) $values['process_id']);
        if (! $process instanceof Process) {
            return ['availability' => 'unavailable_process_not_found'];
        }

        $steps = $process->getAttribute('steps');

        return [
            'availability' => 'available',
            'process_id' => (int) $process->getKey(),
            'steps' => is_array($steps) ? $steps : [],
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function processTasks(User $user, array $arguments): array
    {
        $values = Validator::make($arguments, [
            'process_id' => ['required', 'integer', 'min:1'],
            'sort_by' => ['sometimes', Rule::in(['action', 'status', 'due_date', 'created_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ])->validate();

        $process = $this->visibleProcess($user, 'get_process_tasks', (int) $values['process_id']);
        if (! $process instanceof Process) {
            return ['availability' => 'unavailable_process_not_found'];
        }

        /** @var Builder<Task> $query */
        $query = app(McpTaskRecordScope::class)->apply($process->tasks()->getQuery(), $user)
            ->with(['assignedTo:id,name', 'project:id,title', 'projectPhase:id,title'])
            ->select(['id', 'action', 'status', 'due_date', 'estimated_effort_minutes', 'assigned_to', 'project_id', 'project_phase_id', 'created_at'])
            ->orderBy($values['sort_by'] ?? 'created_at', $values['sort_direction'] ?? 'desc')
            ->orderBy('id');

        return [
            'availability' => 'available',
            'process_id' => (int) $process->getKey(),
            ...$this->pageQuery($query, $values, 'tasks', function (Task $task): array {
                $assignee = $task->getRelation('assignedTo');
                $project = $task->getRelation('project');
                $phase = $task->getRelation('projectPhase');

                return [
                    'id' => (int) $task->getKey(),
                    'action' => (string) $task->action,
                    'status' => (string) $task->status,
                    'due_date' => $this->dateValue($task->due_date),
                    'estimated_effort_minutes' => $task->estimated_effort_minutes === null ? null : (int) $task->estimated_effort_minutes,
                    'assignee_name' => $assignee instanceof User ? (string) $assignee->name : null,
                    'project_title' => $project instanceof Project ? (string) $project->title : null,
                    'project_phase_title' => $phase instanceof ProjectPhase ? (string) $phase->title : null,
                ];
            }),
        ];
    }

    private function visibleProcess(User $user, string $toolName, int $processId): ?Process
    {
        /** @var Builder<Process> $query */
        $query = $this->scopeQuery($user, $toolName, Process::withTrashed()->with('owner:id,full_name'));
        $process = $query->whereKey($processId)->first();

        if ($process instanceof Process) {
            $this->authorizeRecord($user, $toolName, $process);
        }

        return $process;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $values
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function pageQuery(Builder $query, array $values, string $key, callable $map): array
    {
        $limit = (int) ($values['limit'] ?? 50);
        $offset = (int) ($values['offset'] ?? 0);
        $total = (clone $query)->count();
        $items = $query->limit($limit)->offset($offset)->get()->map($map)->values()->all();

        return [
            'total_count' => $total,
            'returned_count' => count($items),
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $total > $offset + $limit,
            $key => $items,
        ];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    private function dateTimeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }

    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $pagination = [
            'sort_by' => ['type' => 'string', 'enum' => ['title', 'main_topic', 'created_at', 'updated_at']],
            'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
        ];
        $step = [
            'type' => 'object',
            'properties' => (object) [
                'type' => ['type' => 'string', 'enum' => ['step', 'condition']],
                'data' => [
                    'type' => 'object',
                    'properties' => (object) [
                        'title' => ['type' => 'string', 'maxLength' => 255],
                        'actor' => $id,
                        'content' => ['type' => 'string', 'maxLength' => 10000],
                        'input' => ['type' => 'string', 'maxLength' => 255],
                        'output' => ['type' => 'string', 'maxLength' => 255],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['type', 'data'],
            'additionalProperties' => false,
        ];
        $fields = [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
            'steps' => ['type' => 'array', 'maxItems' => 100, 'items' => $step],
            'owner_id' => $id,
            'main_topic' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'visible_to' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $id],
            'expected_updated_at' => ['type' => 'string', 'format' => 'date-time'],
            'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$'],
        ];

        $properties = match ($name) {
            'get_processes' => [
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100],
                'main_topic' => ['type' => 'string', 'maxLength' => 255],
                'owner_id' => $id,
                ...$pagination,
            ],
            'get_process_details', 'get_process_steps' => ['process_id' => $id],
            'get_process_tasks' => [
                'process_id' => $id,
                'sort_by' => ['type' => 'string', 'enum' => ['action', 'status', 'due_date', 'created_at']],
                'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
            ],
            'create_process' => [
                'title' => $fields['title'],
                'description' => $fields['description'],
                'steps' => $fields['steps'],
                'owner_id' => $fields['owner_id'],
                'main_topic' => $fields['main_topic'],
                'visible_to' => $fields['visible_to'],
                'idempotency_key' => $fields['idempotency_key'],
            ],
            'update_process' => [
                'process_id' => $id,
                'expected_updated_at' => $fields['expected_updated_at'],
                'title' => $fields['title'],
                'description' => $fields['description'],
                'steps' => $fields['steps'],
                'owner_id' => $fields['owner_id'],
                'main_topic' => $fields['main_topic'],
                'visible_to' => $fields['visible_to'],
                'idempotency_key' => $fields['idempotency_key'],
            ],
            'delete_process' => [
                'process_id' => $id,
                'expected_updated_at' => $fields['expected_updated_at'],
                'idempotency_key' => $fields['idempotency_key'],
            ],
            'execute_create_process', 'execute_update_process', 'execute_delete_process' => [
                'approval_id' => $id,
            ],
            default => [],
        };

        $required = match ($name) {
            'get_process_details', 'get_process_steps', 'get_process_tasks' => ['process_id'],
            'create_process' => ['title', 'description', 'owner_id', 'main_topic', 'visible_to', 'idempotency_key'],
            'update_process' => ['process_id', 'expected_updated_at', 'idempotency_key'],
            'delete_process' => ['process_id', 'expected_updated_at', 'idempotency_key'],
            'execute_create_process', 'execute_update_process', 'execute_delete_process' => ['approval_id'],
            default => [],
        };

        return [
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
