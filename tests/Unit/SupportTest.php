<?php

namespace Faultbox\Laravel\Tests\Unit;

use Faultbox\Laravel\Support\Sanitizer;
use Faultbox\Laravel\Support\Uuid;
use Faultbox\Laravel\Transport\Response;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_uuid_v7_layout_and_time_order(): void
    {
        $a = Uuid::v7(1_700_000_000_000);
        $b = Uuid::v7(1_700_000_000_001);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertStringStartsWith('018bcfe5-6800', $a, 'first 48 bits are the millisecond timestamp');
        $this->assertLessThan(0, strcmp($a, $b), 'later IDs sort later');
        $this->assertNotSame(Uuid::v7(1), Uuid::v7(1), 'random bits differ');
    }

    public function test_sanitizer_redacts_nested_sensitive_keys(): void
    {
        $s = new Sanitizer('/pass(word)?|secret|token|api[-_]?key|card/i');

        $clean = $s->clean([
            'user' => ['email' => 'a@b.c', 'password' => 'hunter2'],
            'api_key' => 'k',
            'items' => [['sku' => 'A1', 'card_number' => '4242']],
            'empty_token' => null,
            'when' => new \DateTimeImmutable('2026-11-09T10:00:00Z'),
            'object' => new \stdClass(),
        ]);

        $this->assertSame('a@b.c', $clean['user']['email']);
        $this->assertSame(Sanitizer::REDACTED, $clean['user']['password']);
        $this->assertSame(Sanitizer::REDACTED, $clean['api_key']);
        $this->assertSame(Sanitizer::REDACTED, $clean['items'][0]['card_number']);
        $this->assertSame('A1', $clean['items'][0]['sku']);
        $this->assertNull($clean['empty_token'], 'null stays null');
        $this->assertSame('2026-11-09T10:00:00+00:00', $clean['when']);
        $this->assertSame('stdClass', $clean['object']);
    }

    public function test_sanitizer_redacts_query_string_secrets(): void
    {
        $s = new Sanitizer('/token|secret/i');

        $this->assertSame(
            'https://shop.test/reset?email=a%40b.c&token=%5Bredacted%5D&page=2#top',
            $s->url('https://shop.test/reset?email=a%40b.c&token=abc123&page=2#top'),
        );
        $this->assertSame('https://shop.test/orders', $s->url('https://shop.test/orders'));
    }

    public function test_sanitizer_stops_at_depth_limit(): void
    {
        $deep = [];
        $cursor = &$deep;
        for ($i = 0; $i < 20; $i++) {
            $cursor['next'] = [];
            $cursor = &$cursor['next'];
        }
        $json = json_encode((new Sanitizer('/x^/'))->clean($deep));
        $this->assertStringContainsString('[too deep]', $json);
    }

    public function test_response_classification(): void
    {
        $this->assertTrue((new Response(202))->accepted());
        foreach ([0, 429, 503] as $status) {
            $this->assertTrue((new Response($status))->retryable(), "$status retryable");
        }
        foreach ([400, 401, 403, 413, 422] as $status) {
            $this->assertFalse((new Response($status))->retryable(), "$status not retryable");
        }
        $this->assertSame('invalid_api_key', (new Response(401, '{"error":{"code":"invalid_api_key","message":"x"}}'))->errorCode());
    }
}
