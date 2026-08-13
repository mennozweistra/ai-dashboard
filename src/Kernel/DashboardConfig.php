<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Kernel;

use PhpCollective\Toml\Toml;

/**
 * Reads the machine-local, read-only TOML config file at
 * `~/.ai-dashboard/config.toml`. Started with the single `ide_command` key
 * (ticket 164, requirement 202); ticket 269 requirement 684 adds `address`
 * and `port`, the defaults `bin/ai-dashboard` binds the web process to. No
 * `tm`-binary key is added — ticket 269 requirement 685 resolves that path
 * from the `ai-toolset/tm` composer dependency instead (see
 * `Application::defaultTmBinaryPath()`).
 *
 * Parsed with `PhpCollective\Toml\Toml::decodeFile()` — ai-lib's own
 * `ConfigLoader` (`../ai-lib/src/Services/ConfigLoader.php`) is not reused
 * here because it is schema-specific to `tm`'s own config file, not this
 * one.
 *
 * `public/index.php` has no long-lived boot phase — `php -S` invokes it
 * fresh on every request — so "fail loud at startup" means every request
 * fails with the parse error on malformed TOML: none of the `read*()`
 * methods below catch `PhpCollective\Toml\Exception\ParseException`, they
 * let it propagate out to the caller. `bin/ai-dashboard` reads the file
 * once at process start and gets the same loud failure before the server
 * ever binds a socket.
 */
final class DashboardConfig
{
    private function __construct()
    {
        // Static-only utility: never instantiated.
    }

    /**
     * Returns the configured command word, or `null` when the IDE feature
     * is off: the file does not exist, or `ide_command` is absent, not a
     * string, or blank. A present, non-blank value is trimmed and returned
     * as-is — this is a single command word with no arguments, so no
     * quoting or splitting is done here.
     */
    public static function readIdeCommand(string $path): ?string
    {
        $value = self::readKey($path, 'ide_command');

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Returns the configured bind address, or `null` when absent, not a
     * string, or blank — the caller falls back to its own default in that
     * case, the same permissive-on-absence behaviour as `readIdeCommand()`.
     */
    public static function readAddress(string $path): ?string
    {
        $value = self::readKey($path, 'address');

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Returns the configured port, or `null` when absent or not an integer
     * — the caller falls back to its own default in that case.
     */
    public static function readPort(string $path): ?int
    {
        $value = self::readKey($path, 'port');

        return is_int($value) ? $value : null;
    }

    public static function defaultPath(): string
    {
        return self::homeDirectory() . '/.ai-dashboard/config.toml';
    }

    private static function readKey(string $path, string $key): mixed
    {
        if (!file_exists($path)) {
            return null;
        }

        $data = Toml::decodeFile($path);

        return $data[$key] ?? null;
    }

    /**
     * The one home-directory resolver for this package: the config file above
     * and the store path in public/index.php both come from here.
     *
     * It reads the HOME environment variable with getenv() and falls back to
     * the account's passwd entry, the same order ai-tm uses. The environment
     * has to be read with getenv() rather than $_SERVER['HOME'] because the
     * built-in web server that `bin/ai-dashboard` starts never copies the
     * environment into $_SERVER: under that SAPI $_SERVER['HOME'] is null while
     * getenv('HOME') is correct. Reading $_SERVER therefore silently ignored
     * HOME and served the passwd account's database instead — the same
     * directory on a normal single-user machine, a different one in a
     * container, under a service unit, for a second account, and in any test
     * that points the dashboard at a scratch home (ticket 269 task 3661).
     */
    public static function homeDirectory(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }

        $info = posix_getpwuid(posix_getuid());
        if ($info === false) {
            throw new \RuntimeException('Could not resolve a home directory: HOME is unset and posix_getpwuid() failed.');
        }

        return $info['dir'];
    }
}
