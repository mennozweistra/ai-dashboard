<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /phase/{id}/edit` (ticket 153, requirements 122/124/126/127/130/131/
 * 132/133/137/139). Reads the phase via `PhaseService::show()` to resolve its
 * `ticketId`, then the ticket via `TicketService::show()` to resolve the
 * `projectId` needed for the redirect/re-render target. The actual write
 * goes through `TmCliRunner`'s `bin/tm phase:set`, never a mutating ai-lib
 * call — the same boundary `TicketEditController` keeps for `ticket:set`.
 *
 * A submitted field is only forwarded to `phase:set` when present in the
 * POST body, mirroring `TicketEditController`'s "missing field means leave
 * unchanged" behaviour.
 */
final readonly class PhaseEditController
{
    public function __construct(
        private PhaseService $phaseService,
        private TicketService $ticketService,
        private TmCliRunner $tmCliRunner,
        private TicketDeepController $ticketDeepController,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $phaseId = (int) $id;

        try {
            $phase = $this->phaseService->show($phaseId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        try {
            $ticket = $this->ticketService->show($phase->ticketId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $name = $request->request->get('name');
        $description = $request->request->get('description');
        $aiDescription = $request->request->get('ai_description');
        $name = is_string($name) ? $name : null;
        $description = is_string($description) ? $description : null;
        $aiDescription = is_string($aiDescription) ? $aiDescription : null;

        $options = ['phase' => (string) $phaseId];
        if ($name !== null) {
            $options['name'] = $name;
        }
        if ($description !== null) {
            $options['description'] = $description;
        }
        if ($aiDescription !== null) {
            $options['ai-description'] = $aiDescription;
        }

        $result = $this->tmCliRunner->run('phase:set', $options);

        if ($result->isSuccess()) {
            return new RedirectResponse("/?project={$ticket->projectId}&ticket={$phase->ticketId}");
        }

        return $this->ticketDeepController->render(
            projectId: $ticket->projectId,
            ticketId: $phase->ticketId,
            phaseEditId: $phaseId,
            phaseEditError: $result->errorMessage(),
            phaseEditName: $name,
            phaseEditDescription: $description,
            phaseEditAiDescription: $aiDescription,
        );
    }
}
