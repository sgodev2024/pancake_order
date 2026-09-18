<?php

namespace App\Services;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\ImportedOpportunity;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Authorizes CustomerCare mutations after the target record has been resolved.
 *
 * ShopAccessService is the maximum scope.  This service adds the separate
 * CustomerCare feature and staff record-ownership rules.
 */
class CustomerCareWriteAccessService
{
    public function __construct(private readonly ShopAccessService $shopAccessService) {}

    public function authorize(User $actor, CustomerCare $customerCare, string $operation): void
    {
        $this->authorizeFeature($actor);

        if (! $this->shopAccessService->canAccessShop($actor, (int) $customerCare->shop_id)) {
            throw new AuthorizationException('You do not have access to this CustomerCare shop.');
        }

        if ($actor->isAdmin() || $actor->isManagerCskh()) {
            return;
        }

        if (in_array($operation, ['accept', 'delete'], true)) {
            throw new AuthorizationException('This CustomerCare operation is not available to staff.');
        }

        if ($operation === 'confirm' || $operation === 'completion') {
            $this->authorizeCurrentAssignee($actor, $customerCare);

            return;
        }

        $this->authorizeStaffUpdateScope($actor, $customerCare);
    }

    public function authorizeFeature(User $actor): void
    {
        if (! $actor->isAdmin() && ! $actor->isManagerCskh() && ! $actor->isStaffCskh()) {
            throw new AuthorizationException('You do not have CustomerCare write access.');
        }
    }

    /**
     * A malformed legacy link must never let a non-admin mutation bridge shops.
     * Missing historical sources are left to the existing operation semantics;
     * a source that exists in a different shop is an explicit conflict.
     */
    public function ensureSourceConsistency(CustomerCare $customerCare): void
    {
        if ($customerCare->pancake_order_id !== null
            && trim((string) $customerCare->pancake_order_id) !== ''
            && Order::query()
                ->where('pancake_order_id', $customerCare->pancake_order_id)
                ->where('shop_id', '!=', $customerCare->shop_id)
                ->exists()) {
            throw new DomainException('CustomerCare source order belongs to a different shop.');
        }

        $assignments = CustomerCareAssignment::query()
            ->where('customer_care_id', $customerCare->getKey())
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            if ((int) $assignment->shop_id !== (int) $customerCare->shop_id) {
                throw new DomainException('CustomerCare assignment belongs to a different shop.');
            }

            $sourceShopId = match ($assignment->source_type) {
                CustomerCareAssignment::SOURCE_ORDER => Order::query()
                    ->whereKey($assignment->source_id)->value('shop_id'),
                CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY => ImportedOpportunity::query()
                    ->whereKey($assignment->source_id)->value('shop_id'),
                default => null,
            };

            if ($sourceShopId !== null && (int) $sourceShopId !== (int) $customerCare->shop_id) {
                throw new DomainException('CustomerCare assignment source belongs to a different shop.');
            }
        }
    }

    public function authorizeApprovalDecision(
        User $actor,
        CustomerCare $customerCare,
        bool $isAccept
    ): void {
        $this->authorize($actor, $customerCare, 'accept');

        // Frontend permission semantics grant administrators every permission.
        if ($actor->isAdmin()) {
            return;
        }

        $requiredPermission = $isAccept ? 'accept-schedule' : 'reject-cskh';
        $actor->loadMissing('role.permissions:id,slug');

        if (! $actor->role?->permissions->contains('slug', $requiredPermission)) {
            throw new AuthorizationException('You do not have permission to review this CustomerCare request.');
        }
    }

    private function authorizeCurrentAssignee(User $actor, CustomerCare $customerCare): void
    {
        $assignments = CustomerCareAssignment::query()
            ->where('customer_care_id', $customerCare->getKey())
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        if ($assignments->count() !== 1) {
            throw new DomainException('CustomerCare does not have exactly one active assignment.');
        }

        $assignment = $assignments->first();
        if ((int) $assignment->shop_id !== (int) $customerCare->shop_id) {
            throw new DomainException('CustomerCare assignment belongs to a different shop.');
        }

        if ((int) $assignment->assignee_user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('This CustomerCare task is not assigned to you.');
        }
    }

    private function authorizeStaffUpdateScope(User $actor, CustomerCare $customerCare): void
    {
        $activeAssignments = CustomerCareAssignment::query()
            ->where('customer_care_id', $customerCare->getKey())
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        if ($activeAssignments->isNotEmpty()) {
            if ($activeAssignments->count() !== 1) {
                throw new DomainException('CustomerCare has ambiguous active assignments.');
            }

            $assignment = $activeAssignments->first();
            if ((int) $assignment->shop_id !== (int) $customerCare->shop_id) {
                throw new DomainException('CustomerCare assignment belongs to a different shop.');
            }

            if ((int) $assignment->assignee_user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('This CustomerCare task is not assigned to you.');
            }

            return;
        }

        $pancakeUserId = (string) $actor->pancake_user_id;
        $isLegacyOwner = $customerCare->user_creator_id === $pancakeUserId
            || $customerCare->user_care_id === $pancakeUserId
            || $customerCare->user_assigning_seller_id === $pancakeUserId
            || $customerCare->users()->whereKey($actor->getKey())->exists();

        if (! $isLegacyOwner) {
            throw new AuthorizationException('This CustomerCare task is not assigned to you.');
        }
    }
}
