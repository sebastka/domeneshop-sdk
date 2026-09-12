<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli\Tests;

use Symfony\Component\Console\Command\Command;

/** Each command driven end-to-end against a recording mock transport. */
final class CommandBehaviorTest extends CommandTestCase
{
    public function testDomainsListRendersATable(): void
    {
        $tester = $this->runCommand('domains:list', [], self::json([[
            'id' => 1, 'domain' => 'example.com', 'status' => 'active',
            'expiry_date' => '2027-01-01', 'renew' => true,
            'services' => ['dns' => true, 'email' => false, 'webhotel' => 'none'],
        ]]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('example.com', $tester->getDisplay());
        self::assertStringContainsString('active', $tester->getDisplay());
        self::assertSame('/v0/domains', parse_url($this->http->lastUri(), PHP_URL_PATH));
    }

    public function testDomainsListSaysSoWhenEmpty(): void
    {
        $tester = $this->runCommand('domains:list', [], self::json([]));

        self::assertStringContainsString('No domains found.', $tester->getDisplay());
    }

    public function testJsonOutputIsTheRawApiPayload(): void
    {
        $payload = [['id' => 1, 'domain' => 'example.com', 'an_unmodelled_field' => 'kept']];
        $tester = $this->runCommand('domains:list', ['--json' => true], self::json($payload));

        self::assertSame($payload, self::decode($tester));
    }

    public function testDomainsListForwardsTheFilter(): void
    {
        $this->runCommand('domains:list', ['--domain' => 'example'], self::json([]));

        parse_str((string) parse_url($this->http->lastUri(), PHP_URL_QUERY), $query);
        self::assertSame(['domain' => 'example'], $query);
    }

    public function testDomainsGetRejectsANonNumericId(): void
    {
        $tester = $this->runCommand('domains:get', ['domain-id' => 'example.com'], self::json([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must be a number', $tester->getErrorOutput());
        self::assertSame(0, $this->http->callCount(), 'No request should be made for invalid input');
    }

    public function testDnsListRendersTypeSpecificExtras(): void
    {
        $tester = $this->runCommand('dns:list', ['domain-id' => '1'], self::json([
            ['id' => 4, 'host' => '@', 'ttl' => 3600, 'type' => 'MX', 'data' => 'mx.example.com', 'priority' => 10],
        ]));

        self::assertStringContainsString('MX', $tester->getDisplay());
        self::assertStringContainsString('priority=10', $tester->getDisplay());
    }

    public function testDnsListRejectsAnUnknownType(): void
    {
        $tester = $this->runCommand('dns:list', ['domain-id' => '1', '--type' => 'NAPTR'], self::json([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown record type "NAPTR"', $tester->getErrorOutput());
    }

    public function testDnsCreateSendsTheRecordAndReportsTheNewId(): void
    {
        $tester = $this->runCommand('dns:create', [
            'domain-id' => '1', 'type' => 'a', 'host' => 'www', 'data' => '203.0.113.10', '--ttl' => '300',
        ], self::json(['id' => 55], 201));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Created DNS record 55', $tester->getDisplay());
        self::assertSame(
            ['host' => 'www', 'type' => 'A', 'ttl' => 300, 'data' => '203.0.113.10'],
            json_decode((string) $this->http->lastRequest()->getBody(), true),
        );
    }

    public function testDnsCreateNamesTheFlagAMxRecordIsMissing(): void
    {
        $tester = $this->runCommand('dns:create', [
            'domain-id' => '1', 'type' => 'MX', 'host' => '@', 'data' => 'mx.example.com',
        ], self::json(['id' => 1], 201));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A MX record requires --priority', $tester->getErrorOutput());
        self::assertSame(0, $this->http->callCount());
    }

    public function testDnsCreateNamesTheFlagsAnSrvRecordIsMissing(): void
    {
        $tester = $this->runCommand('dns:create', [
            'domain-id' => '1', 'type' => 'SRV', 'host' => '_sip._tcp', 'data' => 'sip.example.com',
            '--priority' => '10', '--weight' => '100',
        ], self::json(['id' => 1], 201));

        self::assertStringContainsString('A SRV record requires --port', $tester->getErrorOutput());
    }

    public function testDnsCreateRejectsAnInvalidTtlBeforeSendingAnything(): void
    {
        $tester = $this->runCommand('dns:create', [
            'domain-id' => '1', 'type' => 'A', 'host' => '@', 'data' => '203.0.113.10', '--ttl' => '90',
        ], self::json(['id' => 1], 201));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('multiple of 60', $tester->getErrorOutput());
        self::assertSame(0, $this->http->callCount());
    }

    public function testDnsDeleteIssuesADeleteRequest(): void
    {
        $tester = $this->runCommand('dns:delete', ['domain-id' => '1', 'record-id' => '9']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('DELETE', $this->http->lastRequest()->getMethod());
        self::assertSame('/v0/domains/1/dns/9', parse_url($this->http->lastUri(), PHP_URL_PATH));
    }

    public function testForwardsCreateSendsTheForward(): void
    {
        $tester = $this->runCommand('forwards:create', [
            'domain-id' => '1', 'host' => 'www', 'url' => 'https://example.com',
        ], self::json(null, 201));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            ['host' => 'www', 'url' => 'https://example.com', 'frame' => false],
            json_decode((string) $this->http->lastRequest()->getBody(), true),
        );
    }

    public function testForwardsCreateHonoursTheFrameFlag(): void
    {
        $this->runCommand('forwards:create', [
            'domain-id' => '1', 'host' => 'www', 'url' => 'https://example.com', '--frame' => true,
        ], self::json(null, 201));

        $body = json_decode((string) $this->http->lastRequest()->getBody(), true);
        self::assertTrue($body['frame']);
    }

    public function testInvoicesListForwardsTheStatusFilter(): void
    {
        $this->runCommand('invoices:list', ['--status' => 'UNPAID'], self::json([]));

        parse_str((string) parse_url($this->http->lastUri(), PHP_URL_QUERY), $query);
        self::assertSame(['status' => 'unpaid'], $query);
    }

    public function testInvoicesListRejectsAnUnknownStatus(): void
    {
        $tester = $this->runCommand('invoices:list', ['--status' => 'overdue'], self::json([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown invoice status "overdue"', $tester->getErrorOutput());
    }

    public function testDyndnsUpdateWithoutAnAddressUsesTheClientIp(): void
    {
        $tester = $this->runCommand('dyndns:update', ['hostname' => 'home.example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        parse_str((string) parse_url($this->http->lastUri(), PHP_URL_QUERY), $query);
        self::assertSame(['hostname' => 'home.example.com'], $query);
    }

    public function testDyndnsUpdateSendsAnExplicitAddress(): void
    {
        $this->runCommand('dyndns:update', ['hostname' => 'home.example.com', '--myip' => '203.0.113.10']);

        parse_str((string) parse_url($this->http->lastUri(), PHP_URL_QUERY), $query);
        self::assertSame('203.0.113.10', $query['myip']);
    }

    public function testAnApiErrorBecomesAOneLineFailure(): void
    {
        $tester = $this->runCommand('domains:get', ['domain-id' => '404'], self::error(404, 'Domain not found'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Domain not found', $tester->getErrorOutput());
        self::assertStringNotContainsString('Stack trace', $tester->getErrorOutput());
    }

    public function testErrorsGoToStderrSoJsonStdoutStaysParseable(): void
    {
        $tester = $this->runCommand('domains:list', ['--json' => true], self::error(500, 'boom'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', trim($tester->getDisplay()));
        self::assertStringContainsString('boom', $tester->getErrorOutput());
    }
}
