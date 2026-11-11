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
    public function it_keeps_only_in_flight_statuses_ordered_by_priority_when_filter_is_on(): void
    {
        // Requirement 74: kept statuses are active, blocked, failed, review,
        // skipped; dropped are pending and done. Seeded without phases —
        // TicketService::set(status:) writes the status directly, and with
        // no phases present there is no auto_status rollup to overwrite it.
        $pending = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'pending-ticket', priority: 1));
        $active = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'active-ticket', priority: 2));
        $blocked = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'blocked-ticket', priority: 3));
        $failed = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'failed-ticket', priority: 1));
        $review = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'review-ticket', priority: 2));
        $skipped = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'skipped-ticket', priority: 3));
        $done = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'done-ticket', priority: 1));

        $this->ticketService->set(id: $active->id, status: 'active');
        $this->ticketService->set(id: $blocked->id, status: 'blocked');
        $this->ticketService->set(id: $failed->id, status: 'failed');
        $this->ticketService->set(id: $review->id, status: 'review');
        $this->ticketService->set(id: $skipped->id, status: 'skipped');
        $this->ticketService->set(id: $done->id, status: 'done');
        // $pending stays at its default 'pending' status.

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);

        // showDone: true isolates the assertion below from the pre-existing
        // show-done filter: the in-flight filter must drop 'done' on its
        // own, independent of the show-done toggle's own value.
        $filtered = $this->builder->build($project, $refs, showDone: true, inFlight: true);

        $filteredIds = array_map(fn($row) => $row->id, $filtered->tickets);
        self::assertSame(
            [$skipped->id, $blocked->id, $review->id, $active->id, $failed->id],
            $filteredIds,
        );

        $unfiltered = $this->builder->build($project, $refs, showDone: true, inFlight: false);
        self::assertCount(7, $unfiltered->tickets);
    }


    #[Test]
    public function it_propagates_status_comment_to_ticket_row_view_model(): void
    {
        $ticket = $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'commented-ticket'));
        $this->ticketService->set(id: $ticket->id, statusComment: 'waiting on the migration review');

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: false);

        self::assertSame('waiting on the migration review', $model->tickets[0]->statusComment);
    }

    #[Test]
    public function it_carries_an_empty_status_comment_when_none_was_set(): void
    {
        $this->ticketService->add(new TicketIn(projectId: $this->projectId, name: 'uncommented-ticket'));

        $project = $this->projectService->show($this->projectId);
        $refs = $this->ticketService->list($this->projectId);
        $model = $this->builder->build($project, $refs, showDone: false);

        self::assertSame('', $model->tickets[0]->statusComment);
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
