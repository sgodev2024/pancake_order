<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Owns the deterministic, same-shop link between Customers and Journey logs.
 *
 * The instance query powers the timeline. The correlated query expresses the
 * same rules for customer-list EXISTS/count predicates without loading one
 * timeline per customer.
 */
class CustomerJourneyLinkageService
{
    public function forCustomer(Customer $customer): Builder
    {
        $customerId = (int) $customer->getKey();
        $shopId = (int) $customer->shop_id;
        $linkedOrderIds = $this->linkedOrderIds($customer);
        $shopOrderIds = $this->shopOrderIds($shopId);
        $shopCustomerCareIds = $this->shopCustomerCareIds($shopId);
        $shopAssignmentIds = $this->shopAssignmentIds($shopId);
        $linkedOrderAssignmentIds = $this->linkedOrderAssignmentIds($linkedOrderIds, $shopId);

        return ActivityLog::query()
            ->with([
                'actor' => fn ($query) => $query->select('users.id', 'users.name'),
                'targetUser' => fn ($query) => $query->select('users.id', 'users.name'),
                'shop' => fn ($query) => $query->select('shops.id', 'shops.name'),
            ])
            ->where('activity_logs.shop_id', $shopId)
            ->whereIn('activity_logs.action', CustomerJourneyService::ACTIONS)
            ->where(function (Builder $journey) use (
                $customerId,
                $shopOrderIds,
                $shopCustomerCareIds,
                $shopAssignmentIds,
                $linkedOrderIds,
                $linkedOrderAssignmentIds,
                $customer
            ): void {
                $journey->where(function (Builder $direct) use (
                    $customerId,
                    $shopOrderIds,
                    $shopCustomerCareIds,
                    $shopAssignmentIds
                ): void {
                    $this->whereMetadataCustomerId($direct, $customerId);
                    $direct->where(function (Builder $subject) use (
                        $customerId,
                        $shopOrderIds,
                        $shopCustomerCareIds,
                        $shopAssignmentIds
                    ): void {
                        $subject->where(function (Builder $entered) use ($customerId): void {
                            $entered->where('activity_logs.action', 'customer.entered_system')
                                ->where('activity_logs.subject_type', 'customer')
                                ->where('activity_logs.subject_id', (string) $customerId);
                        })->orWhere(function (Builder $order) use ($shopOrderIds): void {
                            $order->where('activity_logs.action', 'order.created')
                                ->where('activity_logs.subject_type', 'order')
                                ->whereIn('activity_logs.subject_id', $shopOrderIds);
                        })->orWhere(function (Builder $care) use ($shopCustomerCareIds): void {
                            $care->where('activity_logs.action', 'customer_care.completed')
                                ->where('activity_logs.subject_type', 'customer_care')
                                ->whereIn('activity_logs.subject_id', $shopCustomerCareIds);
                        })->orWhere(function (Builder $assignment) use ($shopAssignmentIds): void {
                            $assignment->whereIn('activity_logs.action', [
                                'customer_care.assigned',
                                'customer_care.reassigned',
                                'customer_care.reclaimed',
                            ])->where('activity_logs.subject_type', 'customer_care_assignment')
                                ->whereIn('activity_logs.subject_id', $shopAssignmentIds);
                        });
                    });
                })->orWhere(function (Builder $entered) use ($customerId): void {
                    $entered->where('activity_logs.action', 'customer.entered_system')
                        ->where('activity_logs.subject_type', 'customer')
                        ->where('activity_logs.subject_id', (string) $customerId);
                })->orWhere(function (Builder $orderFallback) use ($linkedOrderIds, $customer): void {
                    if ($this->hasExternalCustomerId($customer)) {
                        $orderFallback->where('activity_logs.action', 'order.created')
                            ->where('activity_logs.subject_type', 'order')
                            ->whereIn('activity_logs.subject_id', $linkedOrderIds)
                            ->where('activity_logs.pancake_customer_id', (string) $customer->pancake_customer_id)
                            ->where(function (Builder $withoutLocalId): void {
                                $withoutLocalId->whereNull('activity_logs.metadata->customer_id')
                                    ->orWhere('activity_logs.metadata->customer_id', '');
                            });
                    } else {
                        $orderFallback->whereRaw('1 = 0');
                    }
                })->orWhere(function (Builder $assignment) use ($linkedOrderAssignmentIds): void {
                    $assignment->whereIn('activity_logs.action', [
                        'customer_care.assigned',
                        'customer_care.reassigned',
                        'customer_care.reclaimed',
                    ])->where('activity_logs.subject_type', 'customer_care_assignment')
                        ->whereIn('activity_logs.subject_id', $linkedOrderAssignmentIds);
                })->orWhere(function (Builder $completed) use (
                    $shopCustomerCareIds,
                    $linkedOrderAssignmentIds
                ): void {
                    $completed->where('activity_logs.action', 'customer_care.completed')
                        ->where('activity_logs.subject_type', 'customer_care')
                        ->whereIn('activity_logs.subject_id', $shopCustomerCareIds)
                        ->whereIn('activity_logs.metadata->assignment_id', $linkedOrderAssignmentIds);
                });
            });
    }

