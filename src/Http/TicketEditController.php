<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /ticket/{id}/edit` (ticket 153, requirements 122/123/130/131/132/133).
 * Reads the ticket via TicketService::show() only to resolve its projectId
 * for the redirect/re-render target; the actual write goes through
 * TmCliRunner's `bin/tm ticket:set`, never a mutating ai-lib call.
 *
 * A submitted field is only forwarded to `ticket:set` when present in the
 * POST body, mirroring TicketSetCommand's own "missing option means leave
 * unchanged" behaviour.
 */
final readonly class TicketEditController
{
    public function __construct(
        private TicketService $ticketService,
        private TmCliRunner $tmCliRunner,
        private TicketDeepController $ticketDeepController,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $ticketId = (int) $id;

        try {
            $ticket = $this->ticketService->show($ticketId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $name = $request->request->get('name');
        $description = $request->request->get('description');
        $aiDescription = $request->request->get('ai_description');
        $name = is_string($name) ? $name : null;
        $description = is_string($description) ? $description : null;
        $aiDescription = is_string($aiDescription) ? $aiDescription : null;

        $options = ['ticket' => (string) $ticketId];
        if ($name !== null) {
            $options['name'] = $name;
        }
        if ($description !== null) {
            $options['description'] = $description;
        }
        if ($aiDescription !== null) {
            $options['ai-description'] = $aiDescription;
        }

        $result = $this->tmCliRunner->run('ticket:set', $options);

        if ($result->isSuccess()) {
            return new RedirectResponse("/?project={$ticket->projectId}&ticket={$ticketId}");
        }

        return $this->ticketDeepController->render(
            projectId: $ticket->projectId,
            ticketId: $ticketId,
            editOpen: true,
            editError: $result->errorMessage(),
            editName: $name,
            editDescription: $description,
            editAiDescription: $aiDescription,
        );
    }
}
