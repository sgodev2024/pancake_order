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
                "name" => "Admin",
                "slug" => "admin"
            ],
            [
                "name" => "Quản lý",
                "slug" => "manager"
            ],
            [
                "name" => "Nhân viên",
                "slug" => "employee"
            ],
            [
                "name" => "Quản lý CSKH",
                "slug" => "manager-cskh"
            ],
            [
                "name" => "Nhân viên CSKH",
                "slug" => "staff-cskh"
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
            ],
            [
                "name" => "Khách hàng",
                "permissions" => [
                    [
                        "name" => "Xem danh sách",
                        "slug" => "list-customer"
                    ]
                ]
            ],
            [
                "name" => "Nhân viên",
                "permissions" => [
                    [
                        "name" => "Xem danh sách",
                        "slug" => "list-staff"
                    ],
                    [
                        "name" => "Thêm",
                        "slug" => "create-staff"
                    ],
                    [
                        "name" => "Sửa",
                        "slug" => "update-staff"
                    ],
                    [
                        "name" => "Xóa",
                        "slug" => "delete-staff"
                    ]
                ]
            ],
            [
                "name" => "Cơ hội",
                "permissions" => [
                    [
                        "name" => "Xem cơ hội",
                        "slug" => "view-chance",
                        "roles" => ["admin", "manager-cskh", "staff-cskh"]
                    ],
                    [
                        "name" => "Phân công CSKH",
                        "slug" => "asign-cskh",
                        "roles" => ["admin", "manager-cskh", "staff-cskh"]
                    ]
                ]
            ]
        ];
        foreach ($roles as $role) {
            Role::updateOrCreate(["slug" => $role["slug"]], ["name" => $role["name"]]);
        }

        $adminRole = Role::where("slug", "admin")->firstOrFail();
        User::where("email", "admin@gmail.com")->update(["role_id" => $adminRole->id]);

        foreach ($pms_groups as $pms_group_item) {
            $pms_group = PermissionGroup::firstOrCreate([
                "name" => $pms_group_item["name"]
            ]);
            foreach ($pms_group_item["permissions"] as $pms_item) {
                $pms = Permission::updateOrCreate(
                    ["slug" => $pms_item["slug"]],
                    [
                        "permission_group_id" => $pms_group->id,
                        "name" => $pms_item["name"]
                    ]
                );

                $roleSlugs = $pms_item["roles"] ?? ["admin"];
                $roleIds = Role::whereIn("slug", $roleSlugs)->pluck("id");

                foreach ($roleIds as $roleId) {
                    RolePermission::updateOrCreate([
                        "role_id" => $roleId,
                        "permission_id" => $pms->id
                    ]);
                }
            }
        }
    }
}
