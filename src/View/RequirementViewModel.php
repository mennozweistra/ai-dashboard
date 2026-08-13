<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class RequirementViewModel
{
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public string $aiDescription,
        public string $verification,
        public int $order,
    ) {}
}
