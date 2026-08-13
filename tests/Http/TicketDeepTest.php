<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiLib\Domain\Config;
use AiToolset\AiLib\Domain\SystemClock;
use AiToolset\AiLib\Repositories\LogEntryRepository;
use AiToolset\AiLib\Repositories\PhaseRepository;
use AiToolset\AiLib\Repositories\ProjectRepository;
use AiToolset\AiLib\Repositories\QuestionRepository;
use AiToolset\AiLib\Repositories\RequirementRepository;
use AiToolset\AiLib\Repositories\TaskRepository;
use AiToolset\AiLib\Repositories\TicketRepository;
use AiToolset\AiLib\Schemas\LogEntryIn;
use AiToolset\AiLib\Schemas\PhaseIn;
use AiToolset\AiLib\Schemas\ProjectIn;
use AiToolset\AiLib\Schemas\QuestionIn;
use AiToolset\AiLib\Schemas\RequirementIn;
use AiToolset\AiLib\Schemas\TaskIn;
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
use Symfony\Component\HttpFoundation\Request;

final class TicketDeepTest extends BaseHttpTest
{
    private const string TM_STUB = __DIR__ . '/fixtures/fake-tm';
    private const string IDE_STUB = __DIR__ . '/fixtures/fake-ide';

    private ProjectService $projectService;
    private TicketService $ticketService;
    private PhaseService $phaseService;
    private TaskService $taskService;
    private LogService $logService;
    private RequirementService $requirementService;
    private QuestionService $questionService;

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
        $requirementRepository = new RequirementRepository($this->pdo);

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
            requirementRepository: $requirementRepository,
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

