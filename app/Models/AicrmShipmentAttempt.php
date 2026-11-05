<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AicrmShipmentAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['request_payload'];

    protected $casts = ['request_payload' => 'array', 'completed_at' => 'datetime'];
}
