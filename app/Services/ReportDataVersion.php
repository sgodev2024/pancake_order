<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportDataVersion
{
    public static function key(string $domain): string
    {
        return (string) Cache::get('report_version:'.$domain, '0');
    }

    public static function bump(string ...$domains): void
    {
        foreach (array_unique($domains) as $domain) {
            Cache::forever('report_version:'.$domain, (string) Str::uuid());
        }
    }

    public static function afterCommit(string ...$domains): void
    {
        DB::afterCommit(fn () => self::bump(...$domains));
    }
}
