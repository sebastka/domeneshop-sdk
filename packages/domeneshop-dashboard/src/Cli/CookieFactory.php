<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Cli;

use Sebastka\Domeneshop\Dashboard\DashboardClient;

/**
 * Resolves the dashboard session cookie, in the same order and from the same
 * places as the API client resolves its token and secret:
 *
 *   1. Environment variable  DOMENESHOP_DASHBOARD_COOKIE
 *   2. INI config file       ~/.config/domeneshop/config.ini  (`dashboard_cookie`)
 *
 * It is never taken from a command-line flag, so it stays out of shell history
 * and `ps` output — the same rule the API credentials follow, and a stricter
 * one matters more here: this cookie is the whole account, not a scoped token.
 */
final class CookieFactory
{
    public static function create(?string $configFile = null): DashboardClient
    {
        $configFile ??= self::defaultConfigPath();
        $config = self::readConfig($configFile);

        $cookie = self::env('DOMENESHOP_DASHBOARD_COOKIE')
            ?? self::stringOrNull($config['dashboard_cookie'] ?? null);

        if ($cookie === null) {
            throw new \RuntimeException(\sprintf(
                "No dashboard session cookie found.\n"
                . "Set DOMENESHOP_DASHBOARD_COOKIE, or add `dashboard_cookie = \"...\"` to %s.\n"
                . 'Run `domeneshop dashboard:cookie` for how to capture one.',
                $configFile !== '' ? $configFile : '~/.config/domeneshop/config.ini',
            ));
        }

        $baseUri = self::env('DOMENESHOP_DASHBOARD_BASE_URI')
            ?? self::stringOrNull($config['dashboard_base_uri'] ?? null);

        return new DashboardClient($cookie, $baseUri ?? DashboardClient::DEFAULT_BASE_URI);
    }

    /** Matches the API CLI's config location exactly, so there is one file to manage. */
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
}
