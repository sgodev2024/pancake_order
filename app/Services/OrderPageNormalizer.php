<?php

namespace App\Services;

use UnexpectedValueException;

class OrderPageNormalizer
{
    public static function id(array $payload): ?string
    {
        $pageId = self::normalize($payload['page_id'] ?? null);
        if ($pageId !== null) {
            return $pageId;
        }

        $pageId = self::normalize(self::page($payload)['id'] ?? null);

        return $pageId ?? self::normalize($payload['account'] ?? null);
    }

    public static function name(array $payload): ?string
    {
        $page = self::page($payload);

        $pageName = self::normalize($page['name'] ?? null);

        return $pageName ?? self::normalize($payload['account_name'] ?? null);
    }

    /** @return array<string, mixed> */
    private static function page(array $payload): array
    {
        $page = $payload['page'] ?? null;
        if ($page === null) {
            return [];
        }

        if (! is_array($page)) {
            throw new UnexpectedValueException('Order page snapshot must be an object.');
        }

        return $page;
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new UnexpectedValueException('Order page snapshot value must be scalar.');
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
