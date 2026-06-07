<?php

use App\Models\Customer;
use App\Models\LoyaltyTier;
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

function is_manager($user_id = NULL)
{
    if (!empty($user_id)) {
        $user = User::find($user_id);

        return $user->role_id == 2 ? true : false;
    } else {
        if (auth()->user()->role_id == 2) {
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

function get_loyalty_tier($purchased_amount)
{
    $loyaltyTier = LoyaltyTier::where("min_order_value", "<=", $purchased_amount)
                                ->where("max_order_value", ">=", $purchased_amount)
                                ->first();
    if (!$loyaltyTier) {
        $loyaltyTier = LoyaltyTier::where("min_order_value", "<=", $purchased_amount)
                                ->whereNull("max_order_value")
                                ->first();
    }
    return $loyaltyTier->id ?? NULL;
}

function get_discount_by_customer($pancake_customer_id)
{
    $customer = Customer::select("id", "loyalty_tier_id")
                        ->where("pancake_customer_id", $pancake_customer_id)
                        ->with("loyalty_tier")
                        ->first();

    return !empty($customer->loyalty_tier) ? $customer->loyalty_tier->discount_percent : 0;
}

function get_customer_name($data)
{
    if (!empty($data["bill_full_name"])) {
        return $data["bill_full_name"];
    }
    if (!empty($data_item["customer"]["name"])) {
        return $data_item["customer"]["name"];
    }
    return NULL;
}