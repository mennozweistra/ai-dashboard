<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

use AiToolset\AiLib\Schemas\ProjectRef;

final readonly class ProjectListViewBuilder
{
    /** @param array<ProjectRef> $projects */
    public function build(array $projects, bool $showDone): ProjectListViewModel
    {
        if (!$showDone) {
            $projects = array_values(array_filter($projects, fn(ProjectRef $p) => $p->status !== 'done'));
        }

        usort($projects, fn(ProjectRef $a, ProjectRef $b) => strcmp($a->name, $b->name));

        return new ProjectListViewModel(
            projects: array_map(
                fn(ProjectRef $ref) => new ProjectRowViewModel($ref->id, $ref->name, $ref->status),
                $projects,
            ),
            showDone: $showDone,
        );
    }
}
