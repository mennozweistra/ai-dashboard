<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class TaskTransitionViewModel
{
    public function __construct(
        public ?string $fromStatus,
        public string $toStatus,
        public string $timestamp,
    ) {}
}
