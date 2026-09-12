<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PancakeOrderSource extends Model
{
    protected $table = 'pancake_order_sources';

    protected $fillable = [
        'shop_id',
        'external_source_id',
        'name',
        'parent_external_source_id',
        'link',
        'source_inserted_at',
        'source_updated_at',
        'synced_at',
        'is_active',
        'last_seen_at',
    ];

    protected $casts = [
        'external_source_id' => 'string',
        'parent_external_source_id' => 'string',
        'source_inserted_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'synced_at' => 'datetime',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
