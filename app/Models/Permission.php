<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends Model
{
    use SoftDeletes;
    
    protected $table = "permissions";

    protected $fillable = [
        "name",
        "slug",
        "permission_group_id"
    ];

    public function permissionGroup()
    {
        return $this->belongsTo(PermissionGroup::class);
    }
}
