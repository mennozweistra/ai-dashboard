<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\View;

use AiToolset\AiDashboard\View\ProjectListViewBuilder;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Services\ProjectService;
use PHPUnit\Framework\Attributes\Test;

final class ProjectListViewBuilderTest extends BaseViewTest
{
    private ProjectService $projectService;
    private ProjectListViewBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectService = new ProjectService(
            repository: new ProjectRepository($this->pdo),
            clock: new SystemClock(),
            ticketRepository: new TicketRepository($this->pdo),
        );
        $this->builder = new ProjectListViewBuilder();
    }

    #[Test]
    public function it_returns_one_row_for_one_active_project_when_one_is_archived(): void
    {
        $active = $this->projectService->add(new ProjectIn(name: 'active', path: '/active'));
        $archived = $this->projectService->add(new ProjectIn(name: 'archived', path: '/archived'));
        $this->projectService->archive($archived->id);

        $refs = $this->projectService->list(includeArchived: false);
        $model = $this->builder->build($refs, showDone: false);

        self::assertCount(1, $model->projects);
        self::assertSame($active->id, $model->projects[0]->id);
        self::assertSame('active', $model->projects[0]->name);
    }

    #[Test]
    public function it_sorts_projects_alphabetically_by_name(): void
    {
        $this->projectService->add(new ProjectIn(name: 'zebra', path: '/zebra'));
        $this->projectService->add(new ProjectIn(name: 'apple', path: '/apple'));
        $this->projectService->add(new ProjectIn(name: 'mango', path: '/mango'));

        $refs = $this->projectService->list(includeArchived: false);
        $model = $this->builder->build($refs, showDone: false);

        self::assertCount(3, $model->projects);
        self::assertSame('apple', $model->projects[0]->name);
        self::assertSame('mango', $model->projects[1]->name);
        self::assertSame('zebra', $model->projects[2]->name);
    }

    #[Test]
    public function it_carries_status_from_project_ref_to_row_view_model(): void
    {
        $this->projectService->add(new ProjectIn(name: 'alpha', path: '/alpha'));

        $refs = $this->projectService->list(includeArchived: false);
        $model = $this->builder->build($refs, showDone: false);

        self::assertCount(1, $model->projects);
        self::assertNotEmpty($model->projects[0]->status);
    }

    #[Test]
    public function it_excludes_done_projects_when_show_done_is_false(): void
    {
        $active = $this->projectService->add(new ProjectIn(name: 'active', path: '/active'));
        $done = $this->projectService->add(new ProjectIn(name: 'done-project', path: '/done', autoStatus: false));
        $this->projectService->set(id: $done->id, status: 'done');

        $refs = $this->projectService->list(includeArchived: false);
        $model = $this->builder->build($refs, showDone: false);

        self::assertCount(1, $model->projects);
        self::assertSame($active->id, $model->projects[0]->id);
    }

    #[Test]
    public function it_includes_done_projects_when_show_done_is_true(): void
    {
        $this->projectService->add(new ProjectIn(name: 'active', path: '/active'));
        $done = $this->projectService->add(new ProjectIn(name: 'done-project', path: '/done', autoStatus: false));
        $this->projectService->set(id: $done->id, status: 'done');

        $refs = $this->projectService->list(includeArchived: false);
        $model = $this->builder->build($refs, showDone: true);

        self::assertCount(2, $model->projects);
    }

    #[Test]
    public function it_carries_show_done_flag_to_view_model(): void
    {
        $refs = $this->projectService->list(includeArchived: false);

        $modelWithout = $this->builder->build($refs, showDone: false);
        $modelWith = $this->builder->build($refs, showDone: true);

        self::assertFalse($modelWithout->showDone);
        self::assertTrue($modelWith->showDone);
    }
}
