<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends Model
{
    use SoftDeletes;

    public const DEFAULT_CARE_CYCLE_DAYS = 5;

    protected $table = 'shops';

    protected $fillable = [
        'avatar_url',
        'pancake_shop_id',
        'name',
        'api_key',
        'pancake_full_data',
        'care_cycle_days',
    ];

    protected $casts = [
        'pancake_full_data' => 'array',
    ];

    public function normalizedCareCycleDays(): int
    {
        $careCycleDays = filter_var($this->care_cycle_days, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $careCycleDays === false
            ? self::DEFAULT_CARE_CYCLE_DAYS
            : $careCycleDays;
    }

    public function customers()
    {
        return $this->hasMany(Customer::class, 'shop_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'shop_id', 'id');
    }

    public function pancakeOrderSources()
    {
        return $this->hasMany(PancakeOrderSource::class, 'shop_id', 'id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, ShopUser::class)->withPivot('is_manager');
    }

    public function managers()
    {
        return $this->belongsToMany(User::class, ShopUser::class)
            ->wherePivot('is_manager', true);
    }
}