    /**
     * Return a correlated ActivityLog query linked to the outer customer row.
     */
    public function forCustomerQuery(string $customerTable = 'customers'): Builder
    {
        $customerId = $customerTable.'.id';
        $customerShopId = $customerTable.'.shop_id';
        $customerExternalId = $customerTable.'.pancake_customer_id';

        return ActivityLog::query()
            ->whereColumn('activity_logs.shop_id', $customerShopId)
            ->whereIn('activity_logs.action', CustomerJourneyService::ACTIONS)
            ->where(function (Builder $journey) use (
                $customerId,
                $customerShopId,
                $customerExternalId
            ): void {
                $journey->where(function (Builder $direct) use (
                    $customerId,
                    $customerShopId
                ): void {
                    $direct->whereColumn('activity_logs.metadata->customer_id', $customerId)
                        ->where(function (Builder $subject) use ($customerId, $customerShopId): void {
                            $subject->where(function (Builder $entered) use ($customerId): void {
                                $entered->where('activity_logs.action', 'customer.entered_system')
                                    ->where('activity_logs.subject_type', 'customer')
                                    ->whereColumn('activity_logs.subject_id', $customerId);
                            })->orWhere(function (Builder $order) use ($customerShopId): void {
                                $order->where('activity_logs.action', 'order.created')
                                    ->where('activity_logs.subject_type', 'order')
                                    ->whereExists($this->subjectExistsInShop('orders', $customerShopId));
                            })->orWhere(function (Builder $care) use ($customerShopId): void {
                                $care->where('activity_logs.action', 'customer_care.completed')
                                    ->where('activity_logs.subject_type', 'customer_care')
                                    ->whereExists($this->subjectExistsInShop('customer_cares', $customerShopId));
                            })->orWhere(function (Builder $assignment) use ($customerShopId): void {
                                $assignment->whereIn('activity_logs.action', [
                                    'customer_care.assigned',
                                    'customer_care.reassigned',
                                    'customer_care.reclaimed',
                                ])->where('activity_logs.subject_type', 'customer_care_assignment')
                                    ->whereExists($this->subjectExistsInShop(
                                        'customer_care_assignments',
                                        $customerShopId
                                    ));
                            });
                        });
                })->orWhere(function (Builder $entered) use ($customerId): void {
                    $entered->where('activity_logs.action', 'customer.entered_system')
                        ->where('activity_logs.subject_type', 'customer')
                        ->whereColumn('activity_logs.subject_id', $customerId);
                })->orWhere(function (Builder $orderFallback) use (
                    $customerShopId,
                    $customerExternalId
                ): void {
                    $orderFallback->where('activity_logs.action', 'order.created')
                        ->where('activity_logs.subject_type', 'order')
                        ->whereRaw("TRIM({$customerExternalId}) <> ?", [''])
                        ->whereColumn('activity_logs.pancake_customer_id', $customerExternalId)
                        ->where(function (Builder $withoutLocalId): void {
                            $withoutLocalId->whereNull('activity_logs.metadata->customer_id')
                                ->orWhere('activity_logs.metadata->customer_id', '');
                        })
                        ->whereExists($this->linkedOrderExists($customerShopId, $customerExternalId));
                })->orWhere(function (Builder $assignment) use (
                    $customerShopId,
                    $customerExternalId
                ): void {
                    $assignment->whereIn('activity_logs.action', [
                        'customer_care.assigned',
                        'customer_care.reassigned',
                        'customer_care.reclaimed',
                    ])->where('activity_logs.subject_type', 'customer_care_assignment')
                        ->whereExists($this->linkedAssignmentExists(
                            $customerShopId,
                            $customerExternalId,
                            false
                        ));
                })->orWhere(function (Builder $completed) use (
                    $customerShopId,
                    $customerExternalId
                ): void {
                    $completed->where('activity_logs.action', 'customer_care.completed')
                        ->where('activity_logs.subject_type', 'customer_care')
                        ->whereExists($this->subjectExistsInShop('customer_cares', $customerShopId))
                        ->whereExists($this->linkedAssignmentExists(
                            $customerShopId,
                            $customerExternalId,
                            true
                        ));
                });
            });
    }

