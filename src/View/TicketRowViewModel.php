<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class TicketRowViewModel
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $name,
        public string $status,
        public string $type,
        public string $createdAt,
        public string $href,
        public ?int $priority = null,
    ) {}
}
