<?php

namespace App\Actions\Processes;

use App\Mcp\Authorization\McpAuthorizationService;
use App\Mcp\Authorization\McpToolPermissionRegistry;
use App\Models\Process;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProcessWorkflow
{
    public function __construct(
        private readonly McpAuthorizationService $authorization,
        private readonly McpToolPermissionRegistry $permissions,
    ) {}

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function validateCreate(array $arguments): array
    {
        $this->assertOnlyKeys($arguments, ['title', 'description', 'steps', 'owner_id', 'main_topic', 'visible_to']);

        $values = Validator::make($arguments, [
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['required', 'string', 'min:1', 'max:20000'],
            'steps' => ['sometimes', 'array', 'max:100'],
            'owner_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'main_topic' => ['required', 'string', 'min:1', 'max:255'],
            'visible_to' => ['required', 'array', 'min:1', 'max:100'],
            'visible_to.*' => ['required', 'integer', 'distinct', Rule::exists('hexa_roles', 'id')->where('guard', $this->domainGuard())],
        ])->validate();

        $values['steps'] = $this->validateSteps($values['steps'] ?? []);
        $values['visible_to'] = array_values(array_map('intval', $values['visible_to']));

        return $values;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function validateUpdate(array $arguments): array
    {
        $this->assertOnlyKeys($arguments, ['process_id', 'expected_updated_at', 'title', 'description', 'steps', 'owner_id', 'main_topic', 'visible_to']);

        $values = Validator::make($arguments, [
            'process_id' => ['required', 'integer', 'min:1'],
            'expected_updated_at' => ['required', 'date'],
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:1', 'max:20000'],
            'steps' => ['sometimes', 'array', 'max:100'],
            'owner_id' => ['sometimes', 'required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'main_topic' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'visible_to' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'visible_to.*' => ['required', 'integer', 'distinct', Rule::exists('hexa_roles', 'id')->where('guard', $this->domainGuard())],
        ])->validate();

        $changes = Arr::only($values, ['title', 'description', 'steps', 'owner_id', 'main_topic', 'visible_to']);
        if ($changes === []) {
            throw ValidationException::withMessages([
                'arguments' => 'At least one process field must be supplied for an update.',
            ]);
        }

        if (array_key_exists('steps', $changes)) {
            $changes['steps'] = $this->validateSteps($changes['steps']);
        }

        if (array_key_exists('visible_to', $changes)) {
            $changes['visible_to'] = array_values(array_map('intval', $changes['visible_to']));
        }

        return [...Arr::only($values, ['process_id', 'expected_updated_at']), ...$changes];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function validateDelete(array $arguments): array
    {
        $this->assertOnlyKeys($arguments, ['process_id', 'expected_updated_at']);

        return Validator::make($arguments, [
            'process_id' => ['required', 'integer', 'min:1'],
            'expected_updated_at' => ['required', 'date'],
        ])->validate();
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function create(array $values): array
    {
        $values = $this->validateCreate($values);
        $ownerId = (int) $values['owner_id'];

        $process = Process::query()->create([
            'title' => $values['title'],
            'description' => $values['description'],
            'steps' => $values['steps'],
            // The original non-nullable owner column is retained for legacy schema compatibility.
            'owner' => $ownerId,
            'owner_id' => $ownerId,
            'main_topic' => $values['main_topic'],
            'visible_to' => $values['visible_to'],
        ]);

        return $this->executionResult($process);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function update(User $principal, array $values): array
    {
        $values = $this->validateUpdate($values);
        $process = $this->visibleActiveProcess($principal, 'update_process', (int) $values['process_id'], true);
        $this->assertExpectedUpdatedAt($process, (string) $values['expected_updated_at']);

        $changes = Arr::only($values, ['title', 'description', 'steps', 'owner_id', 'main_topic', 'visible_to']);
        if (array_key_exists('owner_id', $changes)) {
            // Keep the legacy required column aligned with the canonical owner relationship.
            $changes['owner'] = (int) $changes['owner_id'];
        }

        $process->fill($changes);
        $process->save();

        return $this->executionResult($process->fresh());
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function delete(User $principal, array $values): array
    {
        $values = $this->validateDelete($values);
        $process = $this->visibleActiveProcess($principal, 'delete_process', (int) $values['process_id'], true);
        $this->assertExpectedUpdatedAt($process, (string) $values['expected_updated_at']);
        $process->delete();

        return [
            'process_id' => (int) $process->getKey(),
            'status' => 'deleted',
            'deleted_at' => $process->deleted_at?->toIso8601String(),
        ];
    }

    public function visibleActiveProcess(User $principal, string $toolName, int $processId, bool $lock = false): Process
    {
        /** @var Builder<Process> $query */
        $query = $this->authorization->scopeQuery(
            $principal,
            $this->permissions->forTool($toolName),
            Process::query(),
        )->whereKey($processId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $process = $query->first();
        if (! $process instanceof Process) {
            throw new AuthorizationException('The requested process definition is not accessible.');
        }

        $this->authorization->authorizeRecord($principal, $this->permissions->forTool($toolName), $process);

        return $process;
    }

    private function assertExpectedUpdatedAt(Process $process, string $expectedUpdatedAt): void
    {
        if ($process->updated_at === null
            || ! CarbonImmutable::parse($expectedUpdatedAt)->equalTo($process->updated_at)) {
            throw ValidationException::withMessages([
                'expected_updated_at' => 'The process changed after this operation was requested. Review the current definition and request a new approval.',
            ]);
        }
    }

    /** @param array<mixed> $steps
     * @return list<array<string, mixed>>
     */
    private function validateSteps(array $steps): array
    {
        if (! array_is_list($steps)) {
            throw ValidationException::withMessages([
                'steps' => 'Process steps must be an ordered list.',
            ]);
        }

        foreach ($steps as $index => $block) {
            if (! is_array($block) || array_diff(array_keys($block), ['type', 'data']) !== []
                || ! isset($block['type'], $block['data']) || ! is_array($block['data'])) {
                throw ValidationException::withMessages([
                    "steps.{$index}" => 'Each process block must contain only a type and data object.',
                ]);
            }

            if ($block['type'] === 'step') {
                if (array_diff(array_keys($block['data']), ['title', 'actor', 'content', 'input', 'output']) !== []) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.data" => 'A step block contains unsupported fields.',
                    ]);
                }

                Validator::make($block['data'], [
                    'title' => ['required', 'string', 'min:1', 'max:255'],
                    'actor' => ['required', 'integer', Rule::exists('hexa_roles', 'id')->where('guard', $this->domainGuard())],
                    'content' => ['required', 'string', 'min:1', 'max:10000'],
                    'input' => ['required', 'string', 'min:1', 'max:255'],
                    'output' => ['required', 'string', 'min:1', 'max:255'],
                ])->validate();
            } elseif ($block['type'] === 'condition') {
                if (array_diff(array_keys($block['data']), ['content']) !== []) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.data" => 'A condition block contains unsupported fields.',
                    ]);
                }

                Validator::make($block['data'], [
                    'content' => ['required', 'string', 'min:1', 'max:10000'],
                ])->validate();
            } else {
                throw ValidationException::withMessages([
                    "steps.{$index}.type" => 'Only descriptive step and condition blocks are supported.',
                ]);
            }
        }

        return $steps;
    }

    /** @return array<string, mixed> */
    private function executionResult(?Process $process): array
    {
        if (! $process instanceof Process) {
            throw new AuthorizationException('The process result is unavailable.');
        }

        return [
            'process_id' => (int) $process->getKey(),
            'title' => (string) $process->title,
            'status' => $process->trashed() ? 'deleted' : 'saved',
            'updated_at' => $process->updated_at?->toIso8601String(),
        ];
    }

    /** @param list<string> $allowedKeys */
    private function assertOnlyKeys(array $arguments, array $allowedKeys): void
    {
        $unknownKeys = array_diff(array_keys($arguments), $allowedKeys);
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unsupported process fields: '.implode(', ', $unknownKeys),
            ]);
        }
    }

    private function domainGuard(): string
    {
        return (string) config('mcp.domain_permission_guard', config('auth.defaults.guard', 'web'));
    }
}