    private function subjectExistsInShop(string $table, string $customerShopId): QueryBuilder
    {
        return ActivityLog::query()->getQuery()->newQuery()
            ->from($table)
            ->selectRaw('1')
            ->whereColumn($table.'.id', 'activity_logs.subject_id')
            ->whereColumn($table.'.shop_id', $customerShopId);
    }

    private function linkedOrderExists(string $customerShopId, string $customerExternalId): QueryBuilder
    {
        return Order::query()->getQuery()->newQuery()
            ->from('orders')
            ->selectRaw('1')
            ->whereColumn('orders.id', 'activity_logs.subject_id')
            ->whereColumn('orders.shop_id', $customerShopId)
            ->whereColumn('orders.pancake_customer_id', $customerExternalId);
    }

    private function linkedAssignmentExists(
        string $customerShopId,
        string $customerExternalId,
        bool $matchMetadataAssignment
    ): QueryBuilder {
        $query = CustomerCareAssignment::query()->getQuery()->newQuery()
            ->from('customer_care_assignments')
            ->selectRaw('1')
            ->whereColumn('customer_care_assignments.shop_id', $customerShopId)
            ->where('customer_care_assignments.source_type', CustomerCareAssignment::SOURCE_ORDER)
            ->whereExists(
                Order::query()->getQuery()->newQuery()
                    ->from('orders')
                    ->selectRaw('1')
                    ->whereColumn('orders.id', 'customer_care_assignments.source_id')
                    ->whereColumn('orders.shop_id', $customerShopId)
                    ->whereColumn('orders.pancake_customer_id', $customerExternalId)
            );

        return $matchMetadataAssignment
            ? $query->whereColumn('customer_care_assignments.id', 'activity_logs.metadata->assignment_id')
            : $query->whereColumn('customer_care_assignments.id', 'activity_logs.subject_id');
    }

    private function whereMetadataCustomerId(Builder $query, int $customerId): void
    {
        $query->where(function (Builder $metadata) use ($customerId): void {
            $metadata->where('activity_logs.metadata->customer_id', $customerId)
                ->orWhere('activity_logs.metadata->customer_id', (string) $customerId);
        });
    }

    private function linkedOrderIds(Customer $customer): Builder
    {
        return Order::withTrashed()
            ->select('orders.id')
            ->where('orders.shop_id', (int) $customer->shop_id)
            ->where('orders.pancake_customer_id', (string) $customer->pancake_customer_id);
    }

    private function shopOrderIds(int $shopId): Builder
    {
        return Order::withTrashed()->select('orders.id')->where('orders.shop_id', $shopId);
    }

    private function shopCustomerCareIds(int $shopId): Builder
    {
        return CustomerCare::query()->select('customer_cares.id')->where('customer_cares.shop_id', $shopId);
    }

    private function shopAssignmentIds(int $shopId): Builder
    {
        return CustomerCareAssignment::query()
            ->select('customer_care_assignments.id')
            ->where('customer_care_assignments.shop_id', $shopId);
    }

    private function linkedOrderAssignmentIds(Builder $linkedOrderIds, int $shopId): Builder
    {
        return CustomerCareAssignment::query()
            ->select('customer_care_assignments.id')
            ->where('customer_care_assignments.shop_id', $shopId)
            ->where('customer_care_assignments.source_type', CustomerCareAssignment::SOURCE_ORDER)
            ->whereIn('customer_care_assignments.source_id', $linkedOrderIds);
    }

    private function hasExternalCustomerId(Customer $customer): bool
    {
        return trim((string) $customer->pancake_customer_id) !== '';
    }
}
