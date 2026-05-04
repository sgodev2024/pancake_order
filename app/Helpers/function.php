<?php

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;

function is_admin($user_id = NULL)
{
    if (!empty($user_id)) {
        $user = User::find($user_id);

        return $user->role_id == 1 ? true : false;
    } else {
        if (auth()->user()->role_id == 1) {
            return true;
        }
        return false;
    }
}

function can_access($pms_param)
{
    $pms = Permission::where("slug", $pms_param)->first();
    if (!$pms) { return false; }
    $role_id = auth()->user()->role_id;
    $role_pms = RolePermission::where("permission_id", $pms->id)
                            ->where("role_id", $role_id)
                            ->first();
    if (!$role_pms) { return false; }

    return true;
}