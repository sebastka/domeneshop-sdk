<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Exception\DomeneshopException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A single CLI command described declaratively. Every Domeneshop command is one
 * of these, defined in {@see Commands}: a name, arguments/options, and a handler
 * closure that receives the input, an {@see Output} renderer, and a ready-built
 * client. The client is created lazily per run, `--json` is added to every
 * command automatically, and all SDK/credential errors are turned into a clean
 * one-line error instead of a stack trace.
 */
final class ApiCommand extends Command
{
    /** @var \Closure(InputInterface, Output, DomeneshopClient): int */
    private readonly \Closure $handler;

    /** @var \Closure(): DomeneshopClient */
    private \Closure $clientProvider;

    /**
     * @param array<int, array{0: string, 1: bool, 2: string}>           $arguments [name, required, description]
     * @param array<int, array{0: string, 1: 'value'|'none', 2: string}> $options   [name, kind, description]
     * @param \Closure(InputInterface, Output, DomeneshopClient): int    $handler
     */
    public function __construct(
        string $name,
        string $description,
        array $arguments,
        array $options,
        \Closure $handler,
    ) {
        parent::__construct($name);
        $this->setDescription($description);
        $this->handler = $handler;
        // Default resolves credentials from env/config file; tests inject their own provider.
        $this->clientProvider = static fn (): DomeneshopClient => ClientFactory::create();

        foreach ($arguments as [$argName, $required, $argDescription]) {
            $this->addArgument(
                $argName,
                $required ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
                $argDescription,
            );
        }

        foreach ($options as [$optName, $kind, $optDescription]) {
            $this->addOption(
                $optName,
                null,
                $kind === 'none' ? InputOption::VALUE_NONE : InputOption::VALUE_REQUIRED,
                $optDescription,
            );
        }

        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw API payload as JSON');
    }

    /** Override how the client is built (used by tests to inject a mock transport). */
    public function setClientProvider(\Closure $clientProvider): void
    {
        $this->clientProvider = $clientProvider;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $renderer = new Output($io, (bool) $input->getOption('json'));

        try {
            return ($this->handler)($input, $renderer, ($this->clientProvider)());
        } catch (DomeneshopException | \RuntimeException | \InvalidArgumentException $e) {
            // API errors, missing credentials, and bad argument values all render
            // as a clean one-line error rather than a stack trace. It goes to
            // stderr, so `--json` stdout stays machine-readable even on failure.
            $io->getErrorStyle()->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
