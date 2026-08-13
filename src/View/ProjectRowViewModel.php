<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class ProjectRowViewModel
{
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
    ) {}
}
