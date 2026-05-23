<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAssigned extends Model
{
    protected $table = "customer_assigneds";

    protected $fillable = [
        "customer_care_id",
        "pancake_customer_id",
        "pancake_user_id"
    ];
}
