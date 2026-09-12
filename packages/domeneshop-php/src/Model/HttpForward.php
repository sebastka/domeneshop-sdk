<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An HTTP forward: a subdomain that redirects to a URL.
 *
 * The `host` is the forward's identity — the API addresses forwards by host
 * rather than by a numeric id, and refuses (412) an update that tries to change
 * it. To move a forward to a different host, delete it and create a new one.
 */
final class HttpForward
{
    /**
     * @param string $host  The subdomain this forward applies to, without the
     *                      domain part — `www` for `www.example.com`, `@` for the apex.
     * @param string $url   The target URL. Must include a scheme, e.g. `https://`.
     * @param bool   $frame Serve the target inside an iframe instead of
     *                      redirecting. Domeneshop recommends against it, and so do we.
     */
    public function __construct(
        public readonly string $host,
        public readonly string $url,
        public readonly bool $frame = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            host: (string) ($data['host'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            frame: (bool) ($data['frame'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['host' => $this->host, 'url' => $this->url, 'frame' => $this->frame];
    }
}
