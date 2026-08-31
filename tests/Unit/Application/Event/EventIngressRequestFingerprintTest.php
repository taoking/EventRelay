<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Event;

use App\Application\Event\EventIngressIdempotencyKey;
use App\Application\Event\EventIngressRequestFingerprint;
use PHPUnit\Framework\TestCase;

final class EventIngressRequestFingerprintTest extends TestCase
{
    public function test_key_digest_matches_the_fixed_event_ingress_vector(): void
    {
        $key = EventIngressIdempotencyKey::fromOptional('evt-test-001');

        self::assertNotNull($key);
        self::assertSame('1311614042ef2747b331fd9ccc6a570dd8b4e8a9a0a7b6772334457336abc8c8', $key->digest());
    }

    public function test_fingerprint_matches_the_fixed_v1_vector(): void
    {
        $fingerprint = EventIngressRequestFingerprint::from('order.paid', (object) [
            'b' => 2,
            'a' => (object) [
                'z' => true,
                'm' => [3, 2, 1],
            ],
            'empty' => (object) [],
        ]);

        self::assertSame('{"a":{"m":[3,2,1],"z":true},"b":2,"empty":{}}', $fingerprint->canonicalPayload());
        self::assertSame('58da2d82e6edc34c487fdaed21df917d424398d2f0cf7ef0b56d653d66f79aff', $fingerprint->value());
    }

    public function test_object_key_order_is_recursive_but_array_order_remains_significant(): void
    {
        $first = EventIngressRequestFingerprint::from('order.paid', (object) [
            'b' => (object) ['z' => true, 'a' => (object) []],
            'a' => ['first', 'second'],
        ]);
        $sameObjectDifferentOrder = EventIngressRequestFingerprint::from('order.paid', (object) [
            'a' => ['first', 'second'],
            'b' => (object) ['a' => (object) [], 'z' => true],
        ]);
        $differentArrayOrder = EventIngressRequestFingerprint::from('order.paid', (object) [
            'a' => ['second', 'first'],
            'b' => (object) ['a' => (object) [], 'z' => true],
        ]);

        self::assertSame($first->value(), $sameObjectDifferentOrder->value());
        self::assertNotSame($first->value(), $differentArrayOrder->value());
        self::assertSame('{"a":["first","second"],"b":{"a":{},"z":true}}', $first->canonicalPayload());
    }
}
