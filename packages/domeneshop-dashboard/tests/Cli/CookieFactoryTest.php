<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\Dashboard\Cli\CookieFactory;
use Sebastka\Domeneshop\Dashboard\DashboardClient;

final class CookieFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['DOMENESHOP_DASHBOARD_COOKIE', 'DOMENESHOP_DASHBOARD_BASE_URI', 'XDG_CONFIG_HOME'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv("$name=$value");
        }
    }

    public function testTheEnvironmentVariableBuildsAClient(): void
    {
        putenv('DOMENESHOP_DASHBOARD_COOKIE=sessionid=abc');

        self::assertInstanceOf(DashboardClient::class, CookieFactory::create('/nonexistent/config.ini'));
    }

    public function testTheConfigFileBuildsAClient(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ds-dash-');
        file_put_contents($path, "dashboard_cookie = \"sessionid=abc\"\n");
        register_shutdown_function(static fn () => @unlink($path));

        self::assertInstanceOf(DashboardClient::class, CookieFactory::create($path));
    }

    public function testAMissingCookieExplainsWhereToPutOne(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOMENESHOP_DASHBOARD_COOKIE.*dashboard_cookie/s');

        CookieFactory::create('/nonexistent/config.ini');
    }

    /** One config file for both API and dashboard credentials, not two. */
    public function testItSharesTheApiClisConfigLocation(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg');

        self::assertSame(
            \Sebastka\Domeneshop\Cli\ClientFactory::defaultConfigPath(),
            CookieFactory::defaultConfigPath(),
        );
    }
}
