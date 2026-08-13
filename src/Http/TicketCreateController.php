<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use AiToolset\AiLib\Domain\Exception\NotFoundException;
use AiToolset\AiLib\Services\ProjectService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /project/{id}/ticket/create` (ticket 160, requirements 156/157/158/159
 * and the create half of 170). Creates a ticket through `bin/tm ticket:add`
 * (`TmCliRunner`) and, on success, redirects to the new ticket's deep view.
 * Same action-route exception `TicketEditController` relies on: no `ai-lib`
 * write ever happens here, only a read (`ProjectService::show()`, to 404 on
 * an unknown project before shelling out at all) plus the `TmCliRunner` side
 * effect. Ticket 269 removed the follow-on terminal-session step (and the
 * wrapper prompt that fed it) that used to run here after a successful
 * create: that integration stays private to the owner's machine, so a
 * public dashboard cannot depend on it.
 */
final readonly class TicketCreateController
{
    public function __construct(
        private ProjectService $projectService,
        private TmCliRunner $tmCliRunner,
        private TicketListController $ticketListController,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $projectId = (int) $id;

        try {
            $this->projectService->show($projectId);
        } catch (NotFoundException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $title = $this->stringField($request, 'title');
        $description = $this->stringField($request, 'description');
        $template = $this->stringField($request, 'template');

        $result = $this->tmCliRunner->run('ticket:add', [
            'project' => (string) $projectId,
            'name' => $title,
            'description' => $description,
            'template' => $template,
        ]);

        if (!$result->isSuccess()) {
            // ProjectService::show() above already proved $projectId exists,
            // so render() cannot return null here in practice; the fallback
            // exists only to satisfy Response as this method's return type.
            return $this->ticketListController->render(
                projectId: $projectId,
                showDone: false,
                createTicketOpen: true,
                createTicketError: $result->errorMessage(),
                createTicketTitle: $title,
                createTicketDescription: $description,
                createTicketTemplate: $template,
            ) ?? new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $data = $result->data();
        $rawId = $data['id'] ?? null;
        $newTicketId = is_int($rawId) ? $rawId : 0;

        return new RedirectResponse('/?' . http_build_query([
            'project' => $projectId,
            'ticket' => $newTicketId,
        ]));
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }
}
