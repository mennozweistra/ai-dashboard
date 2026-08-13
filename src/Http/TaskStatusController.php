<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\TaskService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /task/{id}/status` (ticket 159, requirements 161/162/171/173).
 * Backs the ticket page's click-to-cycle status control: the frontend steps
 * the displayed value through the status cycle locally, then posts the
 * final value here once. Resolves the task -> phase -> ticket lookup chain
 * the same way TaskEditController does, shells the status change out to
 * `bin/tm task:set --status` through TmCliRunner — never a mutating ai-lib
 * call — and re-reads the stored task/phase/ticket statuses via ai-lib's
 * read services afterwards, so the response reflects any autoStatus rollup
 * `bin/tm`'s own RollupService performed (requirement 173).
 *
 * The response is JSON, not HTML: a deliberate, user-approved break from
 * http.md §1.2's "no JSON endpoints", documented in ticket 159's docs task.
 * Success is 200 with `{"task":..., "phase":..., "ticket":...}`. A `bin/tm`
 * failure (non-zero exit) is 409 with `{"error": "<parsed message>"}` plus
 * the same three members holding the currently stored statuses, so the
 * frontend can revert its optimistic display (requirement 171).
 */
final readonly class TaskStatusController
{
    public function __construct(
        private TaskService $taskService,
        private PhaseService $phaseService,
        private TicketService $ticketService,
        private TmCliRunner $tmCliRunner,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $taskId = (int) $id;

        try {
            $task = $this->taskService->show($taskId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        try {
            $phase = $this->phaseService->show($task->phaseId);
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

        $result = $this->tmCliRunner->run('task:set', ['task' => (string) $taskId, 'status' => $status]);

        $task = $this->taskService->show($taskId);
        $phase = $this->phaseService->show($task->phaseId);
        $ticket = $this->ticketService->show($phase->ticketId);

        $payload = [
            'task' => ['id' => $task->id, 'status' => $task->status],
            'phase' => ['id' => $phase->id, 'status' => $phase->status],
            'ticket' => ['id' => $ticket->id, 'status' => $ticket->status],
        ];

        if ($result->isSuccess()) {
            return new JsonResponse($payload, 200);
        }

        return new JsonResponse(['error' => $result->errorMessage(), ...$payload], 409);
    }
}
