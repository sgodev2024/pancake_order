<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class OrderItemSql
{
    public static function select(string $sql, array $bindings = []): array
    {
        if (str_contains($sql, 'JSON_TABLE') && !self::supportsJsonTable()) {
            if (!preg_match('/\bWHERE\s+(.+?)(?:\bGROUP BY\b|\bHAVING\b|\bORDER BY\b|\bLIMIT\b|$)/is', $sql, $match)) {
                throw new \LogicException('Order item query requires a scoped WHERE clause.');
            }
            $row = DB::selectOne("SELECT COALESCE(MAX(JSON_LENGTH(JSON_EXTRACT(orders.pancake_full_data, '$.items'))), 0) AS item_count FROM orders WHERE ".$match[1], $bindings);
            $sql = self::expand($sql, (int) $row->item_count);
        }
        return DB::select($sql, $bindings);
    }

    public static function selectOne(string $sql, array $bindings = []): ?object
    {
        return self::select($sql, $bindings)[0] ?? null;
    }

    private static function supportsJsonTable(): bool
    {
        static $supported;
        if ($supported === null) {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            preg_match('/(\d+\.\d+\.\d+)/', $version, $parts);
            $supported = version_compare($parts[1] ?? '0', stripos($version, 'MariaDB') !== false ? '10.6.0' : '8.0.4', '>=');
        }
        return $supported;
    }

    public static function expand(string $sql, int $itemCount): string
    {
        // Derive the range from scoped orders, rather than truncating long item arrays.
        $indices = implode(' UNION ALL ', array_map(fn ($n) => "SELECT {$n} AS n", range(0, max(0, $itemCount - 1))));
        $sql = preg_replace("/JOIN JSON_TABLE\(orders\.pancake_full_data, .*? COLUMNS \(.*?\)\) AS item/is", "JOIN ({$indices}) AS item ON item.n < JSON_LENGTH(JSON_EXTRACT(orders.pancake_full_data, '$.items'))", $sql);
        foreach (['product_name' => ['variation_info.name', null], 'quantity' => ['quantity', 'DECIMAL(12,2)'], 'retail_price' => ['variation_info.retail_price', 'DECIMAL(14,2)']] as $column => [$path, $cast]) {
            $value = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(orders.pancake_full_data, CONCAT('$.items[', item.n, '].{$path}'))), 'null')";
            $value = $cast ? "CAST({$value} AS {$cast})" : "LEFT({$value}, 255)";
            $sql = str_replace('item.'.$column, $value, $sql);
        }
        return $sql;
    }
}

