<?php

namespace Tests\Unit;

use App\Services\OrderPageNormalizer;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class OrderPageNormalizerTest extends TestCase
{
    public function test_top_level_page_id_has_priority_and_is_normalized_as_a_string(): void
    {
        $payload = [
            'page_id' => 123456789,
            'page' => ['id' => 'page-object-id'],
            'account' => 'account-id',
        ];

        $this->assertSame('123456789', OrderPageNormalizer::id($payload));
    }

    public function test_page_object_id_is_the_first_fallback(): void
    {
        $payload = [
            'page_id' => " \t ",
            'page' => ['id' => '  page-object-id  '],
            'account' => 'account-id',
        ];

        $this->assertSame('page-object-id', OrderPageNormalizer::id($payload));
    }

    public function test_account_is_the_final_id_fallback_and_zalo_id_is_not_converted(): void
    {
        $payload = [
            'page' => [],
            'account' => '  pzl_695112902870160686  ',
        ];

        $this->assertSame('pzl_695112902870160686', OrderPageNormalizer::id($payload));
    }

    public function test_page_name_has_priority_and_is_trimmed(): void
    {
        $payload = [
            'page' => ['name' => '  Historical Page Name  '],
            'account_name' => 'Current account fallback',
        ];

        $this->assertSame('Historical Page Name', OrderPageNormalizer::name($payload));
    }

    public function test_account_name_is_used_only_when_page_name_is_blank(): void
    {
        $payload = [
            'page' => ['name' => " \r\n "],
            'account_name' => '  Account fallback  ',
        ];

        $this->assertSame('Account fallback', OrderPageNormalizer::name($payload));
    }

    public function test_blank_candidates_normalize_to_null(): void
    {
        $payload = [
            'page_id' => ' ',
            'page' => ['id' => "\t", 'name' => ''],
            'account' => "\r\n",
            'account_name' => '   ',
        ];

        $this->assertNull(OrderPageNormalizer::id($payload));
        $this->assertNull(OrderPageNormalizer::name($payload));
    }

    public function test_malformed_relevant_value_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        OrderPageNormalizer::id(['page_id' => ['not-a-scalar']]);
    }
}
