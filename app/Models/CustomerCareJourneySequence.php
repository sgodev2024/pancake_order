<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCareJourneySequence extends Model
{
    protected $table = 'customer_care_journey_sequences';

    protected $fillable = [
        'shop_id',
        'sequence_scope',
        'scope_key',
        'last_sequence',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'last_sequence' => 'integer',
    ];
}
