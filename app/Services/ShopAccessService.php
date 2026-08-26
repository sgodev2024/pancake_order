<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class ShopAccessService
{
    /** @var array<string, Collection<int, int>> */
    private array $resolvedIds = [];

    /**
     * Admin is a global scope, not a snapshot of all current shop IDs.
     */
    public function isGlobal(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Return the user's explicit, current shop_users memberships.
     *
     * During the temporary compatibility phase, every non-admin role uses these
     * memberships exactly. The shop_users.is_manager flag is intentionally ignored.
     * For an admin these IDs are informational only; isGlobal() remains authoritative.
     *
     * @return Collection<int, int>
     */
    public function ids(User $user): Collection
    {
        $key = $user->exists ? 'user:'.$user->getKey() : 'object:'.spl_object_id($user);

        return $this->resolvedIds[$key] ??= $user->shops()
            ->pluck('shops.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function canAccessShop(User $user, int|Shop $shop): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        $shopId = $shop instanceof Shop ? (int) $shop->getKey() : $shop;

        return $this->ids($user)->containsStrict($shopId);
    }

    /**
     * Null means no explicit filter. An unauthorized explicit filter is tampering
     * and must not be silently broadened to the user's full scope.
     *
     * @throws AuthorizationException
     */
    public function authorizeRequestedShopId(User $user, ?int $shopId): ?int
    {
        if ($shopId === null) {
            return null;
        }

        if (! $this->canAccessShop($user, $shopId)) {
            throw new AuthorizationException('You do not have access to the requested shop.');
        }

        return $shopId;
    }
}
