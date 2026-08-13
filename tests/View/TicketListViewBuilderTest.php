<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\View;

use AiToolset\AiDashboard\View\TicketListViewBuilder;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;

final class TicketListViewBuilderTest extends BaseViewTest
{
    private ProjectService $projectService;
    private TicketService $ticketService;
    private TicketListViewBuilder $builder;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = new SystemClock();
        $projectRepository = new ProjectRepository($this->pdo);
        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: new TicketRepository($this->pdo),
        );
        $this->ticketService = new TicketService(
            pdo: $this->pdo,
            projectRepository: $projectRepository,
            repository: new TicketRepository($this->pdo),
            recorder: new TransitionRecorder($this->pdo, $clock),
            clock: $clock,
            config: Config::default(),
        );
        $this->builder = new TicketListViewBuilder();
        $project = $this->projectService->add(new ProjectIn(name: 'test', path: '/test'));
        $this->projectId = $project->id;
    }

    #[Test]
    public function it_returns_tickets_sorted_by_created_at_descending(): void
    {
        $first = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'first'));
        $second = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'second'));

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: false);

        self::assertCount(2, $model->tickets);
        self::assertSame($second->id, $model->tickets[0]->id);
        self::assertSame($first->id, $model->tickets[1]->id);
    }

    #[Test]
    public function it_excludes_done_tickets_when_show_done_is_false(): void
    {
        $active = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'active-ticket'));
        $done = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'done-ticket'));
        $this->ticketService->set(id: $done->id, status: 'done');

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: false);

        self::assertCount(1, $model->tickets);
        self::assertSame($active->id, $model->tickets[0]->id);
    }

    #[Test]
    public function it_includes_done_tickets_when_show_done_is_true(): void
    {
        $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'active-ticket'));
        $done = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'done-ticket'));
        $this->ticketService->set(id: $done->id, status: 'done');

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: true);

        self::assertCount(2, $model->tickets);
    }

    #[Test]
    public function it_sorts_by_priority_then_creation_order(): void
    {
        $lowFirst = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'low-first', priority: 1));
        $highFirst = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'high-first', priority: 3));
        $nullFirst = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'null-first'));
        $mediumFirst = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'medium-first', priority: 2));
        $highSecond = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'high-second', priority: 3));
        $mediumSecond = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'medium-second', priority: 2));
        $lowSecond = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'low-second', priority: 1));
        $nullSecond = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'null-second'));

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: false);

        $orderedIds = array_map(fn($row) => $row->id, $model->tickets);
        self::assertSame(
            [
                $highSecond->id,
                $highFirst->id,
                $mediumSecond->id,
                $mediumFirst->id,
                $lowSecond->id,
                $lowFirst->id,
                $nullSecond->id,
                $nullFirst->id,
            ],
            $orderedIds,
        );
    }

    #[Test]
    public function it_propagates_type_to_ticket_row_view_model(): void
    {
        $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'a-ticket', type: 'workflow'));

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: false);

        self::assertSame('workflow', $model->tickets[0]->type);
    }

    #[Test]
    public function it_propagates_available_template_names_to_the_view_model(): void
    {
        // Ticket 160 task 1431: the create-ticket popup's template dropdown
        // (a later task) reads this list off the view model. The builder
        // does not fetch or shape the names itself — TicketListController
        // reads them from `bin/tm template:list` (via TmCliRunner) and
        // passes them straight through as a list<string>.
        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);

        $model = $this->builder->build($project, $refs, showDone: false, templates: ['bugfix', 'feature']);

        self::assertSame(['bugfix', 'feature'], $model->templates);
    }

    #[Test]
    public function it_reports_the_create_ticket_popup_closed_with_empty_fields_by_default(): void
    {
        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);

        $model = $this->builder->build($project, $refs, showDone: false);

        self::assertFalse($model->createTicketOpen);
        self::assertNull($model->createTicketError);
        self::assertSame('', $model->createTicketTitle);
        self::assertSame('', $model->createTicketDescription);
        self::assertSame('', $model->createTicketTemplate);
    }

    #[Test]
    public function it_reports_the_create_ticket_popup_open_with_error_and_preserved_values(): void
    {
        // Ticket 160 task 1433: a later create-ticket route task re-renders
        // through this same builder on a failed `bin/tm` call, mirroring how
        // TicketDeepController/TicketEditController re-open the edit dialog
        // with the submitted values and a parsed error message preserved.
        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);

        $model = $this->builder->build(
            $project,
            $refs,
            showDone: false,
            createTicketOpen: true,
            createTicketError: 'bin/tm failed',
            createTicketTitle: 'submitted title',
            createTicketDescription: 'submitted description',
            createTicketTemplate: 'bugfix',
        );

        self::assertTrue($model->createTicketOpen);
        self::assertSame('bin/tm failed', $model->createTicketError);
        self::assertSame('submitted title', $model->createTicketTitle);
        self::assertSame('submitted description', $model->createTicketDescription);
        self::assertSame('bugfix', $model->createTicketTemplate);
    }
}
