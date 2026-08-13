<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

/**
 * The ticket header shown at the top of the deep view and, as of ticket 153
 * task 1268, also the source of every field rendered in the ticket edit
 * panel's read-only display (requirement 137: every column on the ticket's
 * own database row, not just the editable ones). `objective` carries the
 * same underlying value as the `description` database column — it keeps its
 * existing name because the deep-view tagline paragraph and the ai-details
 * toggle already consume it under that name; the panel template reads the
 * same property under the label "Description".
 */
final readonly class TicketHeaderViewModel
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $name,
        public string $status,
        public string $type,
        public string $objective,
        public string $aiDescription,
        public string $createdAt,
        public string $updatedAt,
        public string $archivedAt,
        public string $sessionId,
        public ?int $priority,
    ) {}
}
