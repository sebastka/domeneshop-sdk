<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Sebastka\Domeneshop\DomeneshopClient;

/**
 * Builds a {@see DomeneshopClient} from configuration, resolved in this order
 * (first non-empty wins):
 *
 *   1. Environment variables  DOMENESHOP_TOKEN / DOMENESHOP_SECRET / DOMENESHOP_BASE_URI
 *   2. INI config file        ~/.config/domeneshop/config.ini  (or $XDG_CONFIG_HOME/domeneshop/config.ini)
 *   3. Built-in default       (base URI only)
 *
 * The config file is a flat INI document:
 *
 *   token           = "your-api-token"
 *   secret          = "your-api-secret"
 *   base_uri        = "https://api.domeneshop.no/v0"
 *   timeout         = 30
 *   connect_timeout = 10
 *
 * Timeouts are in seconds (0 disables); they fall back to the client defaults.
 * The matching env overrides are DOMENESHOP_TIMEOUT / DOMENESHOP_CONNECT_TIMEOUT.
 *
 * Credentials are never taken from CLI flags, so they stay out of shell history
 * and `ps` output.
 */
final class ClientFactory
{
    /**
     * @param string|null $configFile Override the config-file path (mainly for tests);
     *                                null uses the default XDG location.
     */
    public static function create(?string $configFile = null): DomeneshopClient
    {
        $configFile ??= self::defaultConfigPath();
        $config = self::readConfig($configFile);

        $token = self::env('DOMENESHOP_TOKEN') ?? self::stringOrNull($config['token'] ?? null);
        $secret = self::env('DOMENESHOP_SECRET') ?? self::stringOrNull($config['secret'] ?? null);

        $missing = [];
        if ($token === null) {
            $missing[] = 'DOMENESHOP_TOKEN (or `token` in the config file)';
        }
        if ($secret === null) {
            $missing[] = 'DOMENESHOP_SECRET (or `secret` in the config file)';
        }
        if ($missing !== []) {
            throw new \RuntimeException(\sprintf(
                "No Domeneshop credentials found. Missing: %s.\n"
                . "Config file: %s\n"
                . 'Generate a token and secret at https://www.domeneshop.no/admin?view=api',
                implode(', ', $missing),
                $configFile !== '' ? $configFile : '~/.config/domeneshop/config.ini',
            ));
        }

        $baseUri = self::env('DOMENESHOP_BASE_URI') ?? self::stringOrNull($config['base_uri'] ?? null);

        return new DomeneshopClient(
            $token,
            $secret,
            $baseUri ?? DomeneshopClient::DEFAULT_BASE_URI,
            timeout: self::seconds('DOMENESHOP_TIMEOUT', $config['timeout'] ?? null, DomeneshopClient::DEFAULT_TIMEOUT),
            connectTimeout: self::seconds('DOMENESHOP_CONNECT_TIMEOUT', $config['connect_timeout'] ?? null, DomeneshopClient::DEFAULT_CONNECT_TIMEOUT),
        );
    }

    /** Default config path: $XDG_CONFIG_HOME/domeneshop/config.ini, falling back to ~/.config. */
    public static function defaultConfigPath(): string
    {
        $base = self::env('XDG_CONFIG_HOME');
        if ($base === null) {
            $home = self::env('HOME');
            if ($home === null) {
                return '';
            }
            $base = $home . '/.config';
        }

        return $base . '/domeneshop/config.ini';
    }

    /** @return array<string, mixed> */
    private static function readConfig(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        // process_sections = false flattens, so both flat and [section] files work.
        $parsed = @parse_ini_file($path, false, INI_SCANNER_NORMAL);

        return \is_array($parsed) ? $parsed : [];
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value !== false && trim($value) !== '' ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Resolve a timeout (seconds): env var first, else the config value, else the
     * default. Non-numeric values are ignored so a malformed entry can't break the
     * client — it just falls back.
     */
    private static function seconds(string $envName, mixed $configValue, float $default): float
    {
        $env = self::env($envName);
        if ($env !== null && is_numeric($env)) {
            return (float) $env;
        }
        if (is_numeric($configValue)) {
            return (float) $configValue;
        }

        return $default;
    }
}
