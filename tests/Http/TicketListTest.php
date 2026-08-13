<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class TicketListTest extends BaseHttpTest
{
    private ProjectService $projectService;
    private TicketService $ticketService;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = new SystemClock();
        $projectRepository = new ProjectRepository($this->pdo);
        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: new TicketRepository($this->pdo),
        );
        $this->ticketService = new TicketService(
            pdo: $this->pdo,
            projectRepository: $projectRepository,
            repository: new TicketRepository($this->pdo),
            recorder: new TransitionRecorder($this->pdo, $clock),
            clock: $clock,
            config: Config::default(),
        );
    }

    #[Test]
    public function it_renders_ticket_list_for_known_project(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'myproject', path: '/myproject'));
        $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create('/?project=' . $project->id);
        $response = $this->app->handle($request, catch: false);
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('my-ticket', $content);
    }

    #[Test]
    public function it_falls_through_to_project_list_for_unknown_project(): void
    {
        $request = Request::create('/?project=999999');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('section-label', (string) $response->getContent());
    }

    #[Test]
    public function it_falls_through_to_project_list_for_non_integer_project(): void
    {
        $request = Request::create('/?project=foo');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('section-label', (string) $response->getContent());
    }

    #[Test]
    public function it_renders_project_breadcrumb_as_span_not_anchor_on_ticket_list(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'myproject', path: '/myproject'));

        $request = Request::create('/?project=' . $project->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        // On the ticket list page, the project name in the breadcrumb must be a plain <span>, not an <a>
        self::assertMatchesRegularExpression('/<span[^>]*>myproject<\/span>/', $html);
        self::assertDoesNotMatchRegularExpression('/<a[^>]*>myproject<\/a>/', $html);
    }

    #[Test]
    public function it_wraps_breadcrumb_separators_in_span_sep(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'myproject', path: '/myproject'));

        $request = Request::create('/?project=' . $project->id);
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertStringContainsString('<span class="sep">›</span>', $html);
    }

    #[Test]
    public function it_includes_done_tickets_when_show_done_is_present(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'active-ticket'));
        $done = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'done-ticket'));
        $this->ticketService->set(id: $done->id, status: 'done');

        $request = Request::create('/?project=' . $project->id . '&show_done=1');
        $response = $this->app->handle($request, catch: false);
        $content = (string) $response->getContent();

        self::assertStringContainsString('active-ticket', $content);
        self::assertStringContainsString('done-ticket', $content);
    }

    #[Test]
    public function it_renders_ticket_type_in_list_row(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket', type: 'workflow'));

        $request = Request::create('/?project=' . $project->id);
        $response = $this->app->handle($request, catch: false);
        $content = (string) $response->getContent();

        self::assertStringContainsString('workflow', $content);
    }

    #[Test]
    public function it_renders_the_ticket_list_when_bin_tm_is_unreachable(): void
    {
        // Ticket 160 task 1431: TicketListController now reads the template
        // list from `bin/tm template:list` (via TmCliRunner) on every
        // render. When bin/tm cannot be reached at all, TmCliRunner::run()
        // still returns a TmCliResult (isSuccess() === false), never throws
        // — so the page must keep rendering the ticket list rather than
        // erroring out. Pointing at a path with no binary reproduces that
        // failure without a dedicated fixture script.
        $this->app = new Application($this->pdo, '/nonexistent/bin/tm');

        $project = $this->projectService->add(new ProjectIn(name: 'myproject', path: '/myproject'));
        $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create('/?project=' . $project->id);
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('my-ticket', (string) $response->getContent());
    }
}
