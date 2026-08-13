<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\TaskService;
use AiToolset\AiLib\Services\TicketService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /task/{id}/edit` (ticket 153, requirements 124/126/127/130/131/132/
 * 133/136/137/139). Reads the task via `TaskService::show()` to resolve its
 * `phaseId`, then the phase via `PhaseService::show(phaseId)` to resolve its
 * `ticketId`, then the ticket via `TicketService::show(ticketId)` to resolve
 * the `projectId` needed for the redirect/re-render target — the same
 * lookup chain `PhaseEditController` uses one level up. The actual write
 * goes through `TmCliRunner`'s `bin/tm task:set`, never a mutating ai-lib
 * call — the same boundary `TicketEditController`/`PhaseEditController`
 * keep for `ticket:set`/`phase:set`.
 *
 * A submitted field is only forwarded to `task:set` when present in the
 * POST body, mirroring `PhaseEditController`'s "missing field means leave
 * unchanged" behaviour. `actor` and `max_attempts` are forwarded the same
 * way, on top of the `name`/`description`/`ai_description` fields the
 * ticket/phase edit routes already support.
 */
final readonly class TaskEditController
{
    public function __construct(
        private TaskService $taskService,
        private PhaseService $phaseService,
        private TicketService $ticketService,
        private TmCliRunner $tmCliRunner,
        private TicketDeepController $ticketDeepController,
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

        $name = $request->request->get('name');
        $description = $request->request->get('description');
        $aiDescription = $request->request->get('ai_description');
        $actor = $request->request->get('actor');
        $maxAttempts = $request->request->get('max_attempts');
        $name = is_string($name) ? $name : null;
        $description = is_string($description) ? $description : null;
        $aiDescription = is_string($aiDescription) ? $aiDescription : null;
        $actor = is_string($actor) ? $actor : null;
        $maxAttempts = is_string($maxAttempts) ? $maxAttempts : null;

        $options = ['task' => (string) $taskId];
        if ($name !== null) {
            $options['name'] = $name;
        }
        if ($description !== null) {
            $options['description'] = $description;
        }
        if ($aiDescription !== null) {
            $options['ai-description'] = $aiDescription;
        }
        if ($actor !== null) {
            $options['actor'] = $actor;
        }
        if ($maxAttempts !== null) {
            $options['max-attempts'] = $maxAttempts;
        }

        $result = $this->tmCliRunner->run('task:set', $options);

        if ($result->isSuccess()) {
            return new RedirectResponse("/?project={$ticket->projectId}&ticket={$phase->ticketId}");
        }

        return $this->ticketDeepController->render(
            projectId: $ticket->projectId,
            ticketId: $phase->ticketId,
            taskEditId: $taskId,
            taskEditError: $result->errorMessage(),
            taskEditName: $name,
            taskEditDescription: $description,
            taskEditAiDescription: $aiDescription,
            taskEditActor: $actor,
            taskEditMaxAttempts: $maxAttempts,
        );
    }
}
