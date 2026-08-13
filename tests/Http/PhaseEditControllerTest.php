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
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\Ordering;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the `POST /phase/{id}/edit` route (ticket 153, task 1269) through
 * the real HttpKernel, with Application pointed at the fake `bin/tm` stub
 * (see tests/Http/fixtures/fake-tm), mirroring TicketEditControllerTest's
 * coverage for the ticket-level route.
 */
final class PhaseEditControllerTest extends BaseHttpTest
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
    public function it_redirects_to_the_deep_view_on_a_successful_save(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        $response = $this->app->handle(Request::create(
            "/phase/{$phase->id}/edit",
            'POST',
            ['name' => 'New phase name', 'description' => 'New description'],
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

        // The fake stub reports failure exactly when --name is "fail-me".
        $response = $this->app->handle(Request::create(
            "/phase/{$phase->id}/edit",
            'POST',
            ['name' => 'fail-me', 'description' => 'typed but not saved'],
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('simulated failure for name', $body);
        self::assertStringNotContainsString('"ok":false', $body);
        // The just-submitted values are preserved in that phase's form, not the stored ones.
        self::assertStringContainsString('fail-me', $body);
        self::assertStringContainsString('typed but not saved', $body);
        // The phase panel opens already in edit mode.
        self::assertStringContainsString('data-open-phase-id="' . $phase->id . '"', $body);
        self::assertStringContainsString('data-open-on-load', $body);
    }

    #[Test]
    public function it_responds_404_for_an_unknown_phase(): void
    {
        $response = $this->app->handle(Request::create('/phase/999999/edit', 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_responds_404_when_the_phases_ticket_cannot_be_resolved(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        // Orphan the phase by removing its ticket directly, bypassing the
        // foreign key that normal service calls would never allow to be
        // violated — this simulates the data-integrity-gap case the
        // controller's second not-found check exists to guard.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec("DELETE FROM tickets WHERE id = {$ticket->id}");
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $response = $this->app->handle(Request::create("/phase/{$phase->id}/edit", 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_responds_404_for_a_non_numeric_phase_id(): void
    {
        $response = $this->app->handle(Request::create('/phase/not-a-number/edit', 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_get_requests(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));

        $response = $this->app->handle(Request::create("/phase/{$phase->id}/edit", 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }
}
