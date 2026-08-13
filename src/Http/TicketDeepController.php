<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiDashboard\View\TicketDeepViewBuilder;
use AiToolset\AiLib\Domain\Exception\DomainException;
use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\LogService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class TicketDeepController
{
    public function __construct(
        private Environment $twig,
        private ProjectService $projectService,
        private TicketService $ticketService,
        private LogService $logService,
        private TicketDeepViewBuilder $viewBuilder,
        private ProjectListController $projectList,
        private TicketListController $ticketList,
        // Ticket 164, requirement 202: whether ~/.ai-dashboard/config.toml carries a usable
        // ide_command. Fixed per Application instance (a machine-local config file, not
        // request state), so it is a constructor property, not a render() parameter.
        private bool $ideConfigured = false,
    ) {}

    public function __invoke(int $projectId, int $ticketId): Response
    {
        return $this->render($projectId, $ticketId);
    }

    /**
     * Reads the ticket, builds the view model, and renders `ticket_deep.html.twig`.
     * Shared by the plain `GET /` deep-view path (`__invoke()` above, no edit
     * context), `TicketEditController`'s failed-save re-render (ticket 153,
     * task 1268), `PhaseEditController`'s failed-save re-render (ticket
     * 153, task 1269), and `TaskEditController`'s failed-save re-render
     * (ticket 153, task 1270) — all of which pass their edit-panel context
     * through to `TicketDeepViewBuilder::build()` instead of duplicating the
     * ai-lib reads done here.
     */
    public function render(
        int $projectId,
        int $ticketId,
        bool $editOpen = false,
        ?string $editError = null,
        ?string $editName = null,
        ?string $editDescription = null,
        ?string $editAiDescription = null,
        ?int $phaseEditId = null,
        ?string $phaseEditError = null,
        ?string $phaseEditName = null,
        ?string $phaseEditDescription = null,
        ?string $phaseEditAiDescription = null,
        ?int $taskEditId = null,
        ?string $taskEditError = null,
        ?string $taskEditName = null,
        ?string $taskEditDescription = null,
        ?string $taskEditAiDescription = null,
        ?string $taskEditActor = null,
        ?string $taskEditMaxAttempts = null,
    ): Response {
        try {
            $project = $this->projectService->show($projectId);
        } catch (NotFoundException) {
            return ($this->projectList)();
        }

        try {
            $deep = $this->ticketService->showDeep($ticketId);
        } catch (NotFoundException) {
            return ($this->ticketList)($projectId, false) ?? ($this->projectList)();
        } catch (DomainException $e) {
            $html = $this->twig->render('ticket_deep_error.html.twig', [
                'projectId' => $projectId,
                'projectName' => $project->name,
                'ticketId' => $ticketId,
                'errorMessage' => $e->getMessage(),
            ]);

            return new Response($html, 500, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        if ($deep->projectId !== $projectId) {
            return ($this->ticketList)($projectId, false) ?? ($this->projectList)();
        }

        $logs = array_values($this->logService->listByTicket($ticketId, 'desc'));
        $model = $this->viewBuilder->build(
            $project,
            $deep,
            $logs,
            $editOpen,
            $editError,
            $editName,
            $editDescription,
            $editAiDescription,
            $phaseEditId,
            $phaseEditError,
            $phaseEditName,
            $phaseEditDescription,
            $phaseEditAiDescription,
            $taskEditId,
            $taskEditError,
            $taskEditName,
            $taskEditDescription,
            $taskEditAiDescription,
            $taskEditActor,
            $taskEditMaxAttempts,
            ideConfigured: $this->ideConfigured,
        );
        $html = $this->twig->render('ticket_deep.html.twig', ['model' => $model]);
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->headers->set('Cache-Control', 'no-store, must-revalidate');

        return $response;
    }
}