        $this->logService = new LogService(
            ticketRepository: $ticketRepository,
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
            repository: new LogEntryRepository($this->pdo),
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
            repository: new QuestionRepository($this->pdo),
            clock: $clock,
            config: $config,
            taskRepository: $taskRepository,
        );
    }

    #[Test]
    public function it_renders_ticket_deep_view_for_known_project_and_ticket(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('my-ticket', (string) $response->getContent());
    }

    #[Test]
    public function it_sets_the_browser_tab_title_to_the_ticket_id_and_name(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);

        self::assertStringContainsString(
            "<title>{$ticket->id} - my-ticket</title>",
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function it_renders_the_ide_button_when_an_ide_command_is_configured(): void
    {
        $this->app = new Application($this->pdo, self::TM_STUB, ideCommand: self::IDE_STUB);

        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '/<button[^>]*class="ticket-ide-btn"[^>]*data-ticket-id="' . $ticket->id . '"[^>]*>/',
            $html,
        );
    }

    #[Test]
    public function it_does_not_render_the_ide_button_when_no_ide_command_is_configured(): void
    {
        // BaseHttpTest builds $this->app with no ideCommand argument, so
        // TicketDeepController's $ideConfigured stays false.
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertStringNotContainsString('ticket-ide-btn', $html);
    }

    #[Test]
    public function it_renders_the_terminal_error_dialog_closed_and_empty(): void
    {
        // The dialog is always rendered inert; app.js populates and opens it
        // client-side on an IDE-open or status-update failure (see app.js).
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<dialog id="terminal-error" class="terminal-error">', $html);
        self::assertStringContainsString('<p class="terminal-error-message"></p>', $html);
    }

    #[Test]
    public function it_renders_reshaped_log_section_with_section_fold_and_per_entry_detail_folds(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        // Three entries: two carry ai_content (a per-entry detail fold), one does not.
        $this->logService->add(new LogEntryIn(
            logType: 'progress',
            title: 'logtitle-alpha',
            aiContent: 'aicontent-alpha-detail',
            ticketId: $ticket->id,
        ));
        $this->logService->add(new LogEntryIn(
            logType: 'note',
            title: 'logtitle-bravo-nodetail',
            ticketId: $ticket->id,
        ));
        $this->logService->add(new LogEntryIn(
            logType: 'progress',
            title: 'logtitle-charlie',
            aiContent: 'aicontent-charlie-detail',
            ticketId: $ticket->id,
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());

        // The "Log" heading is the toggle: a <label> wraps the heading text
        // and points at the hidden #logs-toggle checkbox.
        self::assertStringContainsString('id="logs-toggle"', $html);
        self::assertMatchesRegularExpression(
            '/<h2 class="section-label"><label for="logs-toggle">Log<\/label><\/h2>/',
            $html,
        );

        // Every entry is present, each rendered as one line carrying its title.
        self::assertStringContainsString('<span class="log-title">logtitle-alpha</span>', $html);
        self::assertStringContainsString('<span class="log-title">logtitle-bravo-nodetail</span>', $html);
        self::assertStringContainsString('<span class="log-title">logtitle-charlie</span>', $html);

        // Newest-first: the last-added entry appears before the first-added one in the markup.
        self::assertLessThan(
            strpos($html, 'logtitle-alpha'),
            strpos($html, 'logtitle-charlie'),
            'Log entries must render newest-first.',
        );

        // Per-entry detail fold present for entries with non-empty ai_content; the detail text is in the HTML.
        self::assertStringContainsString('aicontent-alpha-detail', $html);
        self::assertStringContainsString('aicontent-charlie-detail', $html);

        // Two entries have ai_content, so exactly two per-entry detail folds exist as <details> elements.
        self::assertSame(2, substr_count($html, 'class="log-detail"'));

        // The no-detail entry must not carry a per-entry detail fold. The newest-first order is
        // charlie, bravo, alpha; bravo is the no-detail one, so no <details> element wraps it.
        self::assertDoesNotMatchRegularExpression(
            '/logtitle-bravo-nodetail<\/span>\s*<span class="log-time">[^<]*<\/span>\s*<\/summary>/',
            $html,
            'The entry with empty ai_content must not have a per-entry detail fold.',
        );
    }

    #[Test]
    public function it_renders_ticket_log_section_with_all_scope_levels_and_level_markers(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'task-one', model: null));

        // One entry per scope on the same ticket: ticket, phase, task.
        $this->logService->add(new LogEntryIn(
            logType: 'progress',
            title: 'logtitle-ticket-scoped',
            ticketId: $ticket->id,
        ));
        $this->logService->add(new LogEntryIn(
            logType: 'progress',
            title: 'logtitle-phase-scoped',
            phaseId: $phase->id,
        ));
        $this->logService->add(new LogEntryIn(
            logType: 'progress',
            title: 'logtitle-task-scoped',
            taskId: $task->id,
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());

        // All three entries appear in the rendered HTML.
        self::assertStringContainsString('logtitle-ticket-scoped', $html);
        self::assertStringContainsString('logtitle-phase-scoped', $html);
        self::assertStringContainsString('logtitle-task-scoped', $html);

        // Each row carries the appropriate level marker.
        self::assertStringContainsString('class="log-level">ticket<', $html);
        self::assertStringContainsString('class="log-level">phase<', $html);
        self::assertStringContainsString('class="log-level">task<', $html);
    }

    #[Test]
    public function it_renders_a_closable_log_section_even_with_no_log_entries(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="logs-toggle"', $html);
        self::assertStringContainsString('<h2 class="section-label"><label for="logs-toggle">Log</label></h2>', $html);
        self::assertStringContainsString('No log entries yet.', $html);
        self::assertStringNotContainsString('log-detail-', $html);
    }

    #[Test]
    public function it_renders_a_code_fix_typed_log_entry_in_the_regular_log_section_with_no_separate_code_fixes_section(): void
    {
        // Ticket 179 task 2077, requirement 297: the dedicated Code Fixes section (task
        // 2073) was removed. A code_fix-typed log entry now only ever appears in the
        // regular Log section, like any other log entry.
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));
        $phase = $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));
        $task = $this->taskService->add(new TaskIn(phaseId: $phase->id, name: 'Fix the parser', model: null));

        $this->logService->add(new LogEntryIn(
            logType: 'code_fix',
            title: 'fixed off-by-one',
            aiContent: 'finding: X. repair: Y. commit abc123. registered by subagent. round 1.',
            taskId: $task->id,
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());

        self::assertStringContainsString('class="log-type">code_fix<', $html);
        self::assertStringContainsString('class="log-title">fixed off-by-one</span>', $html);
        self::assertStringContainsString('finding: X. repair: Y. commit abc123. registered by subagent. round 1.', $html);

        self::assertStringNotContainsString('code-fixes', $html);
        self::assertStringNotContainsString('Code Fixes', $html);
    }

    #[Test]
    public function it_falls_through_to_ticket_list_for_unknown_ticket(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'p', path: '/p'));

        $request = Request::create("/?project={$project->id}&ticket=999999");
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('show done', (string) $response->getContent());
    }

    #[Test]
    public function it_falls_through_to_ticket_list_for_mismatched_project_and_ticket(): void
    {
        $project1 = $this->projectService->add(new ProjectIn(name: 'p1', path: '/p1'));
        $project2 = $this->projectService->add(new ProjectIn(name: 'p2', path: '/p2'));
        $ticket2 = $this->ticketService->add(new TicketIn(projectId: $project2->id, name: 't2'));

        $request = Request::create("/?project={$project1->id}&ticket={$ticket2->id}");
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('show done', (string) $response->getContent());
    }

    #[Test]
    public function it_falls_through_to_project_list_for_unknown_project(): void
    {
        $request = Request::create('/?project=999999&ticket=1');
        $response = $this->app->handle($request, catch: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('section-label', (string) $response->getContent());
    }

    #[Test]
    public function it_renders_last_breadcrumb_as_plain_span_without_current_class(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        // Last breadcrumb segment must not have class="current"
        self::assertStringNotContainsString('class="current"', $html);
    }

    #[Test]
    public function it_renders_project_name_as_link_in_ticket_deep_breadcrumb(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        // On the ticket deep page, the project name in the breadcrumb must be a link back to the ticket list
        self::assertStringContainsString('href="/?project=' . $project->id . '"', $html);
        self::assertMatchesRegularExpression('/<a[^>]*href="\/\?project=' . $project->id . '"[^>]*>my-project<\/a>/', $html);
    }

    #[Test]
    public function it_wraps_breadcrumb_separators_in_span_sep(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertStringContainsString('<span class="sep">›</span>', $html);
    }

    #[Test]
    public function it_renders_ticket_type_in_deep_view_header(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket', type: 'workflow'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertStringContainsString('workflow', $html);
    }

    #[Test]
    public function it_renders_requirements_section_above_phases_with_verification_words(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));
        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));

        $this->requirementService->add(new RequirementIn(
            ticketId: $ticket->id,
            name: 'req-met-alpha',
            verification: 'met',
        ));
        $this->requirementService->add(new RequirementIn(
            ticketId: $ticket->id,
            name: 'req-unmet-bravo',
            verification: 'unmet',
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());

        // Both requirement names appear in the HTML.
        self::assertStringContainsString('req-met-alpha', $html);
        self::assertStringContainsString('req-unmet-bravo', $html);

        // The verification words are rendered in each row.
        self::assertStringContainsString('data-verification="met"', $html);
        self::assertStringContainsString('data-verification="unmet"', $html);

        // The requirements section appears before the phases section in the HTML.
        self::assertLessThan(
            strpos($html, 'class="phases"'),
            strpos($html, 'req-met-alpha'),
            'Requirement names must appear before the phases section.',
        );
        self::assertLessThan(
            strpos($html, 'class="phases"'),
            strpos($html, 'req-unmet-bravo'),
            'Requirement names must appear before the phases section.',
        );
    }

    #[Test]
    public function it_renders_empty_requirements_state_when_ticket_has_no_requirements(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No requirements.', $html);
        self::assertStringNotContainsString('data-verification=', $html);
    }

    #[Test]
    public function it_renders_questions_section_adjacent_to_requirements_with_kind_state_and_group(): void
    {
        // Regression coverage for Application::buildKernel() actually wiring
        // QuestionRepository into TicketService — without it, showDeep()
        // always returns an empty questions array and this section would
        // stay empty in the real app regardless of what is stored.
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));
        $this->phaseService->add(new PhaseIn(ticketId: $ticket->id, name: 'Phase 1: Work'));

        $this->questionService->add(new QuestionIn(
            ticketId: $ticket->id,
            name: 'question-name-alpha',
            question: 'What backend should the worker use?',
            kind: 'ask',
            model: 'claude-sonnet',
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('question-name-alpha', $html);
        self::assertStringContainsString('data-group="open"', $html);
        self::assertStringContainsString('needs you', $html);

        // The Questions section appears between Requirements and Phases in the HTML.
        self::assertLessThan(
            strpos($html, 'class="questions"'),
            strpos($html, 'class="requirements"'),
            'Requirements must appear before Questions.',
        );
        self::assertLessThan(
            strpos($html, 'class="phases"'),
            strpos($html, 'question-name-alpha'),
            'Question names must appear before the phases section.',
        );
    }

    #[Test]
    public function it_renders_empty_questions_state_when_ticket_has_no_questions(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No questions.', $html);
        self::assertStringNotContainsString('data-group=', $html);
    }

    #[Test]
    public function it_truncates_the_objective_with_a_fixed_css_clamp_and_no_expand_control(): void
    {
        // Ticket 153 task 1271: reading the full objective now happens via
        // the ticket panel's "view" link, not an on-page toggle. The ticket
        // AI description dropped its clamp under ticket 157 task 1353 — see
        // it_renders_the_full_ai_description_in_the_always_open_details_section
        // and it_renders_the_no_details_placeholder_when_ai_description_is_empty
        // below for its current behaviour.
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(
            projectId: $project->id,
            name: 'my-ticket',
            description: 'a long objective',
            aiDescription: 'a long ai description',
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<p class="objective text-clamp">a long objective</p>', $html);

        // The old checkbox-driven expand toggle no longer exists on the page.
        self::assertStringNotContainsString('objective-toggle', $html);
    }

    #[Test]
    public function it_renders_the_full_ai_description_in_the_always_open_details_section(): void
    {
        // Ticket 157 task 1353: Details behaves like Requirements/Phases/Log —
        // a persisted toggle, full untruncated text when open, no clamp.
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $longAiDescription = str_repeat('a long ai description that spans several lines. ', 10);
        $ticket = $this->ticketService->add(new TicketIn(
            projectId: $project->id,
            name: 'my-ticket',
            aiDescription: $longAiDescription,
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="details-toggle"', $html);
        self::assertStringContainsString('<div class="ai-description">' . $longAiDescription . '</div>', $html);
        self::assertStringNotContainsString('ai-description text-clamp', $html);
    }

    #[Test]
    public function it_renders_the_no_details_placeholder_when_ai_description_is_empty(): void
    {
        // Ticket 157 task 1353 / requirement 153: the Details section always
        // renders, even with no ai_description, and can still be toggled.
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="details-toggle"', $html);
        self::assertStringContainsString('<p class="empty">No details.</p>', $html);
    }

    #[Test]
    public function it_truncates_phase_description_and_ai_description_with_a_fixed_css_clamp_and_no_toggle(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));
        $this->phaseService->add(new PhaseIn(
            ticketId: $ticket->id,
            name: 'Phase 1: work',
            description: 'a long phase description',
            aiDescription: 'a long phase ai description',
        ));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<div class="phase-desc text-clamp">a long phase description</div>', $html);
        self::assertStringContainsString('<div class="phase-ai-desc text-clamp">a long phase ai description</div>', $html);

        // The old checkbox-driven separator fold no longer exists on the page.
        self::assertStringNotContainsString('phase-ai-toggle', $html);
    }

    #[Test]
    public function it_renders_all_three_section_toggles_when_requirements_phases_and_log_are_all_empty(): void
    {
        $project = $this->projectService->add(new ProjectIn(name: 'my-project', path: '/p'));
        $ticket = $this->ticketService->add(new TicketIn(projectId: $project->id, name: 'my-ticket'));

        $request = Request::create("/?project={$project->id}&ticket={$ticket->id}");
        $response = $this->app->handle($request, catch: false);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());

        self::assertStringContainsString('id="requirements-toggle"', $html);
        self::assertStringContainsString('No requirements.', $html);

        self::assertStringContainsString('id="phases-toggle"', $html);
        self::assertStringContainsString('No plan yet for this ticket.', $html);

        self::assertStringContainsString('id="logs-toggle"', $html);
        self::assertStringContainsString('No log entries yet.', $html);
    }
}
