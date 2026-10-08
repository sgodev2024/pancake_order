<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DirectorRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create or update Director role
        $role = Role::updateOrCreate(
            ['slug' => 'director'],
            ['name' => 'Giám đốc']
        );

        // 2. Assign read-only reporting permissions
        $allowedSlugs = [
            'dashboard-overview',
            'revenue',
            'customer-care',
            'region-report',
            'loyalty-report',
            'customer-pendding-upgrade',
            'list-customer',
            'list-product',
            'list-shop',
            'shop-view-revenue',
            'view-total-staff',
            'view-care-cycle-days',
            'list-staff',
        ];

        $permissions = Permission::whereIn('slug', $allowedSlugs)->get();

        // Clear existing permissions for this role and re-assign
        RolePermission::where('role_id', $role->id)->delete();

        foreach ($permissions as $permission) {
            RolePermission::firstOrCreate([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        // 3. Create or update test Director user
        $user = User::updateOrCreate(
            ['email' => 'giamdoc@banmai.vn'],
            [
                'name' => 'Giám đốc Ban Mai',
                'password' => Hash::make('12345678'),
                'role_id' => $role->id,
                'is_first_login' => 0,
            ]
        );
    }
}
