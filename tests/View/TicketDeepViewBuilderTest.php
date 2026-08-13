<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\View;

use AiToolset\AiDashboard\View\TicketDeepViewBuilder;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\LogEntryRepository;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\QuestionRepository;
use AiToolset\AiLib\Repositories\RequirementRepository;
use AiToolset\AiLib\Repositories\StatusTransitionRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\LogEntryIn;
use AiToolset\AiLib\Schemas\LogEntryOut;
use AiToolset\AiLib\Schemas\PhaseDeepOut;
use AiToolset\AiLib\Schemas\PhaseIn;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\QuestionIn;
use AiToolset\AiLib\Schemas\RequirementIn;
use AiToolset\AiLib\Schemas\TaskIn;
use AiToolset\AiLib\Schemas\TicketDeepOut;
use AiToolset\AiLib\Schemas\TicketIn;
use AiToolset\AiLib\Services\LogService;
use AiToolset\AiLib\Services\Ordering;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\QuestionService;
use AiToolset\AiLib\Services\RequirementService;
use AiToolset\AiLib\Services\TaskService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use PHPUnit\Framework\Attributes\Test;

final class TicketDeepViewBuilderTest extends BaseViewTest
{
    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;
    private TaskService $taskService;
    private LogService $logService;
    private RequirementService $requirementService;
    private QuestionService $questionService;
    private TicketDeepViewBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new SystemClock();
        $projectRepository = new ProjectRepository($this->pdo);
        $ticketRepository = new TicketRepository($this->pdo);
        $phaseRepository = new PhaseRepository($this->pdo);
        $taskRepository = new TaskRepository($this->pdo);
        $logEntryRepository = new LogEntryRepository($this->pdo);
        $statusTransitionRepository = new StatusTransitionRepository($this->pdo);
        $recorder = new TransitionRecorder($this->pdo, $clock);
        $ordering = new Ordering();
        $config = Config::default();

