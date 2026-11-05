<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve by stable slug so deployment does not depend on Local role IDs.
        // Staff Sale already has the legacy POS create role; no extra grant is needed.
        $policy = [
            'staff-sale' => ['list-product', 'list-customer'],
            'staff-cskh' => ['pos-create', 'list-product', 'list-customer'],
            'manager-cskh' => ['pos-create', 'list-product', 'list-customer'],
        ];

        DB::transaction(function () use ($policy): void {
            foreach ($policy as $roleSlug => $permissionSlugs) {
                $roles = DB::table('roles')->where('slug', $roleSlug)
                    ->whereNull('deleted_at')->lockForUpdate()->get();
                if ($roles->count() !== 1) {
                    throw new RuntimeException("Expected one active role: {$roleSlug}.");
                }
                foreach ($permissionSlugs as $permissionSlug) {
                    $permissions = DB::table('permissions')->where('slug', $permissionSlug)
                        ->whereNull('deleted_at')->get();
                    if ($permissions->count() !== 1) {
                        throw new RuntimeException("Expected one active permission: {$permissionSlug}.");
                    }
                    $grant = ['role_id' => $roles->sole()->id, 'permission_id' => $permissions->sole()->id];
                    if (! DB::table('role_permissions')->where($grant)->exists()) {
                        DB::table('role_permissions')->insert($grant + [
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // An automatic rollback cannot distinguish old grants from grants added here.
        throw new RuntimeException('Restore reviewed role grants from the pre-change backup; do not revoke existing access automatically.');
    }
};
