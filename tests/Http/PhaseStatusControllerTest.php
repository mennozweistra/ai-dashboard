<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\RequirementRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
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

/**
 * Drives the `POST /phase/{id}/status` route (ticket 159, task 1423) through
 * the real HttpKernel, with Application pointed at the fake `bin/tm` stub
 * (see tests/Http/fixtures/fake-tm), mirroring PhaseEditControllerTest's
 * wiring for the sibling edit route.
 *
 * See TaskStatusControllerTest's class docblock for why the rollup test
 * applies the status change directly through a locally built PhaseService
 * rather than relying on the fake stub to touch the database.
 */
final class PhaseStatusControllerTest extends BaseHttpTest
{
    private const string STUB = __DIR__ . '/fixtures/fake-tm';

    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;

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
            taskRepository: new TaskRepository($this->pdo),
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
            taskRepository: new TaskRepository($this->pdo),
        );
    }

    #[Test]
    public function it_returns_the_updated_phase_and_ticket_statuses_as_json_on_success_and_omits_task(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        // Simulate what a real `bin/tm phase:set --status active` call
        // would already have persisted by the time the dashboard re-reads.
        $this->phaseService->set(id: $phase->id, status: 'active');

        $response = $this->app->handle(Request::create(
            "/phase/{$phase->id}/status",
            'POST',
            ['status' => 'active'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertSame(
            [
                'phase' => ['id' => $phase->id, 'status' => 'active'],
                'ticket' => ['id' => $ticket->id, 'status' => $ticket->status],
            ],
            $decoded,
        );
        self::assertArrayNotHasKey('task', $decoded);
    }

    #[Test]
    public function it_reflects_an_autostatus_rollup_in_the_ticket_status(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        // Simulate what a real `bin/tm phase:set --status done` call would
        // already have persisted (phase done, ticket rolled up to done
        // since it has exactly one phase) by the time the dashboard
        // re-reads.
        $this->phaseService->set(id: $phase->id, status: 'done');
        $this->ticketService->set(id: $ticket->id, status: 'done');

        $response = $this->app->handle(Request::create(
            "/phase/{$phase->id}/status",
            'POST',
            ['status' => 'done'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'phase' => ['id' => $phase->id, 'status' => 'done'],
                'ticket' => ['id' => $ticket->id, 'status' => 'done'],
            ],
            json_decode((string) $response->getContent(), true),
        );
    }

    #[Test]
    public function it_returns_409_with_the_parsed_error_and_current_statuses_on_failure(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        // The fake stub reports failure exactly when --status is "fail-me".
        $response = $this->app->handle(Request::create(
            "/phase/{$phase->id}/status",
            'POST',
            ['status' => 'fail-me'],
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(
            [
                'error' => 'simulated failure for status',
                'phase' => ['id' => $phase->id, 'status' => $phase->status],
                'ticket' => ['id' => $ticket->id, 'status' => $ticket->status],
            ],
            json_decode((string) $response->getContent(), true),
        );
    }

    #[Test]
    public function it_responds_404_for_an_unknown_phase(): void
    {
        $response = $this->app->handle(Request::create('/phase/999999/status', 'POST', ['status' => 'active']));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_responds_404_for_a_non_numeric_phase_id(): void
    {
        $response = $this->app->handle(Request::create('/phase/not-a-number/status', 'POST', ['status' => 'active']));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_get_requests(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        $response = $this->app->handle(Request::create("/phase/{$phase->id}/status", 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }
}
