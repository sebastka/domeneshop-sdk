<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The rendering half of a command.
 *
 * Every command supports `--json`, so each one describes its result twice: once
 * as a table for humans, once as the raw decoded payload for scripts. Routing
 * both through this class keeps that choice out of the individual commands —
 * and keeps `--json` output free of the decoration SymfonyStyle would add.
 *
 * @internal
 */
final class Output
{
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly bool $json,
    ) {
    }

    public function isJson(): bool
    {
        return $this->json;
    }

    /**
     * Render a list of records: a table for humans, a JSON array for scripts.
     *
     * @param list<string>               $headers
     * @param list<list<mixed>>          $rows    One entry per column, in the
     *                                            same order as $headers. Values
     *                                            are rendered by Format::cell().
     * @param list<array<string, mixed>> $raw     What `--json` should emit.
     */
    public function table(array $headers, array $rows, array $raw, string $emptyMessage = 'Nothing to show.'): int
    {
        if ($this->json) {
            return $this->writeJson($raw);
        }

        if ($rows === []) {
            $this->io->writeln($emptyMessage);

            return 0;
        }

        $this->io->table(
            $headers,
            array_map(
                static fn (array $row): array => array_values(array_map(Format::cell(...), $row)),
                $rows,
            ),
        );

        return 0;
    }

    /**
     * Render a single record as a key/value table.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $raw
     */
    public function detail(array $fields, array $raw): int
    {
        if ($this->json) {
            return $this->writeJson($raw);
        }

        $rows = [];
        foreach ($fields as $label => $value) {
            $rows[] = [$label, Format::cell($value)];
        }
        $this->io->table(['Field', 'Value'], $rows);

        return 0;
    }

    /**
     * Report a completed write.
     *
     * @param array<string, mixed> $raw Extra fields for `--json` (e.g. a new id).
     */
    public function done(string $message, array $raw = []): int
    {
        if ($this->json) {
            return $this->writeJson(['status' => 'ok', 'message' => $message] + $raw);
        }

        $this->io->success($message);

        return 0;
    }

    /** Plain text, suppressed in JSON mode so it can never corrupt the payload. */
    public function note(string $message): void
    {
        if (! $this->json) {
            $this->io->writeln($message);
        }
    }

    private function writeJson(mixed $payload): int
    {
        $this->io->writeln((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return 0;
    }
}
