<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Services\ProjectService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class ProjectListTest extends BaseHttpTest
{
    private ProjectService $projectService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectService = new ProjectService(
            repository: new ProjectRepository($this->pdo),
            clock: new SystemClock(),
            ticketRepository: new TicketRepository($this->pdo),
        );
    }

    #[Test]
    public function it_hides_done_projects_by_default(): void
    {
        $this->projectService->add(new ProjectIn(name: 'active-project', path: '/active'));
        $done = $this->projectService->add(new ProjectIn(name: 'done-project', path: '/done', autoStatus: false));
        $this->projectService->set(id: $done->id, status: 'done');

        $response = $this->app->handle(Request::create('/'));
        $content = (string) $response->getContent();

        self::assertStringContainsString('active-project', $content);
        self::assertStringNotContainsString('done-project', $content);
    }

    #[Test]
    public function it_shows_done_projects_when_show_done_is_set(): void
    {
        $this->projectService->add(new ProjectIn(name: 'active-project', path: '/active'));
        $done = $this->projectService->add(new ProjectIn(name: 'done-project', path: '/done', autoStatus: false));
        $this->projectService->set(id: $done->id, status: 'done');

        $response = $this->app->handle(Request::create('/?show_done=1'));
        $content = (string) $response->getContent();

        self::assertStringContainsString('active-project', $content);
        self::assertStringContainsString('done-project', $content);
    }

    #[Test]
    public function it_renders_show_done_toggle_on_project_list(): void
    {
        $response = $this->app->handle(Request::create('/'));
        $content = (string) $response->getContent();

        self::assertStringContainsString('toggle-form', $content);
        self::assertStringContainsString('show done', $content);
    }

    #[Test]
    public function it_renders_toggle_as_active_when_show_done_is_set(): void
    {
        $response = $this->app->handle(Request::create('/?show_done=1'));
        $content = (string) $response->getContent();

        self::assertStringContainsString('is-on', $content);
        self::assertStringContainsString('show done', $content);
    }
}
