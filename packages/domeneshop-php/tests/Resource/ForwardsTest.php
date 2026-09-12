<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Resource;

use Sebastka\Domeneshop\Model\HttpForward;
use Sebastka\Domeneshop\Tests\DomeneshopTestCase;

final class ForwardsTest extends DomeneshopTestCase
{
    public function testListMapsForwards(): void
    {
        $client = $this->client(self::json([
            ['host' => '@', 'url' => 'https://www.example.com', 'frame' => false],
            ['host' => 'old', 'url' => 'https://new.example.com', 'frame' => true],
        ]));

        $forwards = $client->forwards()->list(5);

        self::assertSame('/v0/domains/5/forwards/', $this->http->lastPath());
        self::assertSame('@', $forwards[0]->host);
        self::assertSame('https://www.example.com', $forwards[0]->url);
        self::assertFalse($forwards[0]->frame);
        self::assertTrue($forwards[1]->frame);
    }

    /**
     * get() reads the collection and filters, because the API's per-host
     * endpoint 404s for every host (verified live — see Forwards' class note).
     */
    public function testGetReadsTheCollectionRatherThanTheBrokenItemEndpoint(): void
    {
        $client = $this->client(self::json([
            ['host' => 'www', 'url' => 'https://a.example.com', 'frame' => false],
            ['host' => '@', 'url' => 'https://b.example.com', 'frame' => true],
        ]));

        $forward = $client->forwards()->get(5, '@');

        self::assertSame('/v0/domains/5/forwards/', $this->http->lastPath());
        self::assertSame('https://b.example.com', $forward->url);
        self::assertTrue($forward->frame);
    }

    public function testGetThrowsNotFoundWhenNoForwardHasThatHost(): void
    {
        $client = $this->client(self::json([['host' => 'www', 'url' => 'https://example.com', 'frame' => false]]));

        $this->expectException(\Sebastka\Domeneshop\Exception\NotFoundException::class);
        $client->forwards()->get(5, '@');
    }

    public function testTheApexHostIsStillEncodedOnPathsThatUseIt(): void
    {
        // delete() still addresses the host directly; `@` must survive as %40
        // rather than being taken for a userinfo delimiter.
        $client = $this->client(self::noContent());
        $client->forwards()->delete(5, '@');

        self::assertStringEndsWith('/v0/domains/5/forwards/%40', $this->http->lastUri());
    }

    public function testCreatePostsTheForward(): void
    {
        $client = $this->client(self::noContent(201));
        $client->forwards()->create(5, new HttpForward('www', 'https://example.com'));

        self::assertSame('POST', $this->http->lastMethod());
        self::assertSame('/v0/domains/5/forwards/', $this->http->lastPath());
        self::assertSame(
            ['host' => 'www', 'url' => 'https://example.com', 'frame' => false],
            $this->http->lastBody(),
        );
    }

    public function testUpdateReturnsTheStoredForward(): void
    {
        $client = $this->client(self::json(['host' => 'www', 'url' => 'https://stored.example.com', 'frame' => false]));

        $result = $client->forwards()->update(5, 'www', new HttpForward('www', 'https://sent.example.com'));

        self::assertSame('PUT', $this->http->lastMethod());
        self::assertSame('https://stored.example.com', $result->url);
    }

    public function testUpdateFallsBackToTheSentForwardOnAnEmptyBody(): void
    {
        $client = $this->client(self::noContent(200));

        $result = $client->forwards()->update(5, 'www', new HttpForward('www', 'https://sent.example.com'));

        self::assertSame('https://sent.example.com', $result->url);
    }

    public function testDeleteIssuesADeleteRequest(): void
    {
        $client = $this->client(self::noContent());
        $client->forwards()->delete(5, 'www');

        self::assertSame('DELETE', $this->http->lastMethod());
        self::assertSame('/v0/domains/5/forwards/www', $this->http->lastPath());
    }
}
