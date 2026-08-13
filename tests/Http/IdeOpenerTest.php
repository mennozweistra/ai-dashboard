<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Http\IdeOpener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdeOpenerTest extends TestCase
{
    private const string STUB = __DIR__ . '/fixtures/fake-ide';

    #[Test]
    public function it_reports_success_when_the_command_resolves(): void
    {
        $opener = new IdeOpener(self::STUB);

        $result = $opener->open('/some/workspace');

        self::assertTrue($result->isSuccess());
        self::assertSame('', $result->message);
    }

    #[Test]
    public function it_reports_failure_naming_the_command_when_it_does_not_resolve(): void
    {
        $opener = new IdeOpener('no-such-ide-binary-xyz');

        $result = $opener->open('/some/workspace');

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('no-such-ide-binary-xyz', $result->message);
    }

    #[Test]
    public function it_returns_immediately_instead_of_waiting_for_the_editor_to_exit(): void
    {
        // The stub sleeps 3 seconds before exiting. A blocking exec() would
        // make this call take that long too. IdeOpener must launch detached
        // instead, since a GUI editor does not return until the user closes
        // it.
        $opener = new IdeOpener(self::STUB);

        $start = microtime(true);
        $opener->open('/some/workspace');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed);
    }
}
