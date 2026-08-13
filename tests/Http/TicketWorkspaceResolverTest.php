<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Http\TicketWorkspaceResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see TicketWorkspaceResolver} against a real temporary
 * directory tree (no mocks, per AGENTS.md) built and torn down per test.
 */
final class TicketWorkspaceResolverTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectPath = sys_get_temp_dir() . '/ai-dashboard-workspace-resolver-' . uniqid('', true);
        mkdir($this->projectPath . '/tickets', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->projectPath);
        parent::tearDown();
    }

    #[Test]
    public function it_resolves_to_the_single_matching_ticket_directory(): void
    {
        $ticketDir = $this->projectPath . '/tickets/ticket-42-widget-fix';
        mkdir($ticketDir);

        $resolved = new TicketWorkspaceResolver()->resolve($this->projectPath, 42);

        self::assertSame($ticketDir, $resolved);
    }

    #[Test]
    public function it_falls_back_to_the_project_root_when_no_directory_matches(): void
    {
        $resolved = new TicketWorkspaceResolver()->resolve($this->projectPath, 42);

        self::assertSame($this->projectPath, $resolved);
    }

    #[Test]
    public function it_falls_back_to_the_project_root_when_two_directories_match(): void
    {
        mkdir($this->projectPath . '/tickets/ticket-42-old-slug');
        mkdir($this->projectPath . '/tickets/ticket-42-new-slug');

        $resolved = new TicketWorkspaceResolver()->resolve($this->projectPath, 42);

        self::assertSame($this->projectPath, $resolved);
    }

    #[Test]
    public function it_does_not_confuse_a_ticket_id_with_a_shared_numeric_prefix(): void
    {
        // "ticket-4-*" must not match ticket id 42; the literal "-" right
        // after the id in the glob pattern rules that out.
        mkdir($this->projectPath . '/tickets/ticket-4-other-ticket');

        $resolved = new TicketWorkspaceResolver()->resolve($this->projectPath, 42);

        self::assertSame($this->projectPath, $resolved);
    }

    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . '/' . $entry;
            is_dir($entryPath) ? $this->removeRecursively($entryPath) : unlink($entryPath);
        }

        rmdir($path);
    }
}
