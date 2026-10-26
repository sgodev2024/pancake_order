<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    public const WAREHOUSE_WAITING = 'waiting_packing';

    public const WAREHOUSE_PACKED = 'packed';

    protected $table = 'orders';

    protected $fillable = [
        'shop_id',
        'customer_id',
        'created_by_user_id',
        'origin',
        'idempotency_key',
        'request_hash',
        'warehouse_status',
        'warehouse_queued_at',
        'source_created_at',
        'first_synced_at',
        'source_creator_name',
        'packed_by_user_id',
        'packed_at',
        'province_id',
        'pancake_order_id',
        'pancake_order_source_id',
        'pancake_order_source_name',
        'pancake_order_page_id',
        'pancake_order_page_name',
        'order_number_vtp',
        'total_quantity',
        'cod',
        'discount_percent',
        'cash',
        'note',
        'user_creator_id', // Thông tin người tạo đơn
        'user_care_id', // nhân vien chăm sóc
        'user_assigning_seller_id', // nhân viên được phân công
        'status',
        'pancake_customer_id',
        'status_vtp',
        'pancake_full_data',
        'received_at_shop',
        'customer_name',
        'customer_phone',
        'customer_address',
    ];

    protected $casts = [
        'pancake_full_data' => 'array',
        'warehouse_queued_at' => 'datetime',
        'source_created_at' => 'datetime',
        'first_synced_at' => 'datetime',
        'packed_at' => 'datetime',
    ];

    protected $hidden = ['request_hash', 'idempotency_key'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function packedBy()
    {
        return $this->belongsTo(User::class, 'packed_by_user_id')->select('id', 'name');
    }

    public function packingLog()
    {
        return $this->hasOne(ActivityLog::class, 'subject_id')->where('subject_type', 'order')->where('action', 'warehouse.packed');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function user_creator()
    {
        return $this->belongsTo(User::class, 'user_creator_id', 'pancake_user_id')
            ->select('id', 'name', 'pancake_user_id');
    }

    public function user_care()
    {
        return $this->belongsTo(User::class, 'user_care_id', 'pancake_user_id')
            ->select('id', 'name', 'pancake_user_id');
    }

    public function user_assigning()
    {
        return $this->belongsTo(User::class, 'user_assigning_seller_id', 'pancake_user_id')
            ->select('id', 'name', 'pancake_user_id');
    }

    public function customer_cares()
    {
        return $this->hasMany(CustomerCare::class, 'pancake_order_id', 'pancake_order_id');
    }

    public function customerCareAssignments()
    {
        return $this->hasMany(CustomerCareAssignment::class, 'source_id')
            ->where('source_type', CustomerCareAssignment::SOURCE_ORDER);
    }
}
