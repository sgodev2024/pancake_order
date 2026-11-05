<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderSourceAliasService
{
    public function decorate(Collection $sources, ?int $shopId = null): Collection
    {
        if ($sources->isEmpty() || ! Schema::hasTable('order_source_aliases')) {
            return $sources;
        }
        $shopIds = $shopId === null ? $sources->pluck('shop_id')->unique()->all() : [$shopId];
        $aliases = DB::table('order_source_aliases')->whereIn('shop_id', $shopIds)
            ->get()->keyBy(fn ($row) => $row->shop_id.':'.$row->source_id);
        return $sources->map(function ($source) use ($shopId, $aliases): array {
            $source = (array) $source;
            $alias = $aliases->get(($shopId ?? $source['shop_id']).':'.$source['id'])?->alias;
            if ($alias) {
                $source['original_name'] = $source['name'];
                $source['alias'] = $alias;
                $source['name'] = $alias.' · '.$source['name'];
            }
            return $source;
        });
    }
}
