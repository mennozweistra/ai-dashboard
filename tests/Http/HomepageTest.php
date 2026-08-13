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

final class HomepageTest extends BaseHttpTest
{
    #[Test]
    public function it_returns_200_ok_for_get_root(): void
    {
        $request = Request::create('/');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        self::assertStringContainsString('must-revalidate', $response->headers->get('Cache-Control') ?? '');
    }

    #[Test]
    public function it_returns_html_with_stylesheet_link(): void
    {
        $request = Request::create('/');
        $response = $this->app->handle($request, catch: false);

        self::assertStringContainsString('text/html', $response->headers->get('Content-Type') ?? '');
        self::assertStringContainsString('/static/style.css', (string) $response->getContent());
    }

    #[Test]
    public function it_renders_project_link_for_seeded_project(): void
    {
        $service = new ProjectService(
            repository: new ProjectRepository($this->pdo),
            clock: new SystemClock(),
            ticketRepository: new TicketRepository($this->pdo),
        );
        $project = $service->add(new ProjectIn(name: 'tm', path: '/tm'));

        $request = Request::create('/');
        $response = $this->app->handle($request, catch: false);
        $content = (string) $response->getContent();

        self::assertStringContainsString('href="/?project=' . $project->id . '"', $content);
        self::assertStringContainsString('class="project-name">tm<', $content);
        self::assertStringContainsString('class="project-id">[' . $project->id . ']<', $content);
    }

    #[Test]
    public function it_renders_section_heading_as_h2_inside_section(): void
    {
        $request = Request::create('/');
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        // Section heading must be <h2 class="section-label"> inside a <section>, not <section class="section-label">
        self::assertStringContainsString('<h2 class="section-label">Projects</h2>', $html);
        self::assertStringNotContainsString('<section class="section-label">', $html);
    }
}
