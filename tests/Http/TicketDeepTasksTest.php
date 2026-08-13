<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\PhaseIn;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\TaskIn;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\Ordering;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TaskService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class TicketDeepTasksTest extends BaseHttpTest
{
    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;
    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = new SystemClock();
        $config = Config::default();
        $projectRepository = new ProjectRepository($this->pdo);
        $ticketRepository = new TicketRepository($this->pdo);
        $phaseRepository = new PhaseRepository($this->pdo);
        $taskRepository = new TaskRepository($this->pdo);
        $recorder = new TransitionRecorder($this->pdo, $clock);
        $ordering = new Ordering();

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
            taskRepository: $taskRepository,
        );
        $this->phaseService = new PhaseService(
            pdo: $this->pdo,
            ticketRepository: $ticketRepository,
            repository: $phaseRepository,
            recorder: $recorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
            taskRepository: $taskRepository,
        );
        $this->taskService = new TaskService(
            pdo: $this->pdo,
            phaseRepository: $phaseRepository,
            repository: $taskRepository,
            recorder: $recorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
        );
    }

    #[Test]
    public function it_renders_task_in_phase(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'The ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Setup'));
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'My task', model: null));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('My task', (string) $response->getContent());
    }

    #[Test]
    public function it_renders_no_tasks_placeholder_when_phase_has_no_tasks(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Empty'));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no tasks', (string) $response->getContent());
    }

    #[Test]
    public function it_renders_task_row_markup_for_side_panel(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'A task', model: null));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $body = (string) $response->getContent();

        self::assertStringContainsString('class="task"', $body);
        self::assertStringContainsString('data-task-id="', $body);
        self::assertStringContainsString('id="task-panel"', $body);
    }

    #[Test]
    public function it_renders_no_tasks_element_with_phase_empty_class(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Empty'));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        // The "no tasks" element carries the phase-empty class so it sits in column 2
        // of the phase-body grid, aligned with the phase name above.
        self::assertMatchesRegularExpression('/<div\s[^>]*class="[^"]*phase-empty[^"]*"[^>]*>no tasks<\/div>/', $html);
    }

    #[Test]
    public function it_renders_phase_description_inline_in_phase_body_when_present(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $this->phaseService->add(new PhaseIn(
            ticketId: $ticket->id,
            name: 'Phase 1: Work',
            description: 'This phase sets up the foundation.',
        ));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // The description text is rendered inline as a phase-desc div inside the phase body,
        // fixed-truncated via the shared text-clamp class (ticket 153 task 1271).
        self::assertStringContainsString('<div class="phase-desc text-clamp">This phase sets up the foundation.</div>', $html);
        // The deprecated data-desc tooltip attribute must not appear.
        self::assertStringNotContainsString('data-desc=', $html);
    }

    #[Test]
    public function it_does_not_render_phase_desc_element_when_description_is_empty(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $this->phaseService->add(new PhaseIn(
            ticketId: $ticket->id,
            name: 'Phase 1: Work',
            description: '',
            aiDescription: '',
        ));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // No phase-desc element renders when description is empty.
        self::assertStringNotContainsString('class="phase-desc"', $html);
        // And no data-desc tooltip attribute either.
        self::assertStringNotContainsString('data-desc=', $html);
    }

    #[Test]
    public function it_renders_attempts_in_task_panel_when_max_attempts_is_set(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Retry task', model: null, maxAttempts: 3));
        $this->taskService->set($task->id, attempts: 1);

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // The attempts row (1/3) must appear in the task panel template payload.
        self::assertStringContainsString('1/3', $html);
        self::assertStringContainsString('Attempts', $html);
    }

    #[Test]
    public function it_does_not_render_attempts_row_when_max_attempts_is_zero(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Plain task', model: null));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // No attempts row when max_attempts is 0.
        self::assertStringNotContainsString('Attempts', $html);
    }

    #[Test]
    public function it_renders_attempts_in_phase_panel_and_summary_when_max_attempts_is_set(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work', maxAttempts: 3));
        $this->phaseService->set($phase->id, attempts: 1);

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // The phase-attempts marker in the summary row uses "runs", not "attempts".
        self::assertStringContainsString('<span class="phase-attempts">(1/3 runs)</span>', $html);
        // The Attempts/Max attempts rows must appear in the phase panel template payload.
        self::assertStringContainsString('Max attempts', $html);
        self::assertStringContainsString('1/3', $html);
    }

    #[Test]
    public function it_does_not_render_phase_attempts_when_max_attempts_is_zero(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'Ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Plain'));

        $request = Request::create('/?project=' . $project->id . '&ticket=' . $ticket->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('phase-attempts', $html);
        self::assertStringNotContainsString('Max attempts', $html);
    }
}
