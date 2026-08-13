<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /ticket/{id}/open-ide` (ticket 164, requirement 201). Registered
 * only when an IDE command is configured (see `Application::buildKernel()`)
 * — with none configured the route does not exist and a POST here 404s
 * through the normal router path, keeping the null out of both this
 * controller and `IdeOpener`'s constructor.
 *
 * Reads the ticket and its project's stable root directory from ai-lib
 * (read-only, never a mutating call), resolves the workspace directory by
 * ticket number through {@see TicketWorkspaceResolver}, then shells out
 * through `IdeOpener`.
 *
 * Response shape: success is 204 with an empty body; failure is 500 with the
 * failure message as a plain-text body.
 */
final readonly class TicketIdeController
{
    public function __construct(
        private TicketService $ticketService,
        private ProjectService $projectService,
        private TicketWorkspaceResolver $workspaceResolver,
        private IdeOpener $ideOpener,
    ) {}

    public function __invoke(string $id): Response
    {
        $ticketId = (int) $id;

        try {
            $ticket = $this->ticketService->show($ticketId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        try {
            $project = $this->projectService->show($ticket->projectId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $directory = $this->workspaceResolver->resolve($project->path, $ticketId);
        $result = $this->ideOpener->open($directory);

        if ($result->isSuccess()) {
            return new Response('', 204);
        }

        return new Response($result->message, 500, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
