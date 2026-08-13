<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Services\ProjectService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the `POST /project/{id}/ticket/create` route (ticket 160, task 1432,
 * requirements 156/157/158/159) through the real HttpKernel, with Application
 * pointed at the fake `bin/tm` stub (see tests/Http/fixtures/fake-tm) instead
 * of a real ai-lib-backed database. Ticket 269 removed the follow-on
 * terminal-session step this route used to perform after a successful
 * `ticket:add`, so this route now only creates the ticket and redirects.
 *
 * The fake-tm stub's `ticket:add` branch reports failure exactly when
 * `--name` is "fail-me" (the same convention `TicketEditControllerTest`
 * relies on for `ticket:set`) and otherwise returns a fixed ticket id.
 */
final class TicketCreateControllerTest extends BaseHttpTest
{
    private const string TM_STUB = __DIR__ . '/fixtures/fake-tm';

    /** Fixed ticket id the fake-tm stub's `ticket:add` branch always returns. */
    private const int STUB_TICKET_ID = 501;

    private ProjectService $projectService;

    protected function setUp(): void
    {
        parent::setUp();

        // BaseHttpTest already builds $this->app against the fake bin/tm
        // stub; rebuild it here explicitly so the stub path stays visible
        // to this test regardless of BaseHttpTest's own default.
        $this->app = new Application($this->pdo, self::TM_STUB);

        $clock = new SystemClock();
        $projectRepository = new ProjectRepository($this->pdo);

        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: new TicketRepository($this->pdo),
        );
    }

    #[Test]
    public function it_creates_the_ticket_and_redirects_to_the_ticket_view(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));

        $response = $this->app->handle(Request::create(
            "/project/{$project->id}/ticket/create",
            'POST',
            ['title' => 'New ticket', 'description' => 'What it is about', 'template' => 'feature'],
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '/?project=' . $project->id . '&ticket=' . self::STUB_TICKET_ID,
            $response->headers->get('Location'),
        );
    }

    #[Test]
    public function it_rerenders_the_project_page_with_the_parsed_error_and_preserved_fields_on_ticket_add_failure(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));

        // The fake-tm stub reports failure exactly when --name is "fail-me".
        $response = $this->app->handle(Request::create(
            "/project/{$project->id}/ticket/create",
            'POST',
            ['title' => 'fail-me', 'description' => 'typed but not saved', 'template' => 'bugfix'],
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('simulated failure for name', $body);
        self::assertStringNotContainsString('"ok":false', $body);
        // The just-submitted values are preserved in the form, not discarded.
        self::assertStringContainsString('fail-me', $body);
        self::assertStringContainsString('typed but not saved', $body);
        // The popup opens already open, with the failing template still selected.
        self::assertStringContainsString('data-open-on-load', $body);
        self::assertStringContainsString('value="bugfix" selected', $body);
    }

    #[Test]
    public function it_responds_404_for_an_unknown_project(): void
    {
        $response = $this->app->handle(Request::create(
            '/project/999999/ticket/create',
            'POST',
            ['title' => 'x', 'description' => 'y', 'template' => 'feature'],
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_rejects_get_requests(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'demo', path: '/some/project'));

        $response = $this->app->handle(Request::create("/project/{$project->id}/ticket/create", 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }
}
