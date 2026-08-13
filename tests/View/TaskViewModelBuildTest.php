<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\View;

use AiToolset\AiDashboard\View\TicketDeepViewBuilder;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\PhaseIn;
use AiToolset\AiLib\Schemas\PhaseOut;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\ProjectOut;
use AiToolset\AiLib\Schemas\TaskIn;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Schemas\TicketOut;
use AiToolset\AiLib\Services\Ordering;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TaskService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;

final class TaskViewModelBuildTest extends BaseViewTest
{
    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;
    private TaskService $taskService;
    private TicketDeepViewBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = new SystemClock();
        $config = Config::default();
        $projectRepository = new ProjectRepository($this->pdo);
        $ticketRepository = new TicketRepository($this->pdo);
        $phaseRepository = new PhaseRepository($this->pdo);
        $taskRepository = new TaskRepository($this->pdo);
        $recorder = new TransitionRecorder($this->pdo, $clock);
        $ordering = new Ordering();

        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: $ticketRepository,
        );
        $this->ticketService = new TicketService(
            pdo: $this->pdo,
            projectRepository: $projectRepository,
            repository: $ticketRepository,
            recorder: $recorder,
            clock: $clock,
            config: $config,
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
        );
        $this->phaseService = new PhaseService(
            pdo: $this->pdo,
            ticketRepository: $ticketRepository,
            repository: $phaseRepository,
            recorder: $recorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
            taskRepository: $taskRepository,
        );
        $this->taskService = new TaskService(
            pdo: $this->pdo,
            phaseRepository: $phaseRepository,
            repository: $taskRepository,
            recorder: $recorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
        );
        $this->builder = new TicketDeepViewBuilder();
    }

    /** @return array{0: ProjectOut, 1: TicketOut, 2: PhaseOut} */
    private function seedProjectTicketPhase(string $phaseName = 'Phase 1: Implementation'): array
    {
        $project = $this->projectService->add(new ProjectIn(name: 'proj', path: '/proj'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'ticket'));
        $this->ticketService->set($ticket->id, status: 'active');
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: $phaseName));

        return [$project, $ticket, $phase];
    }

    #[Test]
    public function it_sets_has_summary_true_for_done_task_without_result(): void
    {
        [$project, $ticket, $phase] = $this->seedProjectTicketPhase();
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Task', model: null));
        $this->taskService->set($task->id, status: 'active');
        $this->taskService->set($task->id, status: 'done');

        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($project, $deep);
        $taskVm = $model->phases[0]->tasks[0];

        self::assertTrue($taskVm->hasSummary);
        self::assertSame('', $taskVm->result);
    }

    #[Test]
    public function it_sets_has_desc_false_when_description_equals_name(): void
    {
        [$project, $ticket, $phase] = $this->seedProjectTicketPhase();
        $this->taskService->add(new TaskIn(
            phaseId: $phase->id,
            name: 'Same text',
            model: null,
            description: 'Same text',
        ));

        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($project, $deep);

        self::assertFalse($model->phases[0]->tasks[0]->hasDesc);
    }

    #[Test]
    public function it_sets_has_desc_true_when_description_differs_from_name(): void
    {
        [$project, $ticket, $phase] = $this->seedProjectTicketPhase();
        $this->taskService->add(new TaskIn(
            phaseId: $phase->id,
            name: 'Short title',
            model: null,
            description: 'A longer description that differs.',
        ));

        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($project, $deep);

        self::assertTrue($model->phases[0]->tasks[0]->hasDesc);
    }

    #[Test]
    public function it_propagates_actor_from_task_deep_out_to_view_model(): void
    {
        [$project, $ticket, $phase] = $this->seedProjectTicketPhase();
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Agent task', model: null, actor: 'agent'));
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Human task', model: null, actor: 'human'));

        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($project, $deep);

        self::assertSame('agent', $model->phases[0]->tasks[0]->actor);
        self::assertSame('human', $model->phases[0]->tasks[1]->actor);
    }

    #[Test]
    public function it_propagates_model_from_task_deep_out_to_view_model(): void
    {
        [$project, $ticket, $phase] = $this->seedProjectTicketPhase();
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Cheap task', model: 'claude-haiku-4-5'));
        $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Unset task', model: null));

        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($project, $deep);

        self::assertSame('claude-haiku-4-5', $model->phases[0]->tasks[0]->model);
        self::assertNull($model->phases[0]->tasks[1]->model);
    }
}
