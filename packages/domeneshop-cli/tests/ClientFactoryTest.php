<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\Cli\ClientFactory;
use Sebastka\Domeneshop\DomeneshopClient;

final class ClientFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['DOMENESHOP_TOKEN', 'DOMENESHOP_SECRET', 'DOMENESHOP_BASE_URI', 'XDG_CONFIG_HOME'] as $name) {
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

    public function testEnvironmentCredentialsBuildAClient(): void
    {
        putenv('DOMENESHOP_TOKEN=token');
        putenv('DOMENESHOP_SECRET=secret');

        self::assertInstanceOf(DomeneshopClient::class, ClientFactory::create('/nonexistent/config.ini'));
    }

    public function testConfigFileCredentialsBuildAClient(): void
    {
        $path = $this->writeConfig("token = \"from-file\"\nsecret = \"also-from-file\"\n");

        self::assertInstanceOf(DomeneshopClient::class, ClientFactory::create($path));
    }

    public function testMissingCredentialsNameBothTheEnvVarAndTheConfigFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOMENESHOP_TOKEN.+DOMENESHOP_SECRET/s');

        ClientFactory::create('/nonexistent/config.ini');
    }

    public function testAPartialConfigStillFails(): void
    {
        $path = $this->writeConfig("token = \"only-a-token\"\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOMENESHOP_SECRET/');

        ClientFactory::create($path);
    }

    public function testTheDefaultConfigPathFollowsXdg(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg');

        self::assertSame('/tmp/xdg/domeneshop/config.ini', ClientFactory::defaultConfigPath());
    }

    public function testAMalformedConfigFileIsIgnoredRatherThanFatal(): void
    {
        $path = $this->writeConfig("this is not ini [[[\n");
        putenv('DOMENESHOP_TOKEN=token');
        putenv('DOMENESHOP_SECRET=secret');

        self::assertInstanceOf(DomeneshopClient::class, ClientFactory::create($path));
    }

    private function writeConfig(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'domeneshop-test-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        // tempnam() files are not cleaned up for us.
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
