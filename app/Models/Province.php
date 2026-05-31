<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $table = "provinces";

    protected $fillable = [
        "id",
        "name",
        "name_en",
        "new_id"
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
