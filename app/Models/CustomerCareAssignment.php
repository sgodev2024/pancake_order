<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CustomerCareAssignment extends Model
{
    public const SOURCE_ORDER = 'order';

    public const SOURCE_IMPORTED_OPPORTUNITY = 'imported_opportunity';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RECLAIMED = 'reclaimed';

    public const RECLAIM_AFTER_CALENDAR_DAYS = 3;

    protected $table = 'customer_care_assignments';

    protected $fillable = [
        'shop_id',
        'customer_care_id',
        'source_type',
        'source_id',
        'assignee_user_id',
        'assignee_pancake_user_id',
        'assigned_at',
        'reclaim_eligible_on',
        'status',
        'cared_at',
        'reclaimed_at',
        'reclaim_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'reclaim_eligible_on' => 'date',
        'cared_at' => 'datetime',
        'reclaimed_at' => 'datetime',
    ];

    public static function calculateScheduledCareDate(
        CarbonInterface $assignedAt,
        int $careCycleDays
    ): CarbonImmutable {
        if ($careCycleDays < 0) {
            throw new InvalidArgumentException('Số ngày chu kỳ chăm sóc phải lớn hơn hoặc bằng 0.');
        }

        return CarbonImmutable::instance($assignedAt)
            ->setTimezone(config('app.timezone'))
            ->startOfDay()
            ->addDays($careCycleDays);
    }

    public static function calculateReclaimEligibleOn(
        CarbonInterface $scheduledCareDate
    ): CarbonImmutable {
        return CarbonImmutable::instance($scheduledCareDate)
            ->setTimezone(config('app.timezone'))
            ->startOfDay()
            ->addDays(self::RECLAIM_AFTER_CALENDAR_DAYS);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function customerCare()
    {
        return $this->belongsTo(CustomerCare::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function sourceOrder()
    {
        return $this->belongsTo(Order::class, 'order_source_id');
    }

    // Eager loading also reads this key, so imported IDs never enter its Order query.
    public function getOrderSourceIdAttribute(): ?int
    {
        return $this->source_type === self::SOURCE_ORDER ? $this->source_id : null;
    }
}
