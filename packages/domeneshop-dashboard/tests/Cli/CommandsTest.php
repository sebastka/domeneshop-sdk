<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Cli;

use GuzzleHttp\Psr7\HttpFactory;
use Sebastka\Domeneshop\Dashboard\Cli\Commands;
use Sebastka\Domeneshop\Dashboard\Cli\CookieFactory;
use Sebastka\Domeneshop\Dashboard\DashboardClient;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;
use Sebastka\Domeneshop\Dashboard\Tests\Fixture\MockHttpClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends DashboardTestCase
{
    /** @param \Closure(): DashboardClient $provider */
    private function tester(string $name, \Closure $provider): CommandTester
    {
        $application = new Application('test', 'test');
        $application->setAutoExit(false);
        $application->addCommands(Commands::all($provider));

        return new CommandTester($application->find($name));
    }

    private function provider(MockHttpClient $http): \Closure
    {
        return static fn (): DashboardClient => new DashboardClient(self::COOKIE, DashboardClient::DEFAULT_BASE_URI, $http, new HttpFactory());
    }

    public function testEveryCommandIsUnderTheDashboardPrefix(): void
    {
        foreach (Commands::all(static fn () => null) as $command) {
            self::assertStringStartsWith('dashboard:', (string) $command->getName());
        }
    }

    public function testCookieReportsAWorkingSession(): void
    {
        $tester = $this->tester('dashboard:cookie', $this->provider(new MockHttpClient(self::page())));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('works', $tester->getDisplay());
    }

    public function testCookieExplainsCaptureWhenTheSessionHasExpired(): void
    {
        $tester = $this->tester('dashboard:cookie', $this->provider(new MockHttpClient(self::loginPage())));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not accepted', $tester->getDisplay());
        self::assertStringContainsString('To capture a session cookie', $tester->getDisplay());
    }

    public function testCookieExplainsCaptureWhenNoneIsConfigured(): void
    {
        $tester = $this->tester('dashboard:cookie', static function (): DashboardClient {
            throw new \RuntimeException('No dashboard session cookie found.');
        });
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No dashboard session cookie found', $tester->getDisplay());
        self::assertStringContainsString('WebGL', $tester->getDisplay(), 'should explain why to use a real browser');
    }

    private function fixtureTester(string $command, string $fixture): CommandTester
    {
        return $this->tester($command, $this->provider(new MockHttpClient(self::rawPage(self::fixture($fixture)))));
    }

    public function testNameservers(): void
    {
        $tester = $this->fixtureTester('dashboard:nameservers', 'nameservers');
        $tester->execute(['domain-id' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ns1.hyp.net, ns2.hyp.net, ns3.hyp.net', $tester->getDisplay());
        self::assertMatchesRegularExpression('/DNSSEC\s*\|?\s*yes/', $tester->getDisplay());
    }

    public function testNameserversAsJson(): void
    {
        $tester = $this->fixtureTester('dashboard:nameservers', 'nameservers-external');
        $tester->execute(['domain-id' => '1', '--json' => true]);

        self::assertSame(['hosts' => ['ns1.example.net', 'ns2.example.net'], 'dnssec' => true], json_decode($tester->getDisplay(), true));
    }

    public function testDnssec(): void
    {
        $tester = $this->fixtureTester('dashboard:dnssec', 'dnssec');
        $tester->execute(['domain-id' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2371', $tester->getDisplay());
        self::assertStringContainsString('0123456789abcdef', $tester->getDisplay());
    }

    public function testDnssecAsJson(): void
    {
        $tester = $this->fixtureTester('dashboard:dnssec', 'dnssec');
        $tester->execute(['domain-id' => '1', '--json' => true]);
        $json = json_decode($tester->getDisplay(), true);

        self::assertIsArray($json);
        self::assertSame(['enabled' => true, 'managed_by_domeneshop' => false], ['enabled' => $json['enabled'], 'managed_by_domeneshop' => $json['managed_by_domeneshop']]);
        self::assertSame(2371, $json['records'][0]['keytag']);
    }

    public function testDnssecOnDomeneshopNameserversReportsTheStateItCanSee(): void
    {
        $tester = $this->tester('dashboard:dnssec', $this->provider(new MockHttpClient(
            self::rawPage(self::fixture('dnssec-unavailable')),
            self::rawPage(self::fixture('nameservers')),
        )));
        $tester->execute(['domain-id' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('managed by Domeneshop', $tester->getDisplay());
        self::assertMatchesRegularExpression('/DNSSEC\s*\|?\s*yes/', $tester->getDisplay());
    }

    /** `records: null` is the whole point: it cannot be read as "no DS records". */
    public function testDnssecOnDomeneshopNameserversAsJson(): void
    {
        $tester = $this->tester('dashboard:dnssec', $this->provider(new MockHttpClient(
            self::rawPage(self::fixture('dnssec-unavailable')),
            self::rawPage(self::fixture('nameservers')),
        )));
        $tester->execute(['domain-id' => '1', '--json' => true]);
        $json = json_decode($tester->getDisplay(), true);

        self::assertSame(['enabled' => true, 'managed_by_domeneshop' => true, 'records' => null], $json);
    }

    public function testGlue(): void
    {
        $tester = $this->fixtureTester('dashboard:glue', 'glue');
        $tester->execute(['domain-id' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No glue records.', $tester->getDisplay());
    }

    public function testContactsShowsOnlyTheFieldsTheTldHas(): void
    {
        $tester = $this->fixtureTester('dashboard:contacts', 'contacts-no');
        $tester->execute(['domain-id' => '1']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Owner org number', $display);
        self::assertStringContainsString('Billing email', $display);
        self::assertStringNotContainsString('Owner fax', $display);
        self::assertStringNotContainsString('Admin', $display);
    }

    public function testContactsAsJson(): void
    {
        $tester = $this->fixtureTester('dashboard:contacts', 'contacts-generic');
        $tester->execute(['domain-id' => '1', '--json' => true]);
        $json = json_decode($tester->getDisplay(), true);

        self::assertIsArray($json);
        self::assertSame('per@example.com', $json['tech']['email']);
        self::assertTrue($json['hide_personal_data']);
    }

    public function testReadCommandsRejectANonNumericDomainId(): void
    {
        foreach (['dashboard:nameservers', 'dashboard:dnssec', 'dashboard:glue', 'dashboard:contacts'] as $name) {
            $http = new MockHttpClient();
            $tester = $this->tester($name, $this->provider($http));
            $tester->execute(['domain-id' => 'example.no']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $name);
            self::assertSame(0, $http->callCount(), $name);
        }
    }

    public function testFetchPrintsTheRawHtml(): void
    {
        $html = self::signedIn('<p>contact page</p>');
        $tester = $this->tester('dashboard:fetch', $this->provider(new MockHttpClient(self::page('<p>contact page</p>'))));

        ob_start();
        $tester->execute(['domain-id' => '1234567', 'page' => 'contacts']);
        $printed = (string) ob_get_clean();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($html, $printed);
    }

    public function testFetchRejectsANonNumericDomainId(): void
    {
        $http = new MockHttpClient(self::page());
        $tester = $this->tester('dashboard:fetch', $this->provider($http));
        $tester->execute(['domain-id' => 'example.no', 'page' => 'contacts']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $http->callCount());
    }

    public function testFetchReportsAnExpiredSessionCleanly(): void
    {
        $tester = $this->tester('dashboard:fetch', $this->provider(new MockHttpClient(self::loginPage())));
        $tester->execute(['domain-id' => '1', 'page' => 'contacts'], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Not signed in', $tester->getErrorOutput());
        self::assertStringNotContainsString('secret-session-value', $tester->getErrorOutput());
    }
}
