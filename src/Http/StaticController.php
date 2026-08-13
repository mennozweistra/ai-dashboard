<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use Symfony\Component\HttpFoundation\Response;

final readonly class StaticController
{
    private string $staticDir;

    public function __construct()
    {
        $this->staticDir = (string) realpath(dirname(__DIR__, 2) . '/public/static');
    }

    public function __invoke(string $path): Response
    {
        $candidate = $this->staticDir . '/' . $path;
        $resolved = realpath($candidate);

        if ($resolved === false || !str_starts_with($resolved, $this->staticDir . '/') && $resolved !== $this->staticDir) {
            return new Response('Not Found', 404);
        }

        if (!is_file($resolved)) {
            return new Response('Not Found', 404);
        }

        $content = file_get_contents($resolved);
        if ($content === false) {
            return new Response('Not Found', 404);
        }

        $ext = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js'  => 'application/javascript; charset=utf-8',
            default => 'application/octet-stream',
        };

        return new Response($content, 200, ['Content-Type' => $mimeType]);
    }
}
