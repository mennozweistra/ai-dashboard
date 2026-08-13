<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

/**
 * Resolves the directory the "open IDE" action opens, by ticket number
 * (ticket 164, requirement 201). No path is stored on the ticket — an
 * earlier design that did was dropped during Planning — so this glob-matches
 * `tickets/ticket-<id>-*` under the project root on every call. The slug
 * after the id plays no part in the match, so renaming a ticket's directory
 * never breaks the lookup.
 *
 * Example: for project root `/home/dev/widgets` and ticket 42, this globs
 * `/home/dev/widgets/tickets/ticket-42-*`. Exactly one directory match
 * resolves to that directory. Zero matches, or two or more (renaming can
 * leave both the old and new directory on disk momentarily), fall back to
 * the project root `/home/dev/widgets` because guessing between multiple
 * matches would risk opening the wrong ticket's work.
 */
final readonly class TicketWorkspaceResolver
{
    public function resolve(string $projectPath, int $ticketId): string
    {
        $matches = glob("{$projectPath}/tickets/ticket-{$ticketId}-*") ?: [];
        $directories = array_values(array_filter($matches, is_dir(...)));

        return count($directories) === 1 ? $directories[0] : $projectPath;
    }
}
