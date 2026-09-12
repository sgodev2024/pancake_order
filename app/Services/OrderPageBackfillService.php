<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PancakeOrderSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use UnexpectedValueException;

class OrderPageBackfillService
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
            'partial_page' => 0,
            'no_page' => 0,
            'already_populated' => 0,
            'conflicts' => 0,
            'invalid_payload' => 0,
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
            'orders.pancake_order_page_id',
            'orders.pancake_order_page_name',
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
                    'pancake_order_page_id' => $evaluation['page_id'],
                    'pancake_order_page_name' => $evaluation['page_name'],
                ], fn ($value): bool => $value !== null);

                $updated = DB::table('orders')
                    ->where('id', $order->getKey())
                    ->whereNull('pancake_order_page_id')
                    ->whereNull('pancake_order_page_name')
                    ->update($values);
                $summary['backfilled'] += $updated;
            }

            if (count($samples) < $sampleLimit) {
                $samples[] = [
                    (int) $order->getKey(),
                    (int) $order->shop_id,
                    $evaluation['classification'],
                    $evaluation['page_id'] ?? 'NULL',
                    $evaluation['page_name'] ?? 'NULL',
                    $catalogStatus,
                ];
            }
        }
    }

    /**
     * @return array{
     *     classification: string,
     *     page_id: string|null,
     *     page_name: string|null,
     *     can_backfill: bool
     * }
     */
    private function evaluate(Order $order): array
    {
        try {
            $payload = $this->decodePayload($order->getRawOriginal('pancake_full_data'));
            $pageId = OrderPageNormalizer::id($payload);
            $pageName = OrderPageNormalizer::name($payload);
        } catch (JsonException|UnexpectedValueException) {
            return $this->evaluation('INVALID_PAYLOAD');
        }

        $existingId = $order->getRawOriginal('pancake_order_page_id');
        $existingName = $order->getRawOriginal('pancake_order_page_name');

        if ($existingId !== null || $existingName !== null) {
            $conflict = ($existingId !== null && (string) $existingId !== $pageId)
                || ($existingName !== null && (string) $existingName !== $pageName);

            return $this->evaluation(
                $conflict ? 'CONFLICT' : 'ALREADY_POPULATED',
                $pageId,
                $pageName
            );
        }

        if ($pageId === null && $pageName === null) {
            return $this->evaluation('NO_PAGE');
        }

        if ($pageId === null || $pageName === null) {
            return $this->evaluation('PARTIAL_PAGE', $pageId, $pageName, true);
        }

        return $this->evaluation('WOULD_BACKFILL', $pageId, $pageName, true);
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
     *     page_id: string|null,
     *     page_name: string|null,
     *     can_backfill: bool
     * }
     */
    private function evaluation(
        string $classification,
        ?string $pageId = null,
        ?string $pageName = null,
        bool $canBackfill = false
    ): array {
        return [
            'classification' => $classification,
            'page_id' => $pageId,
            'page_name' => $pageName,
            'can_backfill' => $canBackfill,
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
            $pageId = $evaluations[$order->getKey()]['page_id'];
            if ($pageId !== null) {
                $idsByShop[(int) $order->shop_id][$pageId] = true;
            }
        }

        $matches = [];
        foreach ($idsByShop as $shopId => $pageIds) {
            $matchedIds = PancakeOrderSource::query()
                ->where('shop_id', $shopId)
                ->whereIn('external_source_id', array_keys($pageIds))
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
        if ($evaluation['page_id'] === null) {
            return 'NOT_APPLICABLE';
        }

        if (! $catalogAvailable) {
            return 'UNAVAILABLE';
        }

        return isset($catalogMatches[(int) $order->shop_id][$evaluation['page_id']])
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
            'PARTIAL_PAGE' => $this->recordPartial($summary),
            'NO_PAGE' => $summary['no_page']++,
            'ALREADY_POPULATED' => $summary['already_populated']++,
            'CONFLICT' => $summary['conflicts']++,
            default => $summary['invalid_payload']++,
        };

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
        $summary['partial_page']++;
        $summary['would_backfill']++;
    }
}
