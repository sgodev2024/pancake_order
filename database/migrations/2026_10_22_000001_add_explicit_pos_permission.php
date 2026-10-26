<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reuse the product catalog group. No schema changes or automatic grants.
        DB::transaction(function () {
            $group = DB::table('permissions')->where('slug', 'list-product')->whereNull('deleted_at')->value('permission_group_id');
            if ($group === null) {
                throw new RuntimeException('The existing list-product permission is required.');
            }
            if (! DB::table('permissions')->where('slug', 'pos-create')->exists()) {
                DB::table('permissions')->insert([
                    'permission_group_id' => $group, 'slug' => 'pos-create', 'name' => 'Tạo đơn POS',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain the permission catalog and explicit grants; remove grants through role administration.');
    }
};
