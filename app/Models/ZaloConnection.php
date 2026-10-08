<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ZaloConnection extends Model {
 protected $fillable=['id','base_url','api_key','enabled'];
 protected $hidden=['api_key'];
 protected function casts(): array {return ['api_key'=>'encrypted','enabled'=>'boolean'];}
}

