<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Testing\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class BaseHttpTest extends TestCase
{
    /**
     * Ticket 160 task 1431 made TicketListController call `bin/tm
     * template:list` on every ticket-list render, so every subclass that
     * exercises that route (directly or via HomeController) now shells out
     * through TmCliRunner by default, not just the dedicated edit-route
     * tests. Defaulting to the fake stub here, instead of Application's own
     * real-sibling-binary default, keeps every Http test isolated from the
     * real `~/.ai-tm/store.db`. Subclasses that need a specific tm behaviour
     * (the ticket/phase/task edit tests) already rebuild $this->app in their
     * own setUp() with an explicit stub, so this default does not change
     * their coverage.
     */
    private const string TM_STUB = __DIR__ . '/fixtures/fake-tm';

    protected Application $app;
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = InMemoryDatabase::create();
        $this->app = new Application($this->pdo, tmBinaryPath: self::TM_STUB);
    }
}
