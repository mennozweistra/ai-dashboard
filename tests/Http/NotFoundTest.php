<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class NotFoundTest extends BaseHttpTest
{
    #[Test]
    public function it_returns_404_for_unknown_route(): void
    {
        $request = Request::create('/does-not-exist');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Not Found', (string) $response->getContent());
    }

    #[Test]
    public function it_returns_404_for_static_route_with_unknown_path(): void
    {
        $request = Request::create('/static/nonexistent.css');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_404_as_styled_html_with_site_header(): void
    {
        $request = Request::create('/does-not-exist');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $html = (string) $response->getContent();
        // Must be a full HTML page with the stylesheet, not bare text
        self::assertStringContainsString('text/html', $response->headers->get('Content-Type') ?? '');
        self::assertStringContainsString('/static/style.css', $html);
        self::assertStringContainsString('site-header', $html);
        self::assertStringContainsString('Not Found', $html);
    }
}
