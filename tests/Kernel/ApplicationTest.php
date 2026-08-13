<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Kernel;

use AiToolset\AiDashboard\Kernel\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ticket 269, requirements 684/685: the dashboard finds `tm` through its
 * `ai-toolset/tm` composer dependency rather than a sibling-checkout guess
 * (the old `dirname(__DIR__, 3) . '/ai-tm/bin/tm'`, which only existed in
 * the development layout and made every write route silently unusable
 * under a composer install). This test runs against whatever layout this
 * test suite itself is running in — the worktree's own composer install —
 * and only asserts the resolved path is real, so it catches a resolution
 * that returns a plausible-looking but nonexistent path in either layout.
 */
final class ApplicationTest extends TestCase
{
    #[Test]
    public function it_resolves_the_tm_binary_to_a_file_that_exists(): void
    {
        $path = Application::defaultTmBinaryPath();

        self::assertFileExists($path);
        self::assertStringEndsWith('/bin/tm', $path);
    }
}
