<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class HomeController
{
    public function __construct(
        private ProjectListController $projectList,
        private TicketListController $ticketList,
        private TicketDeepController $ticketDeep,
    ) {}

    public function __invoke(Request $request): Response
    {
        $projectParam = $request->query->get('project', '');
        $ticketParam = $request->query->get('ticket', '');

        $projectId = ctype_digit($projectParam) ? (int) $projectParam : 0;
        $ticketId = ctype_digit($ticketParam) ? (int) $ticketParam : 0;

        if ($projectId > 0 && $ticketId > 0) {
            return ($this->ticketDeep)($projectId, $ticketId);
        }

        if ($projectId > 0) {
            $showDone = $request->query->has('show_done');
            $response = ($this->ticketList)($projectId, $showDone);
            if ($response instanceof Response) {
                return $response;
            }
        }

        $showDoneProjects = $request->query->has('show_done');

        return ($this->projectList)($showDoneProjects);
    }
}
