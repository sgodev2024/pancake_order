<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportedOpportunity extends Model
{
    protected $table = "imported_opportunities";

    protected $fillable = [
        "shop_id",
        "name",
        "phone",
        "address",
        "status",
        "imported_by",
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function importer()
    {
        return $this->belongsTo(User::class, "imported_by");
    }
}
