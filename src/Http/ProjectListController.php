<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiDashboard\View\ProjectListViewBuilder;
use AiToolset\AiLib\Services\ProjectService;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class ProjectListController
{
    public function __construct(
        private Environment $twig,
        private ProjectService $projectService,
        private ProjectListViewBuilder $viewBuilder,
    ) {}

    public function __invoke(bool $showDone = false): Response
    {
        $projects = $this->projectService->list(includeArchived: false);
        $model = $this->viewBuilder->build($projects, $showDone);
        $html = $this->twig->render('project_list.html.twig', ['model' => $model]);
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->headers->set('Cache-Control', 'no-store, must-revalidate');

        return $response;
    }
}
