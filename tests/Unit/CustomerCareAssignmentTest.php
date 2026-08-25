<?php

namespace Tests\Unit;

use App\Models\CustomerCareAssignment;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCareAssignmentTest extends TestCase
{
    #[DataProvider('scheduledCareDatesProvider')]
    public function test_reclaim_eligible_date_uses_scheduled_vietnam_calendar_days(
        string $scheduledCareDate,
        string $expectedEligibleOn
    ): void {
        $scheduledOn = CarbonImmutable::parse($scheduledCareDate, 'Asia/Ho_Chi_Minh');

        $eligibleOn = CustomerCareAssignment::calculateReclaimEligibleOn($scheduledOn);

        $this->assertSame($expectedEligibleOn, $eligibleOn->toDateString());
        $this->assertSame('00:00:00', $eligibleOn->format('H:i:s'));
        $this->assertSame('Asia/Ho_Chi_Minh', $eligibleOn->timezoneName);
    }

    public static function scheduledCareDatesProvider(): array
    {
        return [
            'start of day' => ['2026-08-21 00:01:00', '2026-08-24'],
            'middle of day' => ['2026-08-21 14:35:00', '2026-08-24'],
            'end of day' => ['2026-08-21 23:59:00', '2026-08-24'],
        ];
    }

    public function test_reclaim_eligible_date_converts_to_vietnam_before_adding_days(): void
    {
        $scheduledOn = CarbonImmutable::parse('2026-08-21 17:30:00', 'UTC');

        $eligibleOn = CustomerCareAssignment::calculateReclaimEligibleOn($scheduledOn);

        $this->assertSame('2026-08-25', $eligibleOn->toDateString());
    }

    public function test_scheduled_care_date_uses_local_assignment_day_and_preserves_zero(): void
    {
        $assignedAt = CarbonImmutable::parse('2026-08-24 23:50:00', 'Asia/Ho_Chi_Minh');

        $this->assertSame(
            '2026-08-24',
            CustomerCareAssignment::calculateScheduledCareDate($assignedAt, 0)->toDateString()
        );
        $this->assertSame(
            '2026-08-27',
            CustomerCareAssignment::calculateScheduledCareDate($assignedAt, 3)->toDateString()
        );
        $this->assertSame(
            '2026-08-29',
            CustomerCareAssignment::calculateScheduledCareDate($assignedAt, 5)->toDateString()
        );
    }

    public function test_shop_care_cycle_normalization_preserves_zero_and_uses_existing_default_for_invalid_values(): void
    {
        $shop = new Shop;

        $shop->care_cycle_days = 0;
        $this->assertSame(0, $shop->normalizedCareCycleDays());

        $shop->care_cycle_days = '5';
        $this->assertSame(5, $shop->normalizedCareCycleDays());

        $shop->care_cycle_days = -1;
        $this->assertSame(Shop::DEFAULT_CARE_CYCLE_DAYS, $shop->normalizedCareCycleDays());

        $shop->care_cycle_days = 'invalid';
        $this->assertSame(Shop::DEFAULT_CARE_CYCLE_DAYS, $shop->normalizedCareCycleDays());
    }

    public function test_assignment_domain_defaults_and_casts_are_defined(): void
    {
        $assignment = new CustomerCareAssignment;

        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame('datetime', $assignment->getCasts()['assigned_at']);
        $this->assertSame('date', $assignment->getCasts()['reclaim_eligible_on']);
        $this->assertSame('datetime', $assignment->getCasts()['cared_at']);
        $this->assertSame('datetime', $assignment->getCasts()['reclaimed_at']);
        $this->assertSame('order', CustomerCareAssignment::SOURCE_ORDER);
        $this->assertSame(
            'imported_opportunity',
            CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY
        );
        $this->assertSame('reclaimed', CustomerCareAssignment::STATUS_RECLAIMED);
    }
}
