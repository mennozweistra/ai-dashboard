<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\LogEntryRepository;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\RequirementRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the `POST /ticket/{id}/open-ide` route (ticket 164, requirement
 * 201) through the real HttpKernel, with Application pointed at the fake IDE
 * stub instead of a real GUI editor (see tests/Http/fixtures/fake-ide).
 */
final class TicketIdeControllerTest extends BaseHttpTest
{
    private const string TM_STUB = __DIR__ . '/fixtures/fake-tm';
    private const string IDE_STUB = __DIR__ . '/fixtures/fake-ide';

    private ProjectService $projectService;
    private TicketService $ticketService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application($this->pdo, self::TM_STUB, ideCommand: self::IDE_STUB);

        $clock = new SystemClock();
        $config = Config::default();
        $projectRepository = new ProjectRepository($this->pdo);
        $ticketRepository = new TicketRepository($this->pdo);

        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: $ticketRepository,
        );

        $this->ticketService = new TicketService(
            pdo: $this->pdo,
            projectRepository: $projectRepository,
            repository: $ticketRepository,
            recorder: new TransitionRecorder($this->pdo, $clock),
            clock: $clock,
            config: $config,
            phaseRepository: new PhaseRepository($this->pdo),
            taskRepository: new TaskRepository($this->pdo),
            logEntryRepository: new LogEntryRepository($this->pdo),
            requirementRepository: new RequirementRepository($this->pdo),
        );
    }

    #[Test]
    public function it_responds_204_with_an_empty_body_when_the_launch_is_dispatched(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));

        $response = $this->app->handle(Request::create("/ticket/{$ticket->id}/open-ide", 'POST'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function it_responds_500_naming_the_command_when_it_does_not_resolve(): void
    {
        $this->app = new Application($this->pdo, self::TM_STUB, ideCommand: 'no-such-ide-binary-xyz');

        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));

        $response = $this->app->handle(Request::create("/ticket/{$ticket->id}/open-ide", 'POST'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-such-ide-binary-xyz', (string) $response->getContent());
    }

    #[Test]
    public function it_responds_404_when_no_ide_command_is_configured(): void
    {
        // No ideCommand argument: the route is not registered at all
        // (requirement 201), so this is an unknown-route 404, not a
        // controller-level rejection.
        $this->app = new Application($this->pdo, self::TM_STUB);

        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));

        $response = $this->app->handle(Request::create("/ticket/{$ticket->id}/open-ide", 'POST'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_responds_404_for_an_unknown_ticket(): void
    {
        $response = $this->app->handle(Request::create('/ticket/999999/open-ide', 'POST'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_get_requests(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));

        $response = $this->app->handle(Request::create("/ticket/{$ticket->id}/open-ide", 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }
}
