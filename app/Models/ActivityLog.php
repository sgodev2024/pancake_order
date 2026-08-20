<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = "activity_logs";

    /**
     * Activity logs are append-only and only retain their creation time.
     */
    public $timestamps = false;

    protected $fillable = [
        "actor_user_id",
        "actor_name",
        "target_user_id",
        "target_user_name",
        "source",
        "action",
        "shop_id",
        "shop_name",
        "subject_type",
        "subject_id",
        "pancake_order_id",
        "pancake_customer_id",
        "old_values",
        "new_values",
        "metadata"
    ];

    protected $casts = [
        "old_values" => "array",
        "new_values" => "array",
        "metadata" => "array",
        "created_at" => "datetime"
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, "actor_user_id");
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, "target_user_id");
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class)->withTrashed();
    }
}
