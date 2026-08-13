<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class StaticRouteTest extends BaseHttpTest
{
    #[Test]
    public function it_serves_style_css(): void
    {
        $request = Request::create('/static/style.css');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/css', $response->headers->get('Content-Type') ?? '');
        self::assertStringContainsString('--bg:', (string) $response->getContent());
    }

    #[Test]
    public function it_returns_404_for_unknown_file(): void
    {
        $request = Request::create('/static/nonexistent.css');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_404_for_path_traversal(): void
    {
        $request = Request::create('/static/../../../etc/passwd');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(404, $response->getStatusCode());
    }
}
