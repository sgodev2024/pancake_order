<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CustomerEnteredSystemEventWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GetCustomerByShopJob implements ShouldQueue
{
    use Queueable;

    protected $datas;

    protected $shop_id;

    // Retry three times when the customer/event transaction fails.
    public $tries = 3;

    public $backoff = [30, 60, 120];

    public function __construct(
        $datas,
        $shop_id
    ) {
        $this->datas = $datas;
        $this->shop_id = $shop_id;
    }

    /**
     * Insert the batch and its entry events in one transaction.
     *
     * The post-insert query is deliberately by shop plus external IDs; local
     * IDs are never inferred from an auto-increment range.
     */
    public function handle(CustomerEnteredSystemEventWriter $eventWriter): void
    {
        try {
            DB::transaction(function () use ($eventWriter): void {
                $shopId = (int) $this->shop_id;
                $insertData = [];
                $newExternalIds = [];
                $occurredAtByExternalId = [];
                $acquisitionChannelByExternalId = [];
                $hasLastOrderAt = Schema::hasColumn('customers', 'last_order_at');

                foreach ($this->datas as $dataItem) {
                    $externalId = (string) $dataItem['customer_id'];
                    $customer = Customer::query()
                        ->where('shop_id', $shopId)
                        ->where('pancake_customer_id', $externalId)
                        ->first();

                    if ($customer !== null) {
                        $updates = [
                            'order_count' => $dataItem['order_count'] ?? 0,
                            'purchased_amount' => $dataItem['purchased_amount'] ?? 0,
                            'pancake_full_data' => $dataItem,
                            'phone_numbers' => ! empty($dataItem['phone_numbers'])
                                ? implode(',', $dataItem['phone_numbers'])
                                : null,
                        ];
                        if ($hasLastOrderAt) {
                            $updates['last_order_at'] = $this->parseLastOrderAt($dataItem['last_order_at'] ?? null);
                        }
                        $customer->update($updates);

                        continue;
                    }

                    // Preserve the existing import rule: customers without a
                    // positive order count are not created by this sync.
                    if ((int) ($dataItem['order_count'] ?? 0) <= 0) {
                        continue;
                    }

                    // A repeated external ID in one response is one candidate
                    // customer. Keep the first source row and never create a
                    // duplicate event for the same inserted local row.
                    if (isset($occurredAtByExternalId[$externalId])) {
                        continue;
                    }

                    $occurredAt = $this->parseInsertedAt($dataItem['inserted_at']);
                    $customerData = [
                        'order_count' => $dataItem['order_count'] ?? 0,
                        'shop_id' => $shopId,
                        'assigned_user_id' => $dataItem['assigned_user_id'] ?? null,
                        'pancake_customer_id' => $externalId,
                        'fb_id' => $dataItem['fb_id'] ?? null,
                        'name' => $dataItem['name'] ?? null,
                        'purchased_amount' => $dataItem['purchased_amount'] ?? 0,
                        'loyalty_tier_id' => get_loyalty_tier($dataItem['purchased_amount'] ?? 0),
                        'phone_numbers' => ! empty($dataItem['phone_numbers'])
                            ? implode(',', $dataItem['phone_numbers'])
                            : null,
                        'pancake_full_data' => json_encode($dataItem),
                        'created_at' => $occurredAt->format('Y-m-d H:i:s'),
                        'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
                    ];
                    if ($hasLastOrderAt) {
                        $customerData['last_order_at'] = $this->parseLastOrderAt($dataItem['last_order_at'] ?? null)?->format('Y-m-d H:i:s');
                    }
                    $insertData[] = $customerData;
                    $newExternalIds[] = $externalId;
                    $occurredAtByExternalId[$externalId] = $occurredAt;
                    $acquisitionChannelByExternalId[$externalId] = $this->acquisitionChannel($dataItem);
                }

                if ($insertData === []) {
                    return;
                }

                Customer::insert($insertData);

                $insertedCustomers = Customer::query()
                    ->where('shop_id', $shopId)
                    ->whereIn('pancake_customer_id', $newExternalIds)
                    ->get(['id', 'shop_id', 'pancake_customer_id', 'created_at']);
                $customersByExternalId = $insertedCustomers->keyBy(
                    fn (Customer $customer): string => (string) $customer->pancake_customer_id
                );

                if ($customersByExternalId->count() !== count($newExternalIds)) {
                    throw new \RuntimeException(
                        'Could not resolve every newly inserted customer for journey logging.'
                    );
                }

                foreach ($newExternalIds as $externalId) {
                    $customer = $customersByExternalId->get($externalId);

                    if (! $customer instanceof Customer) {
                        throw new \RuntimeException(
                            'Could not resolve a newly inserted customer for journey logging.'
                        );
                    }

                    $eventWriter->write(
                        $customer,
                        'pancake_bulk_sync',
                        $occurredAtByExternalId[$externalId],
                        $acquisitionChannelByExternalId[$externalId]
                    );
                }
            });
        } catch (\Throwable $throwable) {
            Log::info($throwable->getMessage());

            // A failed event must fail the job so the transaction is retried;
            // swallowing this error would leave an untracked new customer.
            throw $throwable;
        }
    }

    private function parseInsertedAt(mixed $insertedAt): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $insertedAt, 'UTC')
            ->setTimezone(config('app.timezone'));
    }

    private function parseLastOrderAt(mixed $lastOrderAt): ?CarbonImmutable
    {
        if (! is_string($lastOrderAt) || trim($lastOrderAt) === '') {
            return null;
        }

        return CarbonImmutable::parse($lastOrderAt, 'UTC')
            ->setTimezone(config('app.timezone'));
    }

    private function acquisitionChannel(array $dataItem): ?string
    {
        $channel = $dataItem['acquisition_channel'] ?? null;

        if (! is_string($channel)) {
            return null;
        }

        $channel = trim($channel);

        return $channel === '' ? null : $channel;
    }
}
