<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class TicketListViewModel
{
    /**
     * @param array<TicketRowViewModel> $tickets
     * @param list<string> $templates Available `bin/tm` template names (ticket
     *     160 task 1431), read by TicketListController from
     *     `template:list` and passed straight through. Feeds the create-ticket
     *     popup's template `<select>` (ticket 160 task 1433).
     * @param bool $createTicketOpen Whether the create-ticket popup must
     *     auto-open on load (ticket 160 task 1433). `false` on a normal `GET`;
     *     set to `true` by a later create-ticket route task re-rendering the
     *     project page after a failed `bin/tm ticket:add` call, mirroring how
     *     `TicketDeepViewModel::$editOpen` re-opens the ticket-edit dialog on
     *     a failed save.
     * @param string|null $createTicketError Parsed `bin/tm` error message for
     *     that failed call; `null` on a normal render.
     * @param string $createTicketTitle Just-submitted `title` value to
     *     preserve in the popup after a failed create; empty string on a
     *     normal render (there is no stored ticket to fall back to yet).
     * @param string $createTicketDescription Just-submitted `description`
     *     value; same rule as `$createTicketTitle`.
     * @param string $createTicketTemplate Just-submitted selected template
     *     name; same rule as `$createTicketTitle`.
     */
    public function __construct(
        public int $projectId,
        public string $projectName,
        public array $tickets,
        public bool $showDone,
        public array $templates,
        public bool $createTicketOpen = false,
        public ?string $createTicketError = null,
        public string $createTicketTitle = '',
        public string $createTicketDescription = '',
        public string $createTicketTemplate = '',
    ) {}
}
