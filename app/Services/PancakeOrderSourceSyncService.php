<?php

namespace App\Services;

use App\Models\PancakeOrderSource;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class PancakeOrderSourceSyncService
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const TIMEOUT_SECONDS = 15;

    private const RETRY_TIMES = 3;

    /**
     * @return array{
     *     shop_id: int,
     *     shop_name: string,
     *     pancake_shop_id: string,
     *     received: int,
     *     created: int,
     *     updated: int,
     *     deactivated: int,
     *     unchanged: int,
     *     executed: bool
     * }
     */
    public function sync(
        Shop $shop,
        bool $execute = false,
        ?CarbonInterface $syncTime = null
    ): array {
        $remoteSources = $this->fetchSources($shop);
        $syncTime = $syncTime === null
            ? CarbonImmutable::now(config('app.timezone'))
            : CarbonImmutable::instance($syncTime)->setTimezone(config('app.timezone'));

        if (! $execute) {
            $plan = $this->buildPlan($shop, $remoteSources);

            return $this->summary($shop, $remoteSources, $plan, false);
        }

        return DB::transaction(function () use ($shop, $remoteSources, $syncTime): array {
            $plan = $this->buildPlan($shop, $remoteSources, true);

            foreach ($remoteSources as $source) {
                PancakeOrderSource::query()->updateOrCreate(
                    [
                        'shop_id' => $shop->getKey(),
                        'external_source_id' => $source['external_source_id'],
                    ],
                    [
                        ...$source,
                        'is_active' => true,
                        'last_seen_at' => $syncTime,
                        'synced_at' => $syncTime,
                    ]
                );
            }

            $missingActiveSources = PancakeOrderSource::query()
                ->where('shop_id', $shop->getKey())
                ->where('is_active', true);

            $remoteIds = array_column($remoteSources, 'external_source_id');
            if ($remoteIds !== []) {
                $missingActiveSources->whereNotIn('external_source_id', $remoteIds);
            }

            $missingActiveSources->update([
                'is_active' => false,
                'synced_at' => $syncTime,
                'updated_at' => $syncTime,
            ]);

            return $this->summary($shop, $remoteSources, $plan, true);
        });
    }

    /**
     * @return list<array{
     *     external_source_id: string,
     *     name: string,
     *     parent_external_source_id: string|null,
     *     link: string|null,
     *     source_inserted_at: string|null,
     *     source_updated_at: string|null
     * }>
     */
    private function fetchSources(Shop $shop): array
    {
        $pancakeShopId = trim((string) $shop->pancake_shop_id);
        if ($pancakeShopId === '') {
            throw new PancakeOrderSourceSyncException(
                "Local shop {$shop->getKey()} is missing its Pancake shop ID."
            );
        }

        $apiKey = trim((string) $shop->api_key);
        if ($apiKey === '') {
            throw new PancakeOrderSourceSyncException(
                "Local shop {$shop->getKey()} is missing its Pancake credential."
            );
        }

        $baseUrl = rtrim((string) config('services.pancake.api_v1_url'), '/');
        if ($baseUrl === '') {
            throw new PancakeOrderSourceSyncException(
                'The Pancake API base URL is not configured.'
            );
        }

        $url = $baseUrl.'/shops/'.rawurlencode($pancakeShopId).'/order_source';

        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(self::RETRY_TIMES, 250, throw: false)
                ->get($url, ['api_key' => $apiKey]);
        } catch (Throwable) {
            throw new PancakeOrderSourceSyncException(
                "Pancake order source request failed for local shop {$shop->getKey()}."
            );
        }

        if (! $response->successful()) {
            throw new PancakeOrderSourceSyncException(
                "Pancake order source request returned HTTP {$response->status()} for local shop {$shop->getKey()}."
            );
        }

        $payload = $response->json();
        if (! is_array($payload)
            || (array_key_exists('success', $payload) && $payload['success'] !== true)
            || ! array_key_exists('data', $payload)
            || ! is_array($payload['data'])) {
            throw new PancakeOrderSourceSyncException(
                "Pancake returned a malformed order source response for local shop {$shop->getKey()}."
            );
        }

        return $this->normalizeSources($payload['data'], (int) $shop->getKey());
    }

    /**
     * @param  array<int|string, mixed>  $sources
     * @return list<array{
     *     external_source_id: string,
     *     name: string,
     *     parent_external_source_id: string|null,
     *     link: string|null,
     *     source_inserted_at: string|null,
     *     source_updated_at: string|null
     * }>
     */
    private function normalizeSources(array $sources, int $localShopId): array
    {
        $normalized = [];

        foreach ($sources as $index => $source) {
            if (! is_array($source)
                || ! array_key_exists('id', $source)
                || ! $this->isStringableScalar($source['id'])
                || (string) $source['id'] === ''
                || ! array_key_exists('name', $source)
                || ! $this->isStringableScalar($source['name'])) {
                throw new PancakeOrderSourceSyncException(
                    "Pancake returned a malformed order source at index {$index} for local shop {$localShopId}."
                );
            }

            $externalSourceId = (string) $source['id'];
            if (array_key_exists($externalSourceId, $normalized)) {
                throw new PancakeOrderSourceSyncException(
                    "Pancake returned duplicate order source IDs for local shop {$localShopId}."
                );
            }

            $normalized[$externalSourceId] = [
                'external_source_id' => $externalSourceId,
                'name' => trim((string) $source['name']),
                'parent_external_source_id' => $this->normalizeNullableString(
                    $source['parent_id'] ?? null,
                    $index,
                    'parent_id',
                    $localShopId
                ),
                'link' => $this->normalizeNullableString(
                    $source['link'] ?? null,
                    $index,
                    'link',
                    $localShopId
                ),
                'source_inserted_at' => $this->normalizeNullableTimestamp(
                    $source['inserted_at'] ?? null,
                    $index,
                    'inserted_at',
                    $localShopId
                ),
                'source_updated_at' => $this->normalizeNullableTimestamp(
                    $source['updated_at'] ?? null,
                    $index,
                    'updated_at',
                    $localShopId
                ),
            ];
        }

        return array_values($normalized);
    }

    private function normalizeNullableString(
        mixed $value,
        int|string $index,
        string $field,
        int $localShopId
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! $this->isStringableScalar($value)) {
            throw new PancakeOrderSourceSyncException(
                "Pancake returned malformed {$field} at source index {$index} for local shop {$localShopId}."
            );
        }

        return (string) $value;
    }

    private function normalizeNullableTimestamp(
        mixed $value,
        int|string $index,
        string $field,
        int $localShopId
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $this->isStringableScalar($value)) {
            throw new PancakeOrderSourceSyncException(
                "Pancake returned malformed {$field} at source index {$index} for local shop {$localShopId}."
            );
        }

        try {
            return CarbonImmutable::parse((string) $value)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new PancakeOrderSourceSyncException(
                "Pancake returned malformed {$field} at source index {$index} for local shop {$localShopId}."
            );
        }
    }

    private function isStringableScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }

    /**
     * @param  list<array<string, string|null>>  $remoteSources
     * @return array{created: int, updated: int, deactivated: int, unchanged: int}
     */
    private function buildPlan(Shop $shop, array $remoteSources, bool $lock = false): array
    {
        $query = PancakeOrderSource::query()->where('shop_id', $shop->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var Collection<string, PancakeOrderSource> $existingSources */
        $existingSources = $query->get()->keyBy('external_source_id');
        $seenIds = [];
        $plan = [
            'created' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'unchanged' => 0,
        ];

        foreach ($remoteSources as $source) {
            $externalSourceId = $source['external_source_id'];
            $seenIds[$externalSourceId] = true;
            $existing = $existingSources->get($externalSourceId);

            if ($existing === null) {
                $plan['created']++;

                continue;
            }

            if (! $existing->is_active || $this->canonicalFieldsChanged($existing, $source)) {
                $plan['updated']++;
            } else {
                $plan['unchanged']++;
            }
        }

        foreach ($existingSources as $externalSourceId => $existing) {
            if (isset($seenIds[$externalSourceId])) {
                continue;
            }

            if ($existing->is_active) {
                $plan['deactivated']++;
            } else {
                $plan['unchanged']++;
            }
        }

        return $plan;
    }

    /** @param array<string, string|null> $source */
    private function canonicalFieldsChanged(PancakeOrderSource $existing, array $source): bool
    {
        foreach ([
            'name',
            'parent_external_source_id',
            'link',
            'source_inserted_at',
            'source_updated_at',
        ] as $field) {
            $existingValue = $existing->{$field};
            if ($existingValue instanceof CarbonInterface) {
                $existingValue = $existingValue->format('Y-m-d H:i:s');
            }

            if ($existingValue !== $source[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, string|null>>  $remoteSources
     * @param  array{created: int, updated: int, deactivated: int, unchanged: int}  $plan
     * @return array<string, bool|int|string>
     */
    private function summary(Shop $shop, array $remoteSources, array $plan, bool $executed): array
    {
        return [
            'shop_id' => (int) $shop->getKey(),
            'shop_name' => (string) $shop->name,
            'pancake_shop_id' => (string) $shop->pancake_shop_id,
            'received' => count($remoteSources),
            ...$plan,
            'executed' => $executed,
        ];
    }
}
