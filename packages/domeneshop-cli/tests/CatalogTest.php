<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\Cli\Application;
use Sebastka\Domeneshop\Cli\Commands;

/** Structural checks over the whole command catalogue. */
final class CatalogTest extends TestCase
{
    public function testEveryApiOperationHasACommand(): void
    {
        $names = array_map(static fn ($c): string => (string) $c->getName(), Commands::all());

        self::assertEqualsCanonicalizing([
            'domains:list', 'domains:get',
            'dns:list', 'dns:get', 'dns:create', 'dns:update', 'dns:delete',
            'forwards:list', 'forwards:get', 'forwards:create', 'forwards:update', 'forwards:delete',
            'invoices:list', 'invoices:get',
            'dyndns:update',
        ], $names);
    }

    public function testEveryCommandDescribesItself(): void
    {
        foreach (Commands::all() as $command) {
            self::assertNotSame('', $command->getDescription(), $command->getName() . ' has no description');

            foreach ($command->getDefinition()->getArguments() as $argument) {
                self::assertNotSame(
                    '',
                    $argument->getDescription(),
                    \sprintf('%s: argument "%s" has no description', $command->getName(), $argument->getName()),
                );
            }
            foreach ($command->getDefinition()->getOptions() as $option) {
                self::assertNotSame(
                    '',
                    $option->getDescription(),
                    \sprintf('%s: option "--%s" has no description', $command->getName(), $option->getName()),
                );
            }
        }
    }

    public function testEveryCommandAcceptsJson(): void
    {
        foreach (Commands::all() as $command) {
            self::assertTrue(
                $command->getDefinition()->hasOption('json'),
                $command->getName() . ' does not support --json',
            );
        }
    }

    public function testTheApplicationRegistersTheCatalogue(): void
    {
        $application = new Application();

        self::assertTrue($application->has('dns:create'));
        self::assertSame('Domeneshop CLI', $application->getName());
    }
}
