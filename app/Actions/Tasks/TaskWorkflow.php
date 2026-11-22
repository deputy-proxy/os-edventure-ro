<?php

namespace App\Actions\Tasks;

use App\Mcp\Authorization\McpAuthorizationService;
use App\Mcp\Authorization\McpToolPermissionRegistry;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TaskWorkflow
{
    public function __construct(
        private readonly McpAuthorizationService $authorization,
        private readonly McpToolPermissionRegistry $permissions,
    ) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateCreate(array $data): array
    {
        $principal = $this->principal();
        $this->authorization->authorizePrincipalForTool($principal, $this->permissions->forTool('create_task'));

        $values = Validator::make($data, [
            'action' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['required', 'string', 'min:1', 'max:10000'],
            'project_id' => ['required', 'integer', 'min:1', 'exists:projects,id'],
            'project_phase_id' => ['required', 'integer', 'min:1', 'exists:project_phases,id'],
            'assigned_to' => ['sometimes', 'integer', 'min:1', 'exists:users,id'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'estimated_effort_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ])->validate();

        $this->assertPhaseBelongsToProject((int) $values['project_phase_id'], (int) $values['project_id']);

        $assigneeId = (int) ($values['assigned_to'] ?? $principal->getKey());
        $this->assertAssignableUser($principal, $assigneeId);
        $values['assigned_to'] = $assigneeId;

        return $values;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Task
    {
        $values = $this->validateCreate($data);
        $principal = $this->principal();

        return DB::transaction(function () use ($values, $principal): Task {
            ProjectPhase::query()
                ->whereKey($values['project_phase_id'])
                ->where('project_id', $values['project_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $task = Task::query()->create([
                'action' => $values['action'],
                'description' => $values['description'],
                'project_id' => (int) $values['project_id'],
                'project_phase_id' => (int) $values['project_phase_id'],
                'assigned_to' => (int) $values['assigned_to'],
                'created_by' => (int) $principal->getKey(),
                'status' => 'pending',
                'due_date' => $values['due_date'],
                'estimated_effort_minutes' => $values['estimated_effort_minutes'] ?? null,
            ]);

            return $task->refresh();
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateUpdate(array $data): array
    {
        $principal = $this->principal();
        $this->authorization->authorizePrincipalForTool($principal, $this->permissions->forTool('update_task'));

        $values = Validator::make($data, [
            'task_id' => ['required', 'integer', 'min:1'],
            'expected_updated_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'action' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'string', 'min:1', 'max:10000'],
            'due_date' => ['sometimes', 'date_format:Y-m-d'],
            'estimated_effort_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', Rule::in(['pending', 'in_progress'])],
        ])->validate();

        if (count(Arr::only($values, ['action', 'description', 'due_date', 'estimated_effort_minutes', 'status'])) === 0) {
            throw ValidationException::withMessages([
                'fields' => 'At least one supported task field must be supplied for an update.',
            ]);
        }

        $visible = $this->visibleTask($principal, 'update_task', (int) $values['task_id']);
        $this->assertExpectedUpdatedAt($visible, (string) $values['expected_updated_at']);

        return $values;
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): Task
    {
        $values = $this->validateUpdate($data);
        $principal = $this->principal();

        return DB::transaction(function () use ($values, $principal): Task {
            $task = $this->visibleTask($principal, 'update_task', (int) $values['task_id'], true);
            $this->assertExpectedUpdatedAt($task, (string) $values['expected_updated_at']);

            $task->fill(Arr::only($values, ['action', 'description', 'due_date', 'estimated_effort_minutes', 'status']));
            $task->save();

            return $task->refresh();
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateAssign(array $data): array
    {
        $principal = $this->principal();
        $this->authorization->authorizePrincipalForTool($principal, $this->permissions->forTool('assign_task'));

        $values = Validator::make($data, [
            'task_id' => ['required', 'integer', 'min:1'],
            'assigned_to' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'expected_updated_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
        ])->validate();

        $visible = $this->visibleTask($principal, 'assign_task', (int) $values['task_id']);
        $this->assertExpectedUpdatedAt($visible, (string) $values['expected_updated_at']);
        $this->assertAssignableUser($principal, (int) $values['assigned_to']);
        if ($visible->status === 'done') {
            throw ValidationException::withMessages(['status' => 'A completed task cannot be reassigned.']);
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    public function assign(array $data): Task
    {
        $values = $this->validateAssign($data);
        $principal = $this->principal();

        return DB::transaction(function () use ($values, $principal): Task {
            $locked = $this->visibleTask($principal, 'assign_task', (int) $values['task_id'], true);
            $this->assertExpectedUpdatedAt($locked, (string) $values['expected_updated_at']);
            $this->assertAssignableUser($principal, (int) $values['assigned_to']);
            if ($locked->status === 'done') {
                throw ValidationException::withMessages(['status' => 'A completed task cannot be reassigned.']);
            }

            $locked->forceFill(['assigned_to' => (int) $values['assigned_to']])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateComplete(array $data): array
    {
        $principal = $this->principal();
        $this->authorization->authorizePrincipalForTool($principal, $this->permissions->forTool('complete_task'));

        $values = Validator::make($data, [
            'task_id' => ['required', 'integer', 'min:1'],
            'expected_updated_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
        ])->validate();

        $task = $this->visibleTask($principal, 'complete_task', (int) $values['task_id']);
        $this->assertExpectedUpdatedAt($task, (string) $values['expected_updated_at']);
        if ($task->status === 'done') {
            throw ValidationException::withMessages([
                'status' => 'The task is already completed.',
            ]);
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    public function complete(array $data): Task
    {
        $values = $this->validateComplete($data);
        $principal = $this->principal();

        return DB::transaction(function () use ($values, $principal): Task {
            $task = $this->visibleTask($principal, 'complete_task', (int) $values['task_id'], true);
            $this->assertExpectedUpdatedAt($task, (string) $values['expected_updated_at']);

            if ($task->status === 'done') {
                throw ValidationException::withMessages([
                    'status' => 'The task is already completed.',
                ]);
            }

            $task->forceFill(['status' => 'done'])->save();

            return $task->refresh();
        });
    }

    public function visibleTask(User $principal, string $toolName, int $taskId, bool $lock = false): Task
    {
        $permission = $this->permissions->forTool($toolName);
        $query = $this->authorization->scopeQuery(
            $principal,
            $permission,
            Task::query()->whereKey($taskId),
        );

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var Task $task */
        $task = $query->firstOrFail();
        $this->authorization->authorizeRecord($principal, $permission, $task);

        return $task;
    }

    private function assertAssignableUser(User $principal, int $assigneeId): void
    {
        if ($assigneeId === (int) $principal->getKey()) {
            return;
        }

        if (! $this->authorization->hasDomainPermission($principal, 'task.assign')) {
            throw new AuthorizationException('The task.assign permission is required to assign tasks to another user.');
        }

        if ($this->authorization->hasDomainPermission($principal, 'task.view_all')) {
            return;
        }

        $eligible = HexaRole::query()
            ->join('hexa_role_user', 'hexa_role_user.role_id', '=', 'hexa_roles.id')
            ->where('hexa_role_user.user_id', $assigneeId)
            ->where('hexa_roles.guard', (string) config('mcp.domain_permission_guard', config('auth.defaults.guard', 'web')))
            ->whereIn('hexa_roles.name', ['Academician', 'Teacher', 'Marketer', 'Coordinator'])
            ->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected user is not eligible for assignment under the current task-assignment policy.',
            ]);
        }
    }

    private function assertPhaseBelongsToProject(int $phaseId, int $projectId): void
    {
        if (! ProjectPhase::query()->whereKey($phaseId)->where('project_id', $projectId)->exists()) {
            throw ValidationException::withMessages([
                'project_phase_id' => 'The selected project phase does not belong to the selected project.',
            ]);
        }
    }

    private function assertExpectedUpdatedAt(Task $task, string $expectedUpdatedAt): void
    {
        if ($task->updated_at === null
            || ! CarbonImmutable::parse($expectedUpdatedAt)->equalTo($task->updated_at)) {
            throw ValidationException::withMessages([
                'expected_updated_at' => 'The task changed after this operation was requested. Review the current task and request a new approval.',
            ]);
        }
    }

    private function principal(): User
    {
        $principal = auth()->user();

        if (! $principal instanceof User) {
            throw new AuthorizationException('An authenticated os-edventure-ro principal is required.');
        }

        return $principal;
    }
}
