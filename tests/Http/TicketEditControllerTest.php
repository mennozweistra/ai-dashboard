<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
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
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the `POST /ticket/{id}/edit` route (ticket 153, task 1268) through
 * the real HttpKernel, with Application pointed at the fake `bin/tm` stub
 * (see tests/Http/fixtures/fake-tm) instead of a real ai-lib-backed CLI
 * process. `TmCliRunnerTest` covers the wrapper class itself; these tests
 * cover the controller's routing, redirect, and failure re-render behaviour.
 */
final class TicketEditControllerTest extends BaseHttpTest
{
    private const string STUB = __DIR__ . '/fixtures/fake-tm';

    /**
     * Ticket 56 task 1056: fake-tm's generic success branch only echoes
     * back `name` and `subcommand`, and a successful save is a bodyless
     * 302 redirect anyway, so nothing in an HTTP response can show which
     * options were actually forwarded to `bin/tm`. This stub always fails
     * and echoes the exact argv it received into the error message, which
     * TicketEditController renders verbatim into the edit panel on
     * failure — that is what lets the forwarding tests below assert on it.
     */
    private const string ARGV_ECHO_STUB = __DIR__ . '/fixtures/fake-tm-argv-echo';

    private ProjectService $projectService;
    private TicketService $ticketService;

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
            requirementRepository: new RequirementRepository($this->pdo),
        );
    }

    #[Test]
    public function it_redirects_to_the_deep_view_on_a_successful_save(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));

        $response = $this->app->handle(Request::create(
            "/ticket/{$ticket->id}/edit",
            'POST',
            ['name' => 'New name', 'description' => 'New description'],
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

        // The fake stub reports failure exactly when --name is "fail-me".
        $response = $this->app->handle(Request::create(
            "/ticket/{$ticket->id}/edit",
            'POST',
            ['name' => 'fail-me', 'description' => 'typed but not saved'],
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('simulated failure for name', $body);
        self::assertStringNotContainsString('"ok":false', $body);
        // The just-submitted values are preserved in the form, not the stored ones.
        self::assertStringContainsString('fail-me', $body);
        self::assertStringContainsString('typed but not saved', $body);
        // The panel opens already in edit mode.
        self::assertStringContainsString('data-mode="edit"', $body);
        self::assertStringContainsString('data-open-on-load', $body);
    }

    #[Test]
    public function it_forwards_a_submitted_status_comment_as_the_status_comment_option(): void
    {
        // Requirement 75: status_comment maps to --status-comment, mirroring
        // name/description/ai_description in §2.5 of docs/api/http.md.
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $this->app = new Application($this->pdo, self::ARGV_ECHO_STUB);

        $response = $this->app->handle(Request::create(
            "/ticket/{$ticket->id}/edit",
            'POST',
            ['status_comment' => 'waiting on the migration review'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            '--status-comment|waiting on the migration review',
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function it_forwards_an_empty_status_comment_so_the_stored_comment_is_cleared(): void
    {
        // The column is `text NOT NULL DEFAULT ''`, so an empty submitted
        // value is a real value (clear the comment), not a missing one —
        // unlike a field genuinely absent from the body (next test).
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $this->app = new Application($this->pdo, self::ARGV_ECHO_STUB);

        $response = $this->app->handle(Request::create(
            "/ticket/{$ticket->id}/edit",
            'POST',
            ['status_comment' => ''],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('--status-comment|', (string) $response->getContent());
    }

    #[Test]
    public function it_does_not_forward_status_comment_at_all_when_it_is_absent_from_the_body(): void
    {
        // The existing "missing option means leave unchanged" rule
        // (src/Http/TicketEditController.php around lines 42-59) must keep
        // holding for the new field: omitting it from the POST body must
        // not forward --status-comment at all, so bin/tm leaves the stored
        // comment alone.
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $this->app = new Application($this->pdo, self::ARGV_ECHO_STUB);

        $response = $this->app->handle(Request::create(
            "/ticket/{$ticket->id}/edit",
            'POST',
            ['name' => 'New name'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('--status-comment', (string) $response->getContent());
    }

    #[Test]
    public function it_keeps_the_typed_status_comment_in_the_reopened_form_on_a_failed_save(): void
    {
        // Requirement 75: on a failed save the reopened form keeps the
        // submitted comment, not the stored one, mirroring the existing
        // preserve-on-error behaviour for name/description/ai_description
        // (it_rerenders_the_ticket_deep_page_with_the_parsed_error_message_on_failure
        // above). The regular fake-tm stub reports failure exactly when
        // --name is "fail-me".
        //
        // The claim is about the form input only, not the whole page. The
        // always-visible meta block above the form keeps showing the stored
        // value, the same as every other field it lists — a rejected save
        // changes nothing that is stored, so the meta block must not report
        // as if it had.
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));
        $this->ticketService->set(id: $ticket->id, statusComment: 'stored comment');

        $response = $this->app->handle(Request::create(
            "/ticket/{$ticket->id}/edit",
            'POST',
            ['name' => 'fail-me', 'status_comment' => 'typed but not saved'],
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString(
            '<input type="text" id="ticket-edit-status-comment" name="status_comment" value="typed but not saved">',
            $body,
        );
        self::assertStringNotContainsString(
            '<input type="text" id="ticket-edit-status-comment" name="status_comment" value="stored comment">',
            $body,
        );
        self::assertStringContainsString('<dt>Status comment</dt><dd>stored comment</dd>', $body);
    }

    #[Test]
    public function it_responds_404_for_an_unknown_ticket(): void
    {
        $response = $this->app->handle(Request::create('/ticket/999999/edit', 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_responds_404_for_a_non_numeric_ticket_id(): void
    {
        $response = $this->app->handle(Request::create('/ticket/not-a-number/edit', 'POST', ['name' => 'x']));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_get_requests(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'demo ticket'));

        $response = $this->app->handle(Request::create("/ticket/{$ticket->id}/edit", 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }
}
