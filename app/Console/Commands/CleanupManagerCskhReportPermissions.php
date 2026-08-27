<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CleanupManagerCskhReportPermissions extends Command
{
    private const ROLE_SLUG = 'manager-cskh';

    /**
     * @var list<string>
     */
    private const REPORT_PERMISSION_SLUGS = [
        'revenue',
        'region-report',
        'loyalty-report',
        'customer-pendding-upgrade',
        'customer-care',
    ];

    protected $signature = 'auth:cleanup-manager-cskh-report-permissions
                            {--execute : Remove only the displayed manager-cskh role-permission links}';

    protected $description = 'Preview or remove legacy report permissions from the manager-cskh role';

    public function handle(): int
    {
        $role = Role::query()->where('slug', self::ROLE_SLUG)->first();

        if ($role === null) {
            $this->components->error('Role not found: '.self::ROLE_SLUG.'. No database rows were changed.');

            return self::FAILURE;
        }

        $permissions = Permission::query()
            ->whereIn('slug', self::REPORT_PERMISSION_SLUGS)
            ->get(['id', 'slug'])
            ->keyBy('slug');

        $rows = $this->buildRows($role->getKey(), $permissions);

        $this->components->info(
            $this->option('execute')
                ? 'EXECUTE MODE — only matching role_permissions links for manager-cskh will be removed.'
                : 'PREVIEW ONLY — no database rows will be modified.'
        );

        $this->table(
            ['Role ID', 'Permission Slug', 'Permission ID', 'Link Status', 'Action'],
            array_map(fn (array $row) => [
                $row['role_id'],
                $row['permission_slug'],
                $row['permission_id'] ?? 'NOT_FOUND',
                $row['link_status'],
                $row['action'],
            ], $rows)
        );

        $missingSlugs = array_values(array_filter(
            self::REPORT_PERMISSION_SLUGS,
            fn (string $slug) => ! $permissions->has($slug)
        ));

        if ($missingSlugs !== []) {
            $this->components->warn(
                'Permission records not found (nothing will be created): '.implode(', ', $missingSlugs)
            );
        }

        if (! $this->option('execute')) {
            $this->components->info('Would remove: '.$this->countLinkedRows($rows));

            return self::SUCCESS;
        }

        $removedRows = $this->removeLinks($role->getKey(), $permissions);
        $permissionSlugs = $permissions->keyBy('id');

        $this->newLine();
        $this->table(
            ['Pivot ID', 'Role ID', 'Permission Slug', 'Permission ID', 'Result'],
            $removedRows->map(fn (RolePermission $link) => [
                $link->getKey(),
                $role->getKey(),
                $permissionSlugs->get($link->permission_id)?->slug ?? 'UNKNOWN',
                $link->permission_id,
                'REMOVED',
            ])->all()
        );

        $remaining = $permissions->isEmpty()
            ? 0
            : RolePermission::query()
                ->where('role_id', $role->getKey())
                ->whereIn('permission_id', $permissions->pluck('id'))
                ->count();

        $this->components->info('Removed: '.$removedRows->count());
        $this->components->info('Remaining linked target rows: '.$remaining);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Permission>  $permissions
     * @return list<array{
     *     role_id: int,
     *     permission_slug: string,
     *     permission_id: int|null,
     *     link_status: string,
     *     action: string,
     *     pivot_ids: list<int>
     * }>
     */
    private function buildRows(int $roleId, Collection $permissions): array
    {
        $permissionIds = $permissions->pluck('id');
        $linksByPermission = $permissionIds->isEmpty()
            ? collect()
            : RolePermission::query()
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->get(['id', 'permission_id'])
                ->groupBy('permission_id');

        return array_map(function (string $slug) use ($roleId, $permissions, $linksByPermission): array {
            $permission = $permissions->get($slug);

            if ($permission === null) {
                return [
                    'role_id' => $roleId,
                    'permission_slug' => $slug,
                    'permission_id' => null,
                    'link_status' => 'NOT_FOUND',
                    'action' => '-',
                    'pivot_ids' => [],
                ];
            }

            $links = $linksByPermission->get($permission->getKey(), collect());
            $pivotIds = $links->pluck('id')->map(fn (mixed $id) => (int) $id)->values()->all();
            $linked = $pivotIds !== [];

            return [
                'role_id' => $roleId,
                'permission_slug' => $slug,
                'permission_id' => (int) $permission->getKey(),
                'link_status' => $linked ? 'LINKED' : 'NOT_LINKED',
                'action' => $linked ? 'WOULD_REMOVE' : '-',
                'pivot_ids' => $pivotIds,
            ];
        }, self::REPORT_PERMISSION_SLUGS);
    }

    /**
     * @param  list<array{pivot_ids: list<int>}>  $rows
     */
    private function countLinkedRows(array $rows): int
    {
        return array_sum(array_map(
            fn (array $row) => count($row['pivot_ids']),
            $rows
        ));
    }

    /**
     * @param  Collection<string, Permission>  $permissions
     * @return Collection<int, RolePermission>
     */
    private function removeLinks(int $roleId, Collection $permissions): Collection
    {
        if ($permissions->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($roleId, $permissions): Collection {
            $links = RolePermission::query()
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissions->pluck('id'))
                ->get(['id', 'permission_id']);

            if ($links->isNotEmpty()) {
                RolePermission::query()
                    ->where('role_id', $roleId)
                    ->whereIn('id', $links->pluck('id'))
                    ->delete();
            }

            return $links;
        });
    }
}
