<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Model\Domain;

/**
 * Base class for tests that talk to the real API.
 *
 * These are excluded from the default suite (see phpunit.xml) and gated twice
 * over: read-only tests need credentials, and mutating tests additionally need
 * an explicit opt-in plus a named domain. Nothing here runs by accident.
 */
#[Group('contract')]
abstract class ContractTestCase extends TestCase
{
    /** Every host these tests create carries this prefix, so it is always an obvious throwaway. */
    public const TEST_HOST_PREFIX = 'sdk-test-';

    protected function client(): DomeneshopClient
    {
        $token = self::env('DOMENESHOP_TOKEN');
        $secret = self::env('DOMENESHOP_SECRET');

        if ($token === null || $secret === null) {
            self::markTestSkipped('Set DOMENESHOP_TOKEN and DOMENESHOP_SECRET to run the contract suite.');
        }

        return new DomeneshopClient($token, $secret, self::env('DOMENESHOP_BASE_URI') ?? DomeneshopClient::DEFAULT_BASE_URI);
    }

    /**
     * The domain the mutating tests are allowed to write to.
     *
     * Skips unless DOMENESHOP_CONTRACT_WRITE=1 and DOMENESHOP_CONTRACT_DOMAIN
     * name a domain in the account — so a plain `composer test:contract` with
     * credentials never modifies anything.
     */
    protected function writableDomain(): Domain
    {
        if (self::env('DOMENESHOP_CONTRACT_WRITE') !== '1') {
            self::markTestSkipped('Set DOMENESHOP_CONTRACT_WRITE=1 to run the mutating contract tests.');
        }

        $name = self::env('DOMENESHOP_CONTRACT_DOMAIN');
        if ($name === null) {
            self::markTestSkipped('Set DOMENESHOP_CONTRACT_DOMAIN to a domain these tests may write to.');
        }

        $domain = $this->client()->domains()->findByName($name);
        if ($domain === null) {
            self::fail(\sprintf('DOMENESHOP_CONTRACT_DOMAIN is "%s", which is not a domain in this account.', $name));
        }

        return $domain;
    }

    /** A collision-proof host for one test, e.g. "sdk-test-6f2a1b". */
    protected static function testHost(): string
    {
        return self::TEST_HOST_PREFIX . bin2hex(random_bytes(3));
    }

    protected static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value !== false && trim($value) !== '' ? $value : null;
    }
}
