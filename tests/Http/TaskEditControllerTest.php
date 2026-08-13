<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\RequirementRepository;
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
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the `POST /task/{id}/edit` route (ticket 153, task 1270) through
 * the real HttpKernel, with Application pointed at the fake `bin/tm` stub
 * (see tests/Http/fixtures/fake-tm), mirroring PhaseEditControllerTest's
 * coverage for the phase-level route one layer up.
 */
final class TaskEditControllerTest extends BaseHttpTest
{
    private const string STUB = __DIR__ . '/fixtures/fake-tm';

    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;
    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();

        // BaseHttpTest builds $this->app against the real sibling ai-tm
        // binary; rebuild it here pointed at the fake stub so these tests
        // never shell out to a real ai-lib-backed database.
        $this->app = new Application($this->pdo, self::STUB);

        $clock = new SystemClock();
        $config = Config::default();
        $projectRepository = new ProjectRepository($this->pdo);
        $ticketRepository = new TicketRepository($this->pdo);
        $phaseRepository = new PhaseRepository($this->pdo);
        $taskRepository = new TaskRepository($this->pdo);

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
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
            requirementRepository: new RequirementRepository($this->pdo),
        );

        $this->phaseService = new PhaseService(
            pdo: $this->pdo,
            ticketRepository: $ticketRepository,
            repository: $phaseRepository,
            recorder: new TransitionRecorder($this->pdo, $clock),
            ordering: new Ordering(),
            clock: $clock,
            config: $config,
            taskRepository: $taskRepository,
        );

        $this->taskService = new TaskService(
            pdo: $this->pdo,
            phaseRepository: $phaseRepository,
            repository: $taskRepository,
            recorder: new TransitionRecorder($this->pdo, $clock),
            ordering: new Ordering(),
            clock: $clock,
            config: $config,
        );
    }

    #[Test]
    public function it_redirects_to_the_deep_view_on_a_successful_save(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'do the thing', model: null));

        $response = $this->app->handle(Request::create(
            "/task/{$task->id}/edit",
            'POST',
            ['name' => 'New task name', 'description' => 'New description'],
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            "/?project={$project->id}&ticket={$ticket->id}",
            $response->headers->get('Location'),
        );
    }

    #[Test]
    public function it_redirects_to_the_deep_view_when_editing_the_actor(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'do the thing', model: null));

        $response = $this->app->handle(Request::create(
            "/task/{$task->id}/edit",
            'POST',
            ['actor' => 'human'],
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            "/?project={$project->id}&ticket={$ticket->id}",
            $response->headers->get('Location'),
        );
    }

    #[Test]
    public function it_redirects_to_the_deep_view_when_editing_max_attempts(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'do the thing', model: null));

        $response = $this->app->handle(Request::create(
            "/task/{$task->id}/edit",
            'POST',
            ['max_attempts' => '5'],
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            "/?project={$project->id}&ticket={$ticket->id}",
            $response->headers->get('Location'),
        );
    }

    #[Test]
    public function it_rerenders_the_ticket_deep_page_with_the_parsed_error_message_on_failure(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'do the thing', model: null));

        // The fake stub reports failure exactly when --name is "fail-me".
        $response = $this->app->handle(Request::create(
            "/task/{$task->id}/edit",
            'POST',
            ['name' => 'fail-me', 'description' => 'typed but not saved', 'actor' => 'human', 'max_attempts' => '3'],
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('simulated failure for name', $body);
        self::assertStringNotContainsString('"ok":false', $body);
        // The just-submitted values are preserved in that task's form, not the stored ones.
        self::assertStringContainsString('fail-me', $body);
        self::assertStringContainsString('typed but not saved', $body);
        // The task panel opens already in edit mode.
        self::assertStringContainsString('data-open-task-id="' . $task->id . '"', $body);
        self::assertStringContainsString('data-open-on-load', $body);
    }

    #[Test]
    public function it_responds_404_for_an_unknown_task(): void
    {
        $response = $this->app->handle(Request::create('/task/999999/edit', 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_responds_404_when_the_tasks_phase_cannot_be_resolved(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'do the thing', model: null));

        // Orphan the task by removing its phase directly, bypassing the
        // foreign key that normal service calls would never allow to be
        // violated — this simulates the data-integrity-gap case the
        // controller's second not-found check exists to guard.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec("DELETE FROM phases WHERE id = {$phase->id}");
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $response = $this->app->handle(Request::create("/task/{$task->id}/edit", 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_responds_404_for_a_non_numeric_task_id(): void
    {
        $response = $this->app->handle(Request::create('/task/not-a-number/edit', 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_get_requests(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'do the thing', model: null));

        $response = $this->app->handle(Request::create("/task/{$task->id}/edit", 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }
}
