<?php

namespace Tests\Unit;

use App\Models\CustomerCareAssignment;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCareAssignmentTest extends TestCase
{
    #[DataProvider('assignedTimesProvider')]
    public function test_reclaim_eligible_date_uses_vietnam_calendar_days(
        string $assignedAt,
        string $expectedEligibleOn
    ): void {
        $assignmentTime = CarbonImmutable::parse($assignedAt, 'Asia/Ho_Chi_Minh');

        $eligibleOn = CustomerCareAssignment::calculateReclaimEligibleOn($assignmentTime);

        $this->assertSame($expectedEligibleOn, $eligibleOn->toDateString());
        $this->assertSame('00:00:00', $eligibleOn->format('H:i:s'));
        $this->assertSame('Asia/Ho_Chi_Minh', $eligibleOn->timezoneName);
    }

    public static function assignedTimesProvider(): array
    {
        return [
            'start of day' => ['2026-08-21 00:01:00', '2026-08-24'],
            'middle of day' => ['2026-08-21 14:35:00', '2026-08-24'],
            'end of day' => ['2026-08-21 23:59:00', '2026-08-24'],
        ];
    }

    public function test_reclaim_eligible_date_converts_to_vietnam_before_adding_days(): void
    {
        $assignmentTime = CarbonImmutable::parse('2026-08-21 17:30:00', 'UTC');

        $eligibleOn = CustomerCareAssignment::calculateReclaimEligibleOn($assignmentTime);

        $this->assertSame('2026-08-25', $eligibleOn->toDateString());
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
