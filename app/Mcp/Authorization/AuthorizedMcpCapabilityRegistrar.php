<?php

namespace App\Mcp\Authorization;

use App\Mcp\Contracts\McpCapabilityRegistrar;
use App\Models\Mcp\McpApproval;
use App\Models\User;
use App\Services\Mcp\McpApprovalService;
use App\Services\Mcp\McpExecutionAuditRecorder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mcp\Server\Builder;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Throwable;

abstract class AuthorizedMcpCapabilityRegistrar implements McpCapabilityRegistrar
{
    /** @var array<string, array{previewFactory: callable, description: string, inputSchema: array<string, mixed>}> */
    private array $pendingDirectWrites = [];
    public function __construct(
        protected readonly McpToolPermissionRegistry $permissions,
        protected readonly McpAuthorizationService $authorization,
        protected readonly McpFieldFilter $fieldFilter,
        protected readonly McpExecutionAuditRecorder $audit,
    ) {}

    /**
     * Register a read-only tool through the mandatory authorization, audit and output-filtering boundary.
     *
     * @param  callable(User, array<string, mixed>): array<string, mixed>  $handler
     * @param  array<string, mixed>  $inputSchema
     */
    protected function addAuthorizedTool(
        Builder $builder,
        string $toolName,
        callable $handler,
        string $description,
        array $inputSchema,
    ): void {
        $permission = $this->permissions->forTool($toolName);

        if ($permission->operationType !== 'read') {
            throw new LogicException('Write tools cannot use addAuthorizedTool(); use addDirectWriteTool() or the legacy approval boundary.');
        }

        $builder->addTool(
            handler: $this->wrapAuthorizedHandler($permission, $handler),
            name: $permission->toolName,
            description: $description,
            inputSchema: $inputSchema,
        );
    }

    /**
     * Define the validation boundary for a direct write. The paired executor registration
     * below completes the MCP SDK registration; only the final one-call tool is published.
     *
     * @param callable(User, array<string, mixed>): array<string, mixed> $validator
     * @param array<string, mixed> $inputSchema
     */
    protected function addDirectWriteDefinition(
        Builder $builder,
        string $toolName,
        callable $validator,
        string $description,
        array $inputSchema,
    ): void {
        $permission = $this->permissions->forTool($toolName);
        if ($permission->operationType !== 'write' || $permission->executionMode !== 'direct') {
            throw new LogicException('Direct-write definitions require execution_mode=direct.');
        }

        $this->pendingDirectWrites[$toolName] = [
            'previewFactory' => $validator,
            'description' => $description,
            'inputSchema' => $inputSchema,
        ];
    }

    /**
     * Complete a direct-write definition with its domain executor and publish one MCP tool.
     *
     * @param callable(User, array<string, mixed>): array<string, mixed> $executor
     * @param array<string, mixed> $inputSchema
     */
    protected function addDirectWriteExecutor(
        Builder $builder,
        string $toolName,
        string $directWriteToolName,
        callable $executor,
        string $description,
        array $inputSchema,
    ): void {
        $this->addApprovedExecutionTool($builder, $toolName, $directWriteToolName, $executor, $description, $inputSchema);
    }

    /**
     * Register the request half of a controlled write. This tool can create an approval
     * request only; it never invokes the domain executor.
     *
     * The preview factory returns ['preview' => [...], 'target_type' => ?string,
     * 'target_id' => ?string]. The caller must provide an opaque idempotency_key argument.
     *
     * @param  callable(User, array<string, mixed>): array<string, mixed>  $previewFactory
     * @param  array<string, mixed>  $inputSchema
     */
    protected function addApprovalRequestTool(
        Builder $builder,
        string $toolName,
        callable $previewFactory,
        string $description,
        array $inputSchema,
    ): void {
        $permission = $this->permissions->forTool($toolName);

        if ($permission->operationType === 'write' && $permission->executionMode === 'direct') {
            $this->pendingDirectWrites[$toolName] = [
                'previewFactory' => $previewFactory,
                'description' => $description,
                'inputSchema' => $inputSchema,
            ];

            return;
        }

        if ($permission->operationType !== 'write' || ! $permission->requiresApproval) {
            throw new LogicException('Approval request tools require an explicit write permission with requires_approval enabled.');
        }

        foreach (['approval_id', 'status', 'expires_at', 'preview_summary'] as $requiredOutputField) {
            if (! array_key_exists($requiredOutputField, $permission->allowedFields)) {
                throw new LogicException("Approval request tools must allowlist the [{$requiredOutputField}] response field.");
            }
        }

        $approvalService = app(McpApprovalService::class);

        $handler = static function (User $principal, array $arguments) use ($previewFactory, $approvalService, $toolName): array {
            $idempotencyKey = $arguments['idempotency_key'] ?? null;
            if (! is_string($idempotencyKey)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'An idempotency_key is required for controlled MCP writes.',
                ]);
            }

            $existing = $approvalService->existingIdempotentRequest(
                $principal,
                $toolName,
                $arguments,
                $idempotencyKey,
            );

