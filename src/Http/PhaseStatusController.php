<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /phase/{id}/status` (ticket 159, requirements 161/162/171/173).
 * Same purpose and lookup-chain/response-shape rules as TaskStatusController
 * one level up, resolving phase -> ticket the way PhaseEditController does.
 * The response omits the `task` member — a phase has no task to report.
 */
final readonly class PhaseStatusController
{
    public function __construct(
        private PhaseService $phaseService,
        private TicketService $ticketService,
        private TmCliRunner $tmCliRunner,
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

        $status = $request->request->get('status');
        $status = is_string($status) ? $status : '';

        $result = $this->tmCliRunner->run('phase:set', ['phase' => (string) $phaseId, 'status' => $status]);

        $phase = $this->phaseService->show($phaseId);
        $ticket = $this->ticketService->show($phase->ticketId);

        $payload = [
            'phase' => ['id' => $phase->id, 'status' => $phase->status],
            'ticket' => ['id' => $ticket->id, 'status' => $ticket->status],
        ];

        if ($result->isSuccess()) {
            return new JsonResponse($payload, 200);
        }

        return new JsonResponse(['error' => $result->errorMessage(), ...$payload], 409);
    }
}
