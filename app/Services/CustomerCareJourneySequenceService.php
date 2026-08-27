<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\CustomerCareJourneySequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerCareJourneySequenceService
{
    public const BASIS = 'journey_completed_events';

    /**
     * Allocate the next number for one business journey while the caller's
     * completion transaction is open. The sequence row is the serialization
     * point, so two different care rows in one scope cannot receive the same
     * number.
     *
     * @return array{number: int, scope: string, customer_id: int|null, order_id: int|null}
     */
    public function next(CustomerCare $customerCare, CustomerCareAssignment $assignment): array
    {
        $scope = $this->resolveScope($customerCare, $assignment);

        $this->ensureSequenceRow($scope['shop_id'], $scope['scope'], $scope['key']);

        $sequence = CustomerCareJourneySequence::query()
            ->where('shop_id', $scope['shop_id'])
            ->where('sequence_scope', $scope['scope'])
            ->where('scope_key', $scope['key'])
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->last_sequence = (int) $sequence->last_sequence + 1;
        $sequence->save();

        return [
            'number' => (int) $sequence->last_sequence,
            'scope' => $scope['scope'],
            'customer_id' => $scope['customer_id'],
            'order_id' => $scope['order_id'],
        ];
    }

    /**
     * The local Customer row is the preferred customer-level anchor for order
     * care. Imported opportunities have no local Customer, so their local
     * source row is intentionally the complete journey identity.
     *
     * @return array{shop_id: int, scope: string, key: string, customer_id: int|null, order_id: int|null}
     */
    private function resolveScope(
        CustomerCare $customerCare,
        CustomerCareAssignment $assignment
    ): array {
        $shopId = (int) $assignment->shop_id;

        if ($assignment->source_type === CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY) {
            return [
                'shop_id' => $shopId,
                'scope' => 'imported_opportunity_source',
                'key' => 'source:'.$assignment->source_id,
                'customer_id' => null,
                'order_id' => null,
            ];
        }

        if ($assignment->source_type !== CustomerCareAssignment::SOURCE_ORDER) {
            return [
                'shop_id' => $shopId,
                'scope' => 'source_specific',
                'key' => 'type:'.$assignment->source_type.':source:'.$assignment->source_id,
                'customer_id' => null,
                'order_id' => null,
            ];
        }

        $customer = $this->findLocalCustomer($customerCare);
        if ($customer !== null) {
            return [
                'shop_id' => $shopId,
                'scope' => 'customer_shop',
                'key' => 'customer:'.$customer->getKey(),
                'customer_id' => (int) $customer->getKey(),
                'order_id' => $assignment->source_type === CustomerCareAssignment::SOURCE_ORDER
                    ? (int) $assignment->source_id
                    : null,
            ];
        }

        // A legacy/malformed care without a local Customer still has a safe
        // local source identity when it is backed by an order CCA. This keeps
        // the fallback scoped and never treats a Pancake ID as globally safe.
        return [
            'shop_id' => $shopId,
            'scope' => 'order_source',
            'key' => 'source:'.$assignment->source_id,
            'customer_id' => null,
            'order_id' => $assignment->source_type === CustomerCareAssignment::SOURCE_ORDER
                ? (int) $assignment->source_id
                : null,
        ];
    }

    private function findLocalCustomer(CustomerCare $customerCare): ?Customer
    {
        $pancakeCustomerId = trim((string) $customerCare->pancake_customer_id);

        if ($pancakeCustomerId === '' || ! Schema::hasTable('customers')) {
            return null;
        }

        return Customer::query()
            ->where('shop_id', (int) $customerCare->shop_id)
            ->where('pancake_customer_id', $pancakeCustomerId)
            ->lockForUpdate()
            ->first();
    }

    private function ensureSequenceRow(int $shopId, string $scope, string $key): void
    {
        $attributes = [
            'shop_id' => $shopId,
            'sequence_scope' => $scope,
            'scope_key' => $key,
        ];

        DB::table('customer_care_journey_sequences')->insertOrIgnore([
            ...$attributes,
            'last_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
