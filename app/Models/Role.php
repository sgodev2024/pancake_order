<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use SoftDeletes;
    
    protected $table = "roles";

    protected $fillable = [
        "name"
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, RolePermission::class);
    }
}