            if ($existing !== null) {
                return [
                    'approval_id' => $existing->getKey(),
                    'status' => $existing->status,
                    'expires_at' => $existing->expires_at?->toIso8601String(),
                    'preview_summary' => $existing->preview_summary,
                ];
            }

            $details = $previewFactory($principal, $arguments);
            if (! array_key_exists('preview', $details) || ! is_array($details['preview'])) {
                throw ValidationException::withMessages([
                    'preview' => 'The write tool must provide a safe human-readable approval preview.',
                ]);
            }

            $approval = $approvalService->request(
                $principal,
                $toolName,
                $arguments,
                $details['preview'],
                $idempotencyKey,
                is_string($details['target_type'] ?? null) ? $details['target_type'] : null,
                is_string($details['target_id'] ?? null) || is_int($details['target_id'] ?? null)
                    ? (string) $details['target_id']
                    : null,
            );

            return [
                'approval_id' => $approval->getKey(),
                'status' => $approval->status,
                'expires_at' => $approval->expires_at?->toIso8601String(),
                'preview_summary' => $approval->preview_summary,
            ];
        };

        $builder->addTool(
            handler: $this->wrapAuthorizedHandler($permission, $handler),
            name: $permission->toolName,
            description: $description,
            inputSchema: $inputSchema,
        );
    }

    /**
     * Register the execution half of a controlled write. Its only input is an approval
     * ID; the executor receives the encrypted, validated arguments from the approval
     * record, never new arguments supplied by the MCP caller.
     *
     * @param  callable(User, array<string, mixed>): array<string, mixed>  $executor
     * @param  array<string, mixed>  $inputSchema
     */
    protected function addApprovedExecutionTool(
        Builder $builder,
        string $toolName,
        string $approvedOperationToolName,
        callable $executor,
        string $description,
        array $inputSchema,
    ): void {
        $approvedPermission = $this->permissions->forTool($approvedOperationToolName);

        if ($approvedPermission->operationType === 'write' && $approvedPermission->executionMode === 'direct') {
            $definition = $this->pendingDirectWrites[$approvedOperationToolName] ?? null;
            if (! is_array($definition)) {
                throw new LogicException("Direct write [{$approvedOperationToolName}] must define its validator before its executor.");
            }

            $properties = $definition['inputSchema']['properties'] ?? null;
            if (is_object($properties)) $properties = (array) $properties;
            $required = $definition['inputSchema']['required'] ?? null;
            if (! is_array($properties) || ! array_key_exists('idempotency_key', $properties)
                || ! is_array($required) || ! in_array('idempotency_key', $required, true)) {
                throw new LogicException('Direct write tools must require an idempotency_key argument.');
            }

            $this->addDirectWriteTool(
                $builder,
                $approvedPermission,
                $definition['previewFactory'],
                $executor,
                $description,
                $definition['inputSchema'],
            );
            unset($this->pendingDirectWrites[$approvedOperationToolName]);

            return;
        }

        $permission = $this->permissions->forTool($toolName);

        if ($permission->operationType !== 'write' || ! $permission->requiresApproval
            || $approvedPermission->operationType !== 'write' || ! $approvedPermission->requiresApproval) {
            throw new LogicException('Approved execution tools and their approved operations must both require human approval.');
        }

        $properties = $inputSchema['properties'] ?? null;
        if (is_object($properties)) {
            $properties = (array) $properties;
        }
        $required = $inputSchema['required'] ?? null;
        if (! is_array($properties)
            || array_keys($properties) !== ['approval_id']
            || ! is_array($required)
            || ! in_array('approval_id', $required, true)) {
            throw new LogicException('Approved execution tools may accept only a required approval_id argument.');
        }

        $approvalService = app(McpApprovalService::class);

        $handler = static function (User $principal, array $arguments) use (
            $approvalService,
            $approvedOperationToolName,
            $executor,
        ): array {
            if (count($arguments) !== 1 || ! array_key_exists('approval_id', $arguments)) {
                throw ValidationException::withMessages([
                    'arguments' => 'Approved execution accepts only the original approval_id.',
                ]);
            }

            $approvalId = filter_var($arguments['approval_id'], FILTER_VALIDATE_INT);
            if (! is_int($approvalId) || $approvalId < 1) {
                throw ValidationException::withMessages([
                    'approval_id' => 'A valid approval_id is required.',
                ]);
            }

            $approval = McpApproval::query()
                ->whereKey($approvalId)
                ->where('tool_name', $approvedOperationToolName)
                ->firstOrFail();

            return $approvalService->executeApproved($approval, $principal, $executor);
        };

        $builder->addTool(
            handler: $this->wrapAuthorizedHandler($permission, $handler),
            name: $permission->toolName,
            description: $description,
            inputSchema: $inputSchema,
        );
    }


    /**
     * Register one autonomous write call with current authorization, validation,
     * idempotency, transaction, audit and output-filtering controls.
     *
     * @param callable(User, array<string, mixed>): mixed $validator
     * @param callable(User, array<string, mixed>): array<string, mixed> $executor
     * @param array<string, mixed> $inputSchema
     */
    protected function addDirectWriteTool(
        Builder $builder,
        McpToolPermission $permission,
        callable $validator,
        callable $executor,
        string $description,
        array $inputSchema,
    ): void {
        if ($permission->operationType !== 'write' || $permission->executionMode !== 'direct' || $permission->requiresApproval) {
            throw new LogicException('Direct-write registration requires an explicitly autonomous write permission.');
        }

        $properties = $inputSchema['properties'] ?? null;
        if (is_object($properties)) $properties = (array) $properties;
        $required = $inputSchema['required'] ?? null;
        if (! is_array($properties) || ! array_key_exists('idempotency_key', $properties)
            || ! is_array($required) || ! in_array('idempotency_key', $required, true)) {
            throw new LogicException('Direct-write tools must require an idempotency_key argument.');
        }

        $service = app(\App\Services\Mcp\McpDirectWriteService::class);
        $authorization = $this->authorization;
        $handler = function (User $principal, array $arguments) use ($service, $permission, $validator, $executor, $authorization): array {
            if (! (bool) config('mcp.direct_writes_enabled', false)) {
                throw new AuthorizationException('Autonomous MCP writes are disabled for this environment.');
            }

            return $service->execute(
                $principal,
                $permission,
                $arguments,
                static fn () => $validator($principal, $arguments),
                static function () use ($principal, $arguments, $executor, $authorization, $permission): array {
                    $authorization->authorizeTool($principal, $permission);

                    return $executor($principal, $arguments);
                },
            );
        };

        $builder->addTool(
            handler: $this->wrapAuthorizedHandler($permission, $handler),
            name: $permission->toolName,
            description: $description,
            inputSchema: $inputSchema,
        );
    }

    /**
     * @param  callable(User, array<string, mixed>): array<string, mixed>  $handler
     */
    private function wrapAuthorizedHandler(McpToolPermission $permission, callable $handler): Closure
    {
        $authorization = $this->authorization;
        $fieldFilter = $this->fieldFilter;
        $audit = $this->audit;

        // The SDK's explicit-element loader passes its complete argument bag to closures
        // scoped to ReferenceHandler. This keeps internal session/request values out of
        // tool input while ensuring the audit and authorization checks wrap every call.
        return Closure::bind(
            static function (array $arguments) use ($permission, $authorization, $fieldFilter, $audit, $handler): array {
                $principal = Auth::guard('api')->user();
                $normalize = static function (mixed $value) use (&$normalize): mixed {
                    if ($value instanceof \stdClass) {
                        $value = get_object_vars($value);
                    }

                    if (is_array($value)) {
                        foreach ($value as $key => $item) {
                            $value[$key] = $normalize($item);
                        }
                    }

                    return $value;
                };
                $toolArguments = $normalize(Arr::except($arguments, ['_session', '_request']));
                $auditRecord = $audit->startToolInvocation(
                    $principal instanceof User ? $principal : null,
                    $permission->toolName,
                    $toolArguments,
                );

                try {
                    if (! $principal instanceof User) {
                        throw new AuthorizationException('An authenticated MCP service identity is required.');
                    }

                    $authorization->authorizeTool($principal, $permission);
                    $result = $handler($principal, $toolArguments);
                    $filtered = $fieldFilter->filter($result, $permission->allowedFields);
                    $audit->completeToolInvocation($auditRecord, $result);

                    return $filtered;
                } catch (Throwable $exception) {
                    $audit->failToolInvocation($auditRecord, $exception);
                    throw $exception;
                }
            },
            null,
            ReferenceHandler::class,
        );
    }

    protected function withPrincipalContext(User $principal, callable $callback): mixed
    {
        $previousGuard = Auth::getDefaultDriver();
        $domainGuard = (string) config('mcp.domain_permission_guard', config('auth.defaults.guard', 'web'));
        $guard = Auth::guard($domainGuard);
        $previousUser = $guard->user();
        $guard->setUser($principal);
        Auth::shouldUse($domainGuard);
        $gate = Gate::getFacadeRoot();
        Gate::swap($gate->forUser($principal));
        try {
            return $callback();
        } finally {
            Gate::swap($gate);
            if ($previousUser instanceof Authenticatable) {
                $guard->setUser($previousUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            } else {
                Auth::forgetGuards();
            }
            Auth::shouldUse($previousGuard);
        }
    }

    /** Apply the declared record scope before search, pagination, totals or aggregation. */
    protected function scopeQuery(User $principal, string $toolName, \Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $this->authorization->scopeQuery($principal, $this->permissions->forTool($toolName), $query);
    }

    /** Re-authorize a record loaded by identifier immediately before returning or acting on it. */
    protected function authorizeRecord(User $principal, string $toolName, Model $record): void
    {
        $this->authorization->authorizeRecord($principal, $this->permissions->forTool($toolName), $record);
    }
}
