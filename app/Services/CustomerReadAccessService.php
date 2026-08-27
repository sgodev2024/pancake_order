<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The shared read boundary for a local Customer record.
 *
 * Customer lists historically apply the same rule in more than one
 * controller. Keeping the direct-record check here prevents a new endpoint
 * from accidentally becoming broader than those existing reads.
 */
class CustomerReadAccessService
{
    public function __construct(
        private readonly ShopAccessService $shopAccessService
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $actor, Customer $customer): void
    {
        if (! $this->shopAccessService->canAccessShop($actor, (int) $customer->shop_id)) {
            throw new AuthorizationException('You do not have access to this customer shop.');
        }

        if ($actor->isAdmin()
            || $actor->isManagerSale()
            || $actor->isManagerCskh()) {
            return;
        }

        $assignedUserId = $actor->pancake_user_id ?? $actor->getKey();

        if ((string) $customer->assigned_user_id !== (string) $assignedUserId) {
            throw new AuthorizationException('You do not have access to this customer.');
        }
    }

    /**
     * Apply the same record restriction used by the existing customer list
     * endpoints. Shop scope is intentionally applied by the caller.
     */
    public function applyRecordScope($query, User $actor, string $qualifiedAssignedColumn = 'customers.assigned_user_id'): void
    {
        if ($actor->isAdmin()
            || $actor->isManagerSale()
            || $actor->isManagerCskh()) {
            return;
        }

        $assignedUserId = $actor->pancake_user_id ?? $actor->getKey();
        $query->where($qualifiedAssignedColumn, $assignedUserId);
    }
}
