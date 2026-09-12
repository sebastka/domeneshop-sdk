<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\Model\ARecord;
use Sebastka\Domeneshop\Model\DnsRecord;
use Sebastka\Domeneshop\Model\HttpForward;
use Sebastka\Domeneshop\Model\MxRecord;
use Sebastka\Domeneshop\Model\RecordType;

final class ModelTest extends TestCase
{
    public function testATtlOfNullIsAllowedAndOmitted(): void
    {
        self::assertArrayNotHasKey('ttl', (new ARecord('@', '203.0.113.10'))->toArray());
    }

    /** @return iterable<string, array{int}> */
    public static function validTtlProvider(): iterable
    {
        yield 'minimum' => [60];
        yield 'default' => [3600];
        yield 'maximum' => [604800];
    }

    #[DataProvider('validTtlProvider')]
    public function testValidTtlsAreAccepted(int $ttl): void
    {
        self::assertSame($ttl, (new ARecord('@', '203.0.113.10', ttl: $ttl))->ttl);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidTtlProvider(): iterable
    {
        yield 'below the minimum' => [59];
        yield 'above the maximum' => [604860];
        yield 'not a whole minute' => [90];
        yield 'zero' => [0];
        yield 'negative' => [-60];
    }

    #[DataProvider('invalidTtlProvider')]
    public function testInvalidTtlsAreRejectedBeforeTheRequestIsMade(int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ARecord('@', '203.0.113.10', ttl: $ttl);
    }

    public function testFromArrayRejectsAPayloadWithoutAType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DnsRecord::fromArray(['host' => '@', 'data' => 'x']);
    }

    public function testFromArrayRejectsATypeWeDoNotModel(): void
    {
        $this->expectExceptionMessage('Unsupported DNS record type "NAPTR"');
        DnsRecord::fromArray(['host' => '@', 'type' => 'NAPTR', 'data' => 'x']);
    }

    public function testARecordRoundTripsThroughFromArray(): void
    {
        $original = new MxRecord('@', 'mx.example.com', 10, ttl: 600, id: 4);
        $restored = DnsRecord::fromArray($original->toArray() + ['id' => 4]);

        self::assertEquals($original, $restored);
    }

    public function testTypeIsReportedPerSubclass(): void
    {
        self::assertSame(RecordType::A, (new ARecord('@', '203.0.113.10'))->type());
        self::assertSame(RecordType::MX, (new MxRecord('@', 'mx.example.com', 10))->type());
    }

    public function testForwardRoundTrips(): void
    {
        $forward = new HttpForward('www', 'https://example.com', frame: true);

        self::assertEquals($forward, HttpForward::fromArray($forward->toArray()));
    }

    public function testForwardDefaultsToRedirectingRatherThanFraming(): void
    {
        self::assertFalse((new HttpForward('www', 'https://example.com'))->frame);
    }
}
