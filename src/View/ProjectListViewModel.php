<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class ProjectListViewModel
{
    /** @param array<ProjectRowViewModel> $projects */
    public function __construct(
        public array $projects,
        public bool $showDone,
    ) {}
}
