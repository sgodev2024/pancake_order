<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                "id"   => 1,
                "name" => "Admin",
                "slug" => "admin"
            ],
            [
                "id"   => 2,
                "name" => "Quản lý",
                "slug" => "manager"
            ],
            [
                "id"   => 3,
                "name" => "Nhân viên",
                "slug" => "employee"
            ]
        ];
        $pms_groups = [
            [
                "name" => "Shop",
                "permissions" => [
                    [
                        "name" => "Thêm shop",
                        "slug" => "create-shop"
                    ],
                    [
                        "name" => "Xem danh sách shop",
                        "slug" => "list-shop"
                    ]
                ]
            ],
            [
                "name" => "Phân quyền",
                "permissions" => [
                    [
                        "name" => "Phân quyền",
                        "slug" => "role"
                    ]
                ]
            ]
        ];
        Role::insert($roles);
        User::where("email", "admin@gmail.com")->update(["role_id" => 1]);
        foreach ($pms_groups as $pms_group_item) {
            $pms_group = PermissionGroup::create([
                "name" => $pms_group_item["name"]
            ]);
            foreach ($pms_group_item["permissions"] as $pms_item) {
                $pms = Permission::create([
                    "permission_group_id" => $pms_group->id,
                    "name"                => $pms_item["name"],
                    "slug"                => $pms_item["slug"]
                ]);
                RolePermission::updateOrCreate([
                    "role_id"       => 1,
                    "permission_id" => $pms->id
                ]);
            }
        }
    }
}
