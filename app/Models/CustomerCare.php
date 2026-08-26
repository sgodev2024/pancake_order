<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerCare extends Model
{
    protected $table = "customer_cares";

    protected $fillable = [
        "shop_id",
        "pancake_customer_id",
        "customer_phones",
        "customer_name",
        "customer_addresss",
        "pancake_order_id",
        "date_care",
        "note",
        "user_creator_id",
        "user_care_id",
        "user_assigning_seller_id",
        "status",
        "time_care",
        "is_accept",
        "user_accept_id",
        "total_edit",
        "reason",
        "is_confirm_care"
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, "pancake_order_id", "pancake_order_id");
    }

    /**
     * The assignment that currently owns this care task, if any.
     *
     * Keeping this relation on the API payload lets clients identify the
     * source-of-truth row without inferring it from legacy care records.
     */
    public function activeAssignment()
    {
        return $this->hasOne(CustomerCareAssignment::class)
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE);
    }

    /**
     * Every assignment candidate that can represent current, uncared ownership.
     *
     * This is intentionally a has-many relation. Corrupt data can contain more
     * than one matching row, and list serialization must fail closed instead
     * of allowing a has-one relation to choose an arbitrary employee.
     */
    public function currentAssignments()
    {
        return $this->hasMany(CustomerCareAssignment::class)
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->whereNull('cared_at');
    }

    /**
     * Keep imported/manual and non-opportunity order care semantics unchanged.
     * A status-3 order without an active assignment is managed by Opportunity;
     * whenever an order has an active assignment, only its referenced care is
     * actionable.
     */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('customer_cares.pancake_order_id')
                ->orWhereExists(function ($activeAssignmentQuery) {
                    $activeAssignmentQuery->selectRaw('1')
                        ->from('customer_care_assignments as actionable_cca')
                        ->join('orders as actionable_orders', function ($join) {
                            $join->on('actionable_orders.id', '=', 'actionable_cca.source_id')
                                ->whereNull('actionable_orders.deleted_at');
                        })
                        ->whereColumn(
                            'actionable_cca.customer_care_id',
                            'customer_cares.id'
                        )
                        ->whereColumn(
                            'actionable_orders.pancake_order_id',
                            'customer_cares.pancake_order_id'
                        )
                        ->where(
                            'actionable_cca.source_type',
                            CustomerCareAssignment::SOURCE_ORDER
                        )
                        ->where(
                            'actionable_cca.status',
                            CustomerCareAssignment::STATUS_ACTIVE
                        );
                })
                ->orWhere(function (Builder $unassignedOrderCareQuery) {
                    $unassignedOrderCareQuery
                        ->whereExists(function ($nonOpportunityOrderQuery) {
                            $nonOpportunityOrderQuery->selectRaw('1')
                                ->from('orders as non_opportunity_orders')
                                ->whereColumn(
                                    'non_opportunity_orders.pancake_order_id',
                                    'customer_cares.pancake_order_id'
                                )
                                ->whereNull('non_opportunity_orders.deleted_at')
                                ->where('non_opportunity_orders.status', '!=', 3);
                        })
                        ->whereNotExists(function ($opportunityOrderQuery) {
                            $opportunityOrderQuery->selectRaw('1')
                                ->from('orders as opportunity_orders')
                                ->whereColumn(
                                    'opportunity_orders.pancake_order_id',
                                    'customer_cares.pancake_order_id'
                                )
                                ->whereNull('opportunity_orders.deleted_at')
                                ->where('opportunity_orders.status', 3);
                        })
                        ->whereNotExists(function ($activeSourceQuery) {
                            $activeSourceQuery->selectRaw('1')
                                ->from('customer_care_assignments as source_cca')
                                ->join('orders as source_orders', function ($join) {
                                    $join->on('source_orders.id', '=', 'source_cca.source_id')
                                        ->whereNull('source_orders.deleted_at');
                                })
                                ->whereColumn(
                                    'source_orders.pancake_order_id',
                                    'customer_cares.pancake_order_id'
                                )
                                ->where(
                                    'source_cca.source_type',
                                    CustomerCareAssignment::SOURCE_ORDER
                                )
                                ->where(
                                    'source_cca.status',
                                    CustomerCareAssignment::STATUS_ACTIVE
                                );
                        });
                });
        });
    }

    public function user_creator()
    {
        return $this->belongsTo(User::class, "user_creator_id", "pancake_user_id")
                    ->select("id", "name", "pancake_user_id");
    }

    public function user_care()
    {
        return $this->belongsTo(User::class, "user_care_id", "pancake_user_id")
                    ->select("id", "name", "pancake_user_id");
    }

    public function user_assigning()
    {
        return $this->belongsTo(User::class, "user_assigning_seller_id", "pancake_user_id")
                    ->select("id", "name", "pancake_user_id");
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,                     // $related
            "customer_assigneds", //$table (bảng trung gian)
            "customer_care_id",    // $foreignPivotKey 
            "pancake_user_id",                      // $relatedPivotKey (khóa trỏ về User)
            "id",                           // $parentKey 
            "pancake_user_id"          
        )->withPivot("pancake_user_id");
    }

    /**Giống với function users, nhưng đặt tên chuẩn hơn */
    public function assigned()
    {
        return $this->belongsToMany(
            User::class,                     // $related
            "customer_assigneds", //$table (bảng trung gian)
            "customer_care_id",    // $foreignPivotKey (khóa trỏ về ProcessTemplateGroup)
            "pancake_user_id",                      // $relatedPivotKey (khóa trỏ về User)
            "id",                           // $parentKey (khóa chính ProcessTemplateGroup)
            "pancake_user_id"          
        )->withPivot("pancake_user_id");
    }
}
