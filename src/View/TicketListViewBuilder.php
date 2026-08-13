<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

use AiToolset\AiLib\Schemas\ProjectOut;
use AiToolset\AiLib\Schemas\TicketRef;

final readonly class TicketListViewBuilder
{
    /**
     * @param array<TicketRef> $tickets
     * @param list<string> $templates Available `bin/tm` template names (ticket
     *     160 task 1431), passed through unchanged onto the view model.
     *     Defaults to an empty list so existing callers/tests that do not
     *     care about templates are unaffected.
     * @param string|null $createTicketTitle Just-submitted `title` value to
     *     preserve in the create-ticket popup after a failed save; falls back
     *     to an empty string when null (ticket 160 task 1433).
     * @param string|null $createTicketDescription Just-submitted `description`
     *     value; same fallback rule as `$createTicketTitle`.
     * @param string|null $createTicketTemplate Just-submitted selected
     *     template name; same fallback rule as `$createTicketTitle`.
     */
    public function build(
        ProjectOut $project,
        array $tickets,
        bool $showDone,
        array $templates = [],
        bool $createTicketOpen = false,
        ?string $createTicketError = null,
        ?string $createTicketTitle = null,
        ?string $createTicketDescription = null,
        ?string $createTicketTemplate = null,
    ): TicketListViewModel {
        if (!$showDone) {
            $tickets = array_values(array_filter($tickets, fn(TicketRef $t) => $t->status !== 'done'));
        }

        usort(
            $tickets,
            fn(TicketRef $a, TicketRef $b) =>
                [$b->priority ?? 0, $b->createdAt, $b->id]
                <=> [$a->priority ?? 0, $a->createdAt, $a->id],
        );

        return new TicketListViewModel(
            projectId: $project->id,
            projectName: $project->name,
            tickets: array_map(fn(TicketRef $t) => new TicketRowViewModel(
                id: $t->id,
                projectId: $t->projectId,
                name: $t->name,
                status: $t->status,
                type: $t->type,
                createdAt: $t->createdAt->format('Y-m-d'),
                href: '/?project=' . $t->projectId . '&ticket=' . $t->id,
                priority: $t->priority,
            ), $tickets),
            showDone: $showDone,
            templates: $templates,
            createTicketOpen: $createTicketOpen,
            createTicketError: $createTicketError,
            createTicketTitle: $createTicketTitle ?? '',
            createTicketDescription: $createTicketDescription ?? '',
            createTicketTemplate: $createTicketTemplate ?? '',
        );
    }
}
