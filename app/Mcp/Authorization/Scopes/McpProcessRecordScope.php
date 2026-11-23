<?php

namespace App\Mcp\Authorization\Scopes;

use App\Models\Process;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Process visibility is granted to users holding at least one role selected in visible_to. */
final class McpProcessRecordScope implements McpRecordScope
{
    public function apply(Builder $query, User $principal): Builder
    {
        $roleIds = $this->roleIds($principal);

        if ($roleIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($roleIds): void {
            foreach ($roleIds as $roleId) {
                $visible->orWhereJsonContains('visible_to', $roleId)
                    ->orWhereJsonContains('visible_to', (string) $roleId);
            }
        });
    }

    public function allows(User $principal, Model $record): bool
    {
        if (! $record instanceof Process) {
            return false;
        }

        $visibleTo = $record->getAttribute('visible_to');
        if (! is_array($visibleTo) || $visibleTo === []) {
            return false;
        }

        $visibleRoleIds = array_map('strval', $visibleTo);

        return $this->roleIds($principal)
            ->contains(fn (int $roleId): bool => in_array((string) $roleId, $visibleRoleIds, true));
    }

    /** @return Collection<int, int> */
    private function roleIds(User $principal): Collection
    {
        return DB::table('hexa_role_user')
            ->join('hexa_roles', 'hexa_roles.id', '=', 'hexa_role_user.role_id')
            ->where('hexa_role_user.user_id', $principal->getKey())
            ->where('hexa_roles.guard', (string) config('mcp.domain_permission_guard', config('auth.defaults.guard', 'web')))
            ->pluck('hexa_roles.id')
            ->map(static fn ($id): int => (int) $id)
            ->values();
    }
}