        $this->projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: $ticketRepository,
        );

        $requirementRepository = new RequirementRepository($this->pdo);
        $questionRepository = new QuestionRepository($this->pdo);

        $this->ticketService = new TicketService(
            pdo: $this->pdo,
            projectRepository: $projectRepository,
            repository: $ticketRepository,
            recorder: $recorder,
            clock: $clock,
            config: $config,
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
            requirementRepository: $requirementRepository,
            questionRepository: $questionRepository,
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
            logEntryRepository: $logEntryRepository,
            statusTransitionRepository: $statusTransitionRepository,
        );

        $this->taskService = new TaskService(
            pdo: $this->pdo,
            phaseRepository: $phaseRepository,
            repository: $taskRepository,
            recorder: $recorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
            logEntryRepository: $logEntryRepository,
            statusTransitionRepository: $statusTransitionRepository,
        );

        $this->logService = new LogService(
            ticketRepository: $ticketRepository,
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
            repository: $logEntryRepository,
            clock: $clock,
            config: $config,
        );

        $this->requirementService = new RequirementService(
            pdo: $this->pdo,
            ticketRepository: $ticketRepository,
            repository: $requirementRepository,
            ordering: $ordering,
            config: $config,
        );

        $this->questionService = new QuestionService(
            pdo: $this->pdo,
            ticketRepository: $ticketRepository,
            repository: $questionRepository,
            clock: $clock,
            config: $config,
            taskRepository: $taskRepository,
        );

        $this->builder = new TicketDeepViewBuilder();
    }

    #[Test]
    public function it_marks_every_phase_closed_regardless_of_status(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $ph1 = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: done', status: 'done'));
        $ph2 = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 2: active', status: 'active'));
        $ph3 = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 3: pending'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertFalse($model->phases[0]->isOpen, 'done phase should be closed');
        self::assertFalse($model->phases[1]->isOpen, 'active phase should be closed (open state lives in localStorage)');
        self::assertFalse($model->phases[2]->isOpen, 'pending phase should be closed');

        // suppress unused variable warnings from IDE
        unset($ph1, $ph2, $ph3);
    }

    #[Test]
    public function it_maps_every_log_entry_to_the_logs_list_in_order(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);

        $logEntries = [];
        for ($i = 1; $i <= 5; $i++) {
            $logEntries[] = new LogEntryOut(
                id: $i,
                ticketId: $ticket->id,
                phaseId: null,
                taskId: null,
                logType: 'note',
                title: "title {$i}",
                aiContent: "detail {$i}",
                timestamp: new \DateTimeImmutable("2026-05-12 10:0{$i}:30"),
            );
        }

        $model = $this->builder->build($projectOut, $deep, $logEntries);

        self::assertCount(5, $model->logs);
        self::assertSame('title 1', $model->logs[0]->title);
        self::assertSame('title 5', $model->logs[4]->title);
        self::assertSame('detail 1', $model->logs[0]->detail);
        self::assertSame('detail 5', $model->logs[4]->detail);
        self::assertSame('note', $model->logs[0]->logType);
        self::assertSame('2026-05-12 10:01', $model->logs[0]->timestamp);
        self::assertSame('2026-05-12 10:05', $model->logs[4]->timestamp);
    }

    #[Test]
    public function it_leaves_the_logs_list_empty_when_there_are_no_entries(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);

        $model = $this->builder->build($projectOut, $deep, []);

        self::assertSame([], $model->logs);
    }

    #[Test]
    public function it_propagates_type_to_ticket_header_view_model(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't', type: 'workflow'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame('workflow', $model->header->type);
    }

    #[Test]
    public function it_exposes_phase_description_and_ai_description_on_the_phase_view_model(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $ph = $this->phaseService->add(new PhaseIn(
            ticketId: $ticket->id,
            name: 'Phase 1: work',
            description: 'human readable description',
            aiDescription: 'ai detailed description',
        ));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame('human readable description', $model->phases[0]->description);
        self::assertSame('ai detailed description', $model->phases[0]->aiDescription);

        unset($ph);
    }

    #[Test]
    public function it_populates_all_new_fields_on_task(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work'));
        $task = $this->taskService->add(new TaskIn(
            phaseId: $phase->id,
            name: 'Task one',
            model: null,
            aiDescription: 'ai detailed task description',
        ));
        // Trigger a status transition so transitions list has more than the initial insert entry.
        $this->taskService->set($task->id, status: 'active');
        $this->logService->add(new LogEntryIn(
            logType: 'note',
            title: 'progress note',
            aiContent: 'did some work',
            taskId: $task->id,
        ));

        $projectOut = $this->projectService->show($project->id);
        $ticketDeep = $this->ticketService->showDeep($ticket->id);
        $taskDeep = $this->taskService->showDeep($task->id);

        // TicketService::showDeep populates task DeepOut with empty logs/transitions; substitute the
        // task-level deep representation (which the TaskService does populate) so the builder sees
        // the real logs and transitions for this task.
        $phaseDeep = $ticketDeep->phases[0];
        $rebuiltPhase = new PhaseDeepOut(
            id: $phaseDeep->id,
            ticketId: $phaseDeep->ticketId,
            name: $phaseDeep->name,
            description: $phaseDeep->description,
            aiDescription: $phaseDeep->aiDescription,
            order: $phaseDeep->order,
            status: $phaseDeep->status,
            maxAttempts: $phaseDeep->maxAttempts,
            attempts: $phaseDeep->attempts,
            createdAt: $phaseDeep->createdAt,
            updatedAt: $phaseDeep->updatedAt,
            archivedAt: $phaseDeep->archivedAt,
            tasks: [$taskDeep],
            logs: $phaseDeep->logs,
            transitions: $phaseDeep->transitions,
        );
        $rebuiltTicket = new TicketDeepOut(
            id: $ticketDeep->id,
            projectId: $ticketDeep->projectId,
            name: $ticketDeep->name,
            description: $ticketDeep->description,
            aiDescription: $ticketDeep->aiDescription,
            status: $ticketDeep->status,
            type: $ticketDeep->type,
            createdAt: $ticketDeep->createdAt,
            updatedAt: $ticketDeep->updatedAt,
            archivedAt: $ticketDeep->archivedAt,
            requirements: $ticketDeep->requirements,
            questions: $ticketDeep->questions,
            phases: [$rebuiltPhase],
            logs: $ticketDeep->logs,
            transitions: $ticketDeep->transitions,
            sessionId: $ticketDeep->sessionId,
        );

        $model = $this->builder->build($projectOut, $rebuiltTicket);
        $taskVm = $model->phases[0]->tasks[0];

        self::assertSame('ai detailed task description', $taskVm->aiDescription);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $taskVm->createdAt);
        self::assertNotEmpty($taskVm->transitions);
        self::assertSame('progress note', $taskVm->logs[0]->title);
    }

    #[Test]
    public function it_sets_empty_strings_for_phase_description_fields_when_not_provided(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $ph = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: bare'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame('', $model->phases[0]->description);
        self::assertSame('', $model->phases[0]->aiDescription);

        unset($ph);
    }

    #[Test]
    public function it_maps_requirements_to_view_models_in_order_with_all_fields(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $this->requirementService->add(new RequirementIn(
            ticketId: $ticket->id,
            name: 'First requirement',
            description: 'human description one',
            aiDescription: 'ai description one',
            verification: 'unverified',
        ));
        $this->requirementService->add(new RequirementIn(
            ticketId: $ticket->id,
            name: 'Second requirement',
            description: 'human description two',
            aiDescription: 'ai description two',
            verification: 'met',
        ));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertCount(2, $model->requirements);
        self::assertSame('First requirement', $model->requirements[0]->name);
        self::assertSame('human description one', $model->requirements[0]->description);
        self::assertSame('ai description one', $model->requirements[0]->aiDescription);
        self::assertSame('unverified', $model->requirements[0]->verification);
        self::assertSame(1, $model->requirements[0]->order);
        self::assertSame('Second requirement', $model->requirements[1]->name);
        self::assertSame('met', $model->requirements[1]->verification);
        self::assertSame(2, $model->requirements[1]->order);
    }

    #[Test]
    public function it_produces_an_empty_requirements_array_when_ticket_has_no_requirements(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame([], $model->requirements);
    }

    #[Test]
    public function it_maps_an_open_question_to_the_needs_you_group(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $this->questionService->add(new QuestionIn(
            ticketId: $ticket->id,
            name: 'Which queue backend?',
            question: 'Should the worker use Redis or the existing SQLite queue?',
            kind: 'ask',
            model: 'claude-sonnet',
            background: 'Two queue backends are already used elsewhere in the toolset.',
            recommendation: 'Reuse the existing SQLite queue for consistency.',
        ));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertCount(1, $model->questions);
        $q = $model->questions[0];
        self::assertSame('Which queue backend?', $q->name);
        self::assertSame('ask', $q->kind);
        self::assertSame('open', $q->state);
        self::assertSame('open', $q->group);
        self::assertSame('needs you', $q->groupLabel);
        self::assertSame('Should the worker use Redis or the existing SQLite queue?', $q->question);
        self::assertSame('Two queue backends are already used elsewhere in the toolset.', $q->background);
        self::assertSame('Reuse the existing SQLite queue for consistency.', $q->recommendation);
        self::assertNull($q->answer);
    }

    #[Test]
    public function it_maps_a_resolved_but_unprocessed_question_to_the_needs_agent_group_with_its_resolution(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $question = $this->questionService->add(new QuestionIn(
            ticketId: $ticket->id,
            name: 'Rate limit value?',
            question: 'What request rate limit should the API enforce?',
            kind: 'check',
            model: 'claude-sonnet',
        ));
        $this->questionService->resolve($question->id, state: 'answered', answer: '100 requests per minute.', resolutionQuality: 'direct');

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        $q = $model->questions[0];
        self::assertSame('check', $q->kind);
        self::assertSame('answered', $q->state);
        self::assertSame('resolved_unprocessed', $q->group);
        self::assertSame('needs agent', $q->groupLabel);
        self::assertSame('100 requests per minute.', $q->answer);
    }

    #[Test]
    public function it_maps_a_processed_question_to_the_done_group(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $question = $this->questionService->add(new QuestionIn(
            ticketId: $ticket->id,
            name: 'Naming convention?',
            question: 'Should the new field be camelCase?',
            kind: 'ask',
            model: 'claude-sonnet',
        ));
        $this->questionService->resolve($question->id, state: 'accepted', answer: null, resolutionQuality: 'direct');
        $this->questionService->markProcessed($question->id);

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        $q = $model->questions[0];
        self::assertSame('accepted', $q->state);
        self::assertSame('done', $q->group);
        self::assertSame('done', $q->groupLabel);
    }

    #[Test]
    public function it_maps_a_withdrawn_question_to_the_done_group(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $question = $this->questionService->add(new QuestionIn(
            ticketId: $ticket->id,
            name: 'Cache layer?',
            question: 'Should the report page cache its query results?',
            kind: 'ask',
            model: 'claude-sonnet',
        ));
        $this->questionService->withdraw($question->id, 'The page was dropped from scope.');

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        $q = $model->questions[0];
        self::assertSame('withdrawn', $q->state);
        self::assertSame('done', $q->group);
        self::assertSame('done', $q->groupLabel);
    }

    #[Test]
    public function it_produces_an_empty_questions_array_when_ticket_has_no_questions(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame([], $model->questions);
    }

    #[Test]
    public function it_keeps_the_full_untruncated_text_on_the_view_model_for_long_ticket_and_phase_descriptions(): void
    {
        // Truncation on ticket_deep.html.twig is a CSS/visual concern (a fixed
        // line-clamp, ticket 153 task 1271) — the view model must still carry
        // the complete text so the ticket/phase panel can render it in full.
        $longObjective = str_repeat('objective line. ', 40);
        $longAiDescription = str_repeat('ai description line. ', 40);
        $longPhaseDescription = str_repeat('phase description line. ', 40);
        $longPhaseAiDescription = str_repeat('phase ai description line. ', 40);

        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(
            projectId: $project->id,
            name: 't',
            description: $longObjective,
            aiDescription: $longAiDescription,
        ));
        $ph = $this->phaseService->add(new PhaseIn(
            ticketId: $ticket->id,
            name: 'Phase 1: work',
            description: $longPhaseDescription,
            aiDescription: $longPhaseAiDescription,
        ));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame($longObjective, $model->header->objective);
        self::assertSame($longAiDescription, $model->header->aiDescription);
        self::assertSame($longPhaseDescription, $model->phases[0]->description);
        self::assertSame($longPhaseAiDescription, $model->phases[0]->aiDescription);

        unset($ph);
    }

    #[Test]
    public function it_propagates_max_attempts_and_attempts_from_phase_deep_out_to_view_model(): void
    {
        // Ticket 179 task 2072, requirement 306: PhaseDeepOut now carries maxAttempts/attempts
        // (mirroring TaskDeepOut's existing fields); the builder must thread them through to
        // PhaseRowViewModel unchanged so the template can render the "N/M runs" marker.
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: work', maxAttempts: 3));
        $this->phaseService->set($phase->id, attempts: 1);

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame(3, $model->phases[0]->maxAttempts);
        self::assertSame(1, $model->phases[0]->attempts);
    }

    #[Test]
    public function it_defaults_phase_max_attempts_and_attempts_to_zero_when_unset(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: plain'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);
        $model = $this->builder->build($projectOut, $deep);

        self::assertSame(0, $model->phases[0]->maxAttempts);
        self::assertSame(0, $model->phases[0]->attempts);
    }

    #[Test]
    public function it_carries_ide_configured_through_to_the_view_model(): void
    {
        // Ticket 164, requirement 202: TicketDeepController passes this
        // flag once per Application instance, reflecting whether
        // ~/.ai-dashboard/config.toml carries a usable ide_command. The
        // builder must thread it through unchanged so the template (added
        // by the follow-up round-buttons task) can render the "i" button
        // conditionally.
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);

        $model = $this->builder->build($projectOut, $deep, ideConfigured: true);

        self::assertTrue($model->ideConfigured);
    }

    #[Test]
    public function it_defaults_ide_configured_to_false(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 't'));

        $projectOut = $this->projectService->show($project->id);
        $deep = $this->ticketService->showDeep($ticket->id);

        $model = $this->builder->build($projectOut, $deep);

        self::assertFalse($model->ideConfigured);
    }

}
