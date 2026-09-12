<?php

namespace App\Services;

class OrderSourceNormalizer
{
    public static function id(mixed $sourceId): ?string
    {
        return $sourceId === null ? null : (string) $sourceId;
    }

    public static function name(mixed $sourceName): ?string
    {
        if ($sourceName === null) {
            return null;
        }

        $sourceName = trim((string) $sourceName);

        return $sourceName === '' ? null : $sourceName;
    }
}
