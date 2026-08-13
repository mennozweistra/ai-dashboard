<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Kernel;

use AiToolset\AiDashboard\Kernel\DashboardConfig;
use PhpCollective\Toml\Exception\ParseException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see DashboardConfig} against real temporary files (no mocks,
 * per AGENTS.md). `readIdeCommand()` covers the behaviours the by-hand
 * verification step in ticket 164 task 1591 asked for — file absent, key
 * absent, valid, malformed. `readAddress()` and `readPort()` cover the same
 * shape for ticket 269 requirement 684's two new keys. None of this touches
 * the real `~/.ai-dashboard/config.toml`, which is out of scope for this
 * run.
 */
final class DashboardConfigTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/ai-dashboard-config-' . uniqid('', true) . '.toml';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_returns_null_ide_command_when_the_file_does_not_exist(): void
    {
        self::assertNull(DashboardConfig::readIdeCommand($this->path));
    }

    #[Test]
    public function it_returns_null_when_ide_command_is_absent(): void
    {
        file_put_contents($this->path, "other_key = \"value\"\n");

        self::assertNull(DashboardConfig::readIdeCommand($this->path));
    }

    #[Test]
    public function it_returns_null_when_ide_command_is_blank(): void
    {
        file_put_contents($this->path, "ide_command = \"\"\n");

        self::assertNull(DashboardConfig::readIdeCommand($this->path));
    }

    #[Test]
    public function it_returns_the_command_when_present_and_valid(): void
    {
        file_put_contents($this->path, "ide_command = \"phpstorm\"\n");

        self::assertSame('phpstorm', DashboardConfig::readIdeCommand($this->path));
    }

    #[Test]
    public function it_lets_a_parse_exception_propagate_for_malformed_toml(): void
    {
        file_put_contents($this->path, "ide_command = this is not valid toml\n");

        $this->expectException(ParseException::class);

        DashboardConfig::readIdeCommand($this->path);
    }

    #[Test]
    public function it_returns_null_address_when_the_file_does_not_exist(): void
    {
        self::assertNull(DashboardConfig::readAddress($this->path));
    }

    #[Test]
    public function it_returns_null_when_address_is_absent(): void
    {
        file_put_contents($this->path, "ide_command = \"phpstorm\"\n");

        self::assertNull(DashboardConfig::readAddress($this->path));
    }

    #[Test]
    public function it_returns_the_address_when_present_and_valid(): void
    {
        file_put_contents($this->path, "address = \"0.0.0.0\"\n");

        self::assertSame('0.0.0.0', DashboardConfig::readAddress($this->path));
    }

    #[Test]
    public function it_returns_null_port_when_the_file_does_not_exist(): void
    {
        self::assertNull(DashboardConfig::readPort($this->path));
    }

    #[Test]
    public function it_returns_null_when_port_is_absent(): void
    {
        file_put_contents($this->path, "ide_command = \"phpstorm\"\n");

        self::assertNull(DashboardConfig::readPort($this->path));
    }

    #[Test]
    public function it_returns_null_when_port_is_not_an_integer(): void
    {
        file_put_contents($this->path, "port = \"8766\"\n");

        self::assertNull(DashboardConfig::readPort($this->path));
    }

    #[Test]
    public function it_returns_the_port_when_present_and_valid(): void
    {
        file_put_contents($this->path, "port = 9090\n");

        self::assertSame(9090, DashboardConfig::readPort($this->path));
    }

    /**
     * The environment, not $_SERVER: the built-in web server `bin/ai-dashboard`
     * starts leaves $_SERVER['HOME'] null however the environment is set, so a
     * resolver reading $_SERVER ignores HOME and serves the passwd account's
     * database instead (ticket 269 task 3661).
     */
    #[Test]
    public function it_resolves_the_home_directory_from_the_environment_not_from_server(): void
    {
        $previousEnv = getenv('HOME');
        $previousServer = $_SERVER['HOME'] ?? null;
        putenv('HOME=/scratch/from-environment');
        $_SERVER['HOME'] = '/scratch/from-server';

        try {
            self::assertSame('/scratch/from-environment', DashboardConfig::homeDirectory());
            self::assertSame('/scratch/from-environment/.ai-dashboard/config.toml', DashboardConfig::defaultPath());
        } finally {
            $previousEnv === false ? putenv('HOME') : putenv('HOME=' . $previousEnv);
            if ($previousServer === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServer;
            }
        }
    }
}
