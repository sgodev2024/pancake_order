<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $role = DB::table('roles')->where('slug', 'warehouse')->lockForUpdate()->first();
            if ($role && $role->deleted_at !== null) {
                throw new RuntimeException('Warehouse role was deleted; review before restoring it.');
            }
            $roleId = $role?->id ?? DB::table('roles')->insertGetId([
                'name' => 'Kho', 'slug' => 'warehouse', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['warehouse-view', 'warehouse-pack'] as $slug) {
                $permission = DB::table('permissions')->where('slug', $slug)->whereNull('deleted_at')->sole();
                $grant = ['role_id' => $roleId, 'permission_id' => $permission->id];
                if (! DB::table('role_permissions')->where($grant)->exists()) {
                    DB::table('role_permissions')->insert($grant + ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Review assigned warehouse accounts before removing this role.');
    }
};
