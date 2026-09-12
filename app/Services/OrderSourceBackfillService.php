<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PancakeOrderSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

class OrderSourceBackfillService
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int>|null  $ids
     * @return array{
     *     summary: array<string, int>,
     *     samples: list<array<int, int|string>>,
     *     catalog_available: bool
     * }
     */
    public function run(
        ?int $shopId = null,
        ?array $ids = null,
        bool $execute = false,
        int $sampleLimit = 25
    ): array {
        $summary = [
            'scanned' => 0,
            'would_backfill' => 0,
            'backfilled' => 0,
            'already_populated' => 0,
            'no_source' => 0,
            'partial_source' => 0,
            'invalid_payload' => 0,
            'conflict' => 0,
            'catalog_matched' => 0,
            'catalog_missing' => 0,
            'catalog_unavailable' => 0,
            'not_found' => 0,
        ];
        $samples = [];
        $catalogAvailable = Schema::hasTable('pancake_order_sources');
        $query = $this->scopedOrders($shopId, $ids);

        if ($execute) {
            $query->select('orders.id')->chunkById(
                self::CHUNK_SIZE,
                function (EloquentCollection $orders) use (
                    &$summary,
                    &$samples,
                    $catalogAvailable,
                    $sampleLimit,
                    $shopId
                ): void {
                    $orderIds = $orders->pluck('id')->map(fn ($id): int => (int) $id)->all();

                    DB::transaction(function () use (
                        $orderIds,
                        &$summary,
                        &$samples,
                        $catalogAvailable,
                        $sampleLimit,
                        $shopId
                    ): void {
                        $lockedOrders = $this->scopedOrders($shopId, $orderIds)
                            ->select($this->selectedColumns())
                            ->lockForUpdate()
                            ->get();

                        $this->processChunk(
                            $lockedOrders,
                            $summary,
                            $samples,
                            $catalogAvailable,
                            $sampleLimit,
                            true
                        );
                    });
                },
                'orders.id',
                'id'
            );
        } else {
            $query->select($this->selectedColumns())->chunkById(
                self::CHUNK_SIZE,
                function (EloquentCollection $orders) use (
                    &$summary,
                    &$samples,
                    $catalogAvailable,
                    $sampleLimit
                ): void {
                    $this->processChunk(
                        $orders,
                        $summary,
                        $samples,
                        $catalogAvailable,
                        $sampleLimit,
                        false
                    );
                },
                'orders.id',
                'id'
            );
        }

        if ($ids !== null) {
            $summary['not_found'] = max(0, count($ids) - $summary['scanned']);
        }

        return [
            'summary' => $summary,
            'samples' => $samples,
            'catalog_available' => $catalogAvailable,
        ];
    }

    /** @return Builder<Order> */
    private function scopedOrders(?int $shopId, ?array $ids): Builder
    {
        return Order::withTrashed()
            ->when($shopId !== null, fn ($query) => $query->where('shop_id', $shopId))
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('orders.id');
    }

    /** @return list<string> */
    private function selectedColumns(): array
    {
        return [
            'orders.id',
            'orders.shop_id',
            'orders.pancake_order_source_id',
            'orders.pancake_order_source_name',
            'orders.pancake_full_data',
        ];
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @param  array<string, int>  $summary
     * @param  list<array<int, int|string>>  $samples
     */
    private function processChunk(
        EloquentCollection $orders,
        array &$summary,
        array &$samples,
        bool $catalogAvailable,
        int $sampleLimit,
        bool $execute
    ): void {
        $evaluations = [];

        foreach ($orders as $order) {
            $evaluations[$order->getKey()] = $this->evaluate($order);
        }

        $catalogMatches = $this->catalogMatches($orders, $evaluations, $catalogAvailable);

        foreach ($orders as $order) {
            $evaluation = $evaluations[$order->getKey()];
            $catalogStatus = $this->catalogStatus(
                $order,
                $evaluation,
                $catalogMatches,
                $catalogAvailable
            );

            $this->recordSummary($summary, $evaluation, $catalogStatus);

            if ($execute && $evaluation['can_backfill']) {
                $values = array_filter([
                    'pancake_order_source_id' => $evaluation['source_id'],
                    'pancake_order_source_name' => $evaluation['source_name'],
                ], fn ($value): bool => $value !== null);

                $updated = DB::table('orders')
                    ->where('id', $order->getKey())
                    ->whereNull('pancake_order_source_id')
                    ->whereNull('pancake_order_source_name')
                    ->update($values);
                $summary['backfilled'] += $updated;
            }

            if (count($samples) < $sampleLimit) {
                $samples[] = [
                    (int) $order->getKey(),
                    (int) $order->shop_id,
                    $evaluation['classification'],
                    $evaluation['source_id'] ?? 'NULL',
                    $evaluation['source_name'] ?? 'NULL',
                    $catalogStatus,
                    $evaluation['conflict'] ? 'YES' : 'NO',
                ];
            }
        }
    }

    /**
     * @return array{
     *     classification: string,
     *     source_id: string|null,
     *     source_name: string|null,
     *     can_backfill: bool,
     *     conflict: bool
     * }
     */
    private function evaluate(Order $order): array
    {
        try {
            $payload = $this->decodePayload($order->getRawOriginal('pancake_full_data'));
        } catch (JsonException) {
            return $this->evaluation('INVALID_PAYLOAD');
        }

        $rawSourceId = $payload['order_sources'] ?? null;
        $rawSourceName = $payload['order_sources_name'] ?? null;

        if (($rawSourceId !== null && ! is_scalar($rawSourceId))
            || ($rawSourceName !== null && ! is_scalar($rawSourceName))) {
            return $this->evaluation('INVALID_PAYLOAD');
        }

        $sourceId = OrderSourceNormalizer::id($rawSourceId);
        $sourceName = OrderSourceNormalizer::name($rawSourceName);
        $existingId = $order->getRawOriginal('pancake_order_source_id');
        $existingName = $order->getRawOriginal('pancake_order_source_name');

        if ($existingId !== null || $existingName !== null) {
            $conflict = ($existingId !== null && (string) $existingId !== $sourceId)
                || ($existingName !== null && (string) $existingName !== $sourceName);

            return $this->evaluation(
                'ALREADY_POPULATED',
                $sourceId,
                $sourceName,
                false,
                $conflict
            );
        }

        if ($sourceId === null && $sourceName === null) {
            return $this->evaluation('NO_SOURCE');
        }

        if ($sourceId === null || $sourceName === null) {
            return $this->evaluation('PARTIAL_SOURCE', $sourceId, $sourceName, true);
        }

        return $this->evaluation('WOULD_BACKFILL', $sourceId, $sourceName, true);
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $rawPayload): array
    {
        if (is_array($rawPayload)) {
            return $rawPayload;
        }

        if (! is_string($rawPayload) || $rawPayload === '') {
            throw new JsonException('Invalid Order payload.');
        }

        $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new JsonException('Invalid Order payload.');
        }

        return $payload;
    }

    /**
     * @return array{
     *     classification: string,
     *     source_id: string|null,
     *     source_name: string|null,
     *     can_backfill: bool,
     *     conflict: bool
     * }
     */
    private function evaluation(
        string $classification,
        ?string $sourceId = null,
        ?string $sourceName = null,
        bool $canBackfill = false,
        bool $conflict = false
    ): array {
        return [
            'classification' => $classification,
            'source_id' => $sourceId,
            'source_name' => $sourceName,
            'can_backfill' => $canBackfill,
            'conflict' => $conflict,
        ];
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @param  array<int, array<string, bool|string|null>>  $evaluations
     * @return array<int, array<string, true>>
     */
    private function catalogMatches(
        EloquentCollection $orders,
        array $evaluations,
        bool $catalogAvailable
    ): array {
        if (! $catalogAvailable) {
            return [];
        }

        $idsByShop = [];
        foreach ($orders as $order) {
            $sourceId = $evaluations[$order->getKey()]['source_id'];
            if ($sourceId !== null) {
                $idsByShop[(int) $order->shop_id][$sourceId] = true;
            }
        }

        $matches = [];
        foreach ($idsByShop as $shopId => $sourceIds) {
            $matchedIds = PancakeOrderSource::query()
                ->where('shop_id', $shopId)
                ->whereIn('external_source_id', array_keys($sourceIds))
                ->pluck('external_source_id');

            foreach ($matchedIds as $matchedId) {
                $matches[$shopId][(string) $matchedId] = true;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, bool|string|null>  $evaluation
     * @param  array<int, array<string, true>>  $catalogMatches
     */
    private function catalogStatus(
        Order $order,
        array $evaluation,
        array $catalogMatches,
        bool $catalogAvailable
    ): string {
        if ($evaluation['source_id'] === null) {
            return 'NOT_APPLICABLE';
        }

        if (! $catalogAvailable) {
            return 'UNAVAILABLE';
        }

        return isset($catalogMatches[(int) $order->shop_id][$evaluation['source_id']])
            ? 'MATCHED'
            : 'NOT_IN_CATALOG';
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<string, bool|string|null>  $evaluation
     */
    private function recordSummary(array &$summary, array $evaluation, string $catalogStatus): void
    {
        $summary['scanned']++;

        match ($evaluation['classification']) {
            'WOULD_BACKFILL' => $summary['would_backfill']++,
            'PARTIAL_SOURCE' => $this->recordPartial($summary),
            'ALREADY_POPULATED' => $summary['already_populated']++,
            'NO_SOURCE' => $summary['no_source']++,
            default => $summary['invalid_payload']++,
        };

        if ($evaluation['conflict']) {
            $summary['conflict']++;
        }

        match ($catalogStatus) {
            'MATCHED' => $summary['catalog_matched']++,
            'NOT_IN_CATALOG' => $summary['catalog_missing']++,
            'UNAVAILABLE' => $summary['catalog_unavailable']++,
            default => null,
        };
    }

    /** @param array<string, int> $summary */
    private function recordPartial(array &$summary): void
    {
        $summary['partial_source']++;
        $summary['would_backfill']++;
    }
}
