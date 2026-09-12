<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\Cli\Commands;
use Sebastka\Domeneshop\Cli\Tests\Fixture\MockHttpClient;
use Sebastka\Domeneshop\DomeneshopClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives a real command through Symfony's CommandTester, with the SDK wired to
 * a recording PSR-18 mock — so the assertions cover the command, the SDK and
 * the request it would actually send, without touching the network.
 */
abstract class CommandTestCase extends TestCase
{
    protected MockHttpClient $http;

    /**
     * Run $name with $input, against the given canned responses.
     *
     * @param array<string, mixed> $input
     */
    protected function runCommand(string $name, array $input = [], Response ...$responses): CommandTester
    {
        if ($responses === []) {
            $responses = [new Response(204, [], '')];
        }
        $this->http = new MockHttpClient(...$responses);
        $factory = new HttpFactory();

        $provider = fn (): DomeneshopClient => new DomeneshopClient(
            'TOKEN',
            'SECRET',
            DomeneshopClient::DEFAULT_BASE_URI,
            $this->http,
            $factory,
            $factory,
        );

        $application = new Application('Domeneshop CLI (test)', 'test');
        $application->setAutoExit(false);
        $application->addCommands(Commands::all($provider));

        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    protected static function json(mixed $value, int $status = 200): Response
    {
        return new Response($status, [], (string) json_encode($value));
    }

    protected static function error(int $status, string $help): Response
    {
        return new Response($status, [], (string) json_encode(['help' => $help]));
    }

    /** Decode a command's `--json` stdout. */
    protected static function decode(CommandTester $tester): mixed
    {
        return json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    }
}
