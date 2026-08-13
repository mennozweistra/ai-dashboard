<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\LogEntryRepository;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\StatusTransitionRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\PhaseIn;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\Ordering;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class EmptyStatesTest extends BaseHttpTest
{
    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new SystemClock();
        $projectRepository = new ProjectRepository($this->pdo);
        $ticketRepository = new TicketRepository($this->pdo);
        $phaseRepository = new PhaseRepository($this->pdo);
        $recorder = new TransitionRecorder($this->pdo, $clock);
        $config = Config::default();

        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: $ticketRepository,
        );

        $this->ticketService = new TicketService(
            pdo: $this->pdo,
            projectRepository: $projectRepository,
            repository: $ticketRepository,
            recorder: $recorder,
            clock: $clock,
            config: $config,
            phaseRepository: $phaseRepository,
            taskRepository: new TaskRepository($this->pdo),
        );

        $this->phaseService = new PhaseService(
            pdo: $this->pdo,
            ticketRepository: $ticketRepository,
            repository: $phaseRepository,
            recorder: $recorder,
            ordering: new Ordering(),
            clock: $clock,
            config: $config,
            taskRepository: new TaskRepository($this->pdo),
            logEntryRepository: new LogEntryRepository($this->pdo),
            statusTransitionRepository: new StatusTransitionRepository($this->pdo),
        );
    }

    #[Test]
    public function it_shows_no_projects_excluding_done_on_empty_project_list_by_default(): void
    {
        $response = $this->app->handle(Request::create('/'));

        self::assertStringContainsString('No projects (excluding done).', (string) $response->getContent());
    }

    #[Test]
    public function it_shows_no_projects_yet_when_show_done_is_on_and_list_is_empty(): void
    {
        $response = $this->app->handle(Request::create('/?show_done=1'));

        self::assertStringContainsString('No projects yet.', (string) $response->getContent());
    }

    #[Test]
    public function it_shows_no_tickets_excluding_done_when_project_has_no_tickets(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));

        $response = $this->app->handle(Request::create('/?project=' . $project->id));

        self::assertStringContainsString('No tickets (excluding done) in this project.', (string) $response->getContent());
    }

    #[Test]
    public function it_shows_no_tickets_when_project_has_no_tickets_and_show_done_is_on(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));

        $response = $this->app->handle(Request::create('/?project=' . $project->id . '&show_done=1'));

        self::assertStringContainsString('No tickets in this project.', (string) $response->getContent());
    }

    #[Test]
    public function it_shows_no_plan_yet_for_ticket_with_no_phases(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $response = $this->app->handle(Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id));

        self::assertStringContainsString('No plan yet for this ticket.', (string) $response->getContent());
    }

    #[Test]
    public function it_shows_no_tasks_placeholder_for_phase_with_no_tasks(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));
        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: setup'));

        $response = $this->app->handle(Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id));

        self::assertStringContainsString('no tasks', (string) $response->getContent());
    }

    #[Test]
    public function it_falls_through_to_project_list_for_unknown_project_param(): void
    {
        $response = $this->app->handle(Request::create('/?project=999999'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No projects (excluding done).', (string) $response->getContent());
    }

    #[Test]
    public function it_falls_through_to_ticket_list_for_unknown_ticket_param(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));

        $response = $this->app->handle(Request::create('/?project=' . $project->id . '&ticket=999999'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('show done', (string) $response->getContent());
    }
}
