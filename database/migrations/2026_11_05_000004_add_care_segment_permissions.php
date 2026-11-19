<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $group = DB::table('permission_groups')->where('name', 'Lọc CSKH')->value('id')
                ?? DB::table('permission_groups')->insertGetId(['name' => 'Lọc CSKH', 'created_at' => now(), 'updated_at' => now()]);
            foreach (['care-segment-view' => 'Xem Lọc CSKH', 'care-segment-export' => 'Xuất danh sách Lọc CSKH'] as $slug => $name) {
                $id = DB::table('permissions')->where('slug', $slug)->whereNull('deleted_at')->value('id')
                    ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name,
                        'permission_group_id' => $group, 'created_at' => now(), 'updated_at' => now()]);
                $roles = $slug === 'care-segment-view' ? ['admin', 'manager', 'manager-sale', 'manager-cskh', 'staff-cskh'] : ['admin'];
                foreach (DB::table('roles')->whereIn('slug', $roles)->whereNull('deleted_at')->pluck('id') as $role) {
                    if (! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $id)->exists()) {
                        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Review assigned segment permissions before rollback.');
    }
};
