<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiDashboard\View\TicketListViewBuilder;
use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class TicketListController
{
    public function __construct(
        private Environment $twig,
        private ProjectService $projectService,
        private TicketService $ticketService,
        private TicketListViewBuilder $viewBuilder,
        private TmCliRunner $tmCliRunner,
    ) {}

    public function __invoke(int $projectId, bool $showDone): ?Response
    {
        return $this->render($projectId, $showDone);
    }

    /**
     * Reads the project and its tickets, builds the view model, and renders
     * `ticket_list.html.twig`. Shared by the plain `GET /?project={id}` path
     * (`__invoke()` above, no create-ticket popup context) and a later
     * create-ticket route task's failed-save re-render (ticket 160 task
     * 1433), which passes the popup's submitted state through to
     * `TicketListViewBuilder::build()` instead of duplicating the ai-lib
     * reads done here — the same pattern `TicketDeepController::render()`
     * uses for the ticket/phase/task edit dialogs.
     */
    public function render(
        int $projectId,
        bool $showDone,
        bool $createTicketOpen = false,
        ?string $createTicketError = null,
        ?string $createTicketTitle = null,
        ?string $createTicketDescription = null,
        ?string $createTicketTemplate = null,
    ): ?Response {
        try {
            $project = $this->projectService->show($projectId);
        } catch (NotFoundException) {
            return null;
        }

        $tickets = $this->ticketService->list($projectId, includeArchived: false);
        $templates = $this->templateNames($this->tmCliRunner->run('template:list', []));
        $model = $this->viewBuilder->build(
            $project,
            $tickets,
            $showDone,
            $templates,
            $createTicketOpen,
            $createTicketError,
            $createTicketTitle,
            $createTicketDescription,
            $createTicketTemplate,
        );
        $html = $this->twig->render('ticket_list.html.twig', ['model' => $model]);
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->headers->set('Cache-Control', 'no-store, must-revalidate');

        return $response;
    }

    /**
     * Reads the `templates` array out of `bin/tm template:list`'s decoded
     * `data` (ticket 160, requirement 155's dropdown feeds off this list;
     * task 1431 wires the data plumbing only). Falls back to an empty list
     * whenever the call fails or returns an unexpected shape, so a broken or
     * unreachable `bin/tm` degrades the create-ticket template dropdown
     * (added by a later task) rather than breaking the ticket list page
     * itself.
     *
     * @return list<string>
     */
    private function templateNames(TmCliResult $result): array
    {
        if (!$result->isSuccess()) {
            return [];
        }

        $templates = $result->data()['templates'] ?? null;
        if (!is_array($templates)) {
            return [];
        }

        return array_values(array_filter($templates, is_string(...)));
    }
}
