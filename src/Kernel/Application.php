<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Kernel;

use AiToolset\AiDashboard\Http\HomeController;
use AiToolset\AiDashboard\Http\IdeOpener;
use AiToolset\AiDashboard\Http\NotFoundSubscriber;
use AiToolset\AiDashboard\Http\PhaseEditController;
use AiToolset\AiDashboard\Http\PhaseStatusController;
use AiToolset\AiDashboard\Http\ProjectListController;
use AiToolset\AiDashboard\Http\RequestLogSubscriber;
use AiToolset\AiDashboard\Http\StaticController;
use AiToolset\AiDashboard\Http\TaskEditController;
use AiToolset\AiDashboard\Http\TaskStatusController;
use AiToolset\AiDashboard\Http\TicketCreateController;
use AiToolset\AiDashboard\Http\TicketDeepController;
use AiToolset\AiDashboard\Http\TicketEditController;
use AiToolset\AiDashboard\Http\TicketIdeController;
use AiToolset\AiDashboard\Http\TicketListController;
use AiToolset\AiDashboard\Http\TicketWorkspaceResolver;
use AiToolset\AiDashboard\Http\TmCliRunner;
use AiToolset\AiDashboard\View\ProjectListViewBuilder;
use AiToolset\AiDashboard\View\TicketDeepViewBuilder;
use AiToolset\AiDashboard\View\TicketListViewBuilder;
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
use AiToolset\AiLib\Services\LogService;
use AiToolset\AiLib\Services\Ordering;
use AiToolset\AiLib\Services\PhaseService;
use AiToolset\AiLib\Services\ProjectService;
use AiToolset\AiLib\Services\TaskService;
use AiToolset\AiLib\Services\TicketService;
use AiToolset\AiLib\Services\TransitionRecorder;
use Composer\InstalledVersions;
use PDO;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final readonly class Application
{
    private RequestStack $requestStack;
    private Environment $twig;
    private HttpKernel $httpKernel;

    /**
     * @param string|null $tmBinaryPath Absolute path to the `tm` binary invoked
     *     by ticket/phase/task edit saves (ticket 153) through TmCliRunner.
     *     Defaults to the `bin/tm` inside the installed `ai-toolset/tm`
     *     Composer package (ticket 269, requirement 685/684) — resolved via
     *     `Composer\InstalledVersions::getInstallPath()`, so it works
     *     unchanged whether `ai-toolset/tm` landed as a path repository
     *     symlink (development layout) or a real vendor install (composer
     *     install layout). Tests override this to point at a fake stub
     *     script (see tests/Http/fixtures/fake-tm) instead of a real
     *     ai-lib-backed database.
     * @param string|null $ideCommand The `ide_command` word read from
     *     `~/.ai-dashboard/config.toml` (ticket 164, requirement 202) by
     *     `public/index.php` via `DashboardConfig::readIdeCommand()`. `null` means the
     *     IDE feature is off: no default is resolved here, unlike
     *     `$tmBinaryPath`, because there is no sibling binary to fall back
     *     to — a GUI editor is either configured or it is not.
     */
    public function __construct(
        PDO $pdo,
        ?string $tmBinaryPath = null,
        ?string $ideCommand = null,
    ) {
        $this->requestStack = new RequestStack();
        $this->twig = $this->buildTwig();
        $this->httpKernel = $this->buildKernel(
            $pdo,
            $tmBinaryPath ?? self::defaultTmBinaryPath(),
            $ideCommand,
        );
    }

    /**
     * Resolves `bin/tm` inside the installed `ai-toolset/tm` Composer
     * package (ticket 269, requirements 684/685) instead of guessing a
     * sibling-checkout path — works unchanged whether `ai-toolset/tm`
     * landed as a path repository symlink (development layout) or a real
     * vendor install (composer install layout), because
     * `InstalledVersions::getInstallPath()` reads the lock file's recorded
     * install path either way.
     *
     * Public so it is unit-testable without constructing an `Application`
     * (which needs a `PDO`). Throws `OutOfBoundsException` (from
     * `getInstallPath()`) if `ai-toolset/tm` is somehow missing from the
     * lock file — that is a broken install, not a condition this method
     * should paper over, so it is left to propagate like the other startup
     * failures in this package (see `DashboardConfig`, `SchemaMigrator` in
     * `public/index.php`).
     */
    public static function defaultTmBinaryPath(): string
    {
        $installPath = InstalledVersions::getInstallPath('ai-toolset/tm');
        if ($installPath === null) {
            throw new \RuntimeException('ai-toolset/tm has no install path (metapackage or replaced) — cannot locate the tm binary.');
        }

        return $installPath . '/bin/tm';
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        return $this->httpKernel->handle($request, $type, $catch);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->httpKernel->terminate($request, $response);
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter(
            'strip_phase_prefix',
            static fn(string $name): string => (string) preg_replace('/^Phase\s+\d+:\s*/i', '', $name),
        ));

        return $twig;
    }

    private function buildKernel(PDO $pdo, string $tmBinaryPath, ?string $ideCommand): HttpKernel
    {
        $tmCliRunner = new TmCliRunner($tmBinaryPath);

        $clock = new SystemClock();
        $config = Config::default();
        $projectRepository = new ProjectRepository($pdo);
        $ticketRepository = new TicketRepository($pdo);
        $phaseRepository = new PhaseRepository($pdo);
        $taskRepository = new TaskRepository($pdo);
        $logEntryRepository = new LogEntryRepository($pdo);
        $statusTransitionRepository = new StatusTransitionRepository($pdo);
        $requirementRepository = new RequirementRepository($pdo);
        $questionRepository = new QuestionRepository($pdo);

        $transitionRecorder = new TransitionRecorder($pdo, $clock);
        $ordering = new Ordering();

        $projectService = new ProjectService(
            repository: $projectRepository,
            clock: $clock,
            ticketRepository: $ticketRepository,
        );

        $ticketService = new TicketService(
            pdo: $pdo,
            projectRepository: $projectRepository,
            repository: $ticketRepository,
            recorder: $transitionRecorder,
            clock: $clock,
            config: $config,
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
            logEntryRepository: $logEntryRepository,
            statusTransitionRepository: $statusTransitionRepository,
            requirementRepository: $requirementRepository,
            questionRepository: $questionRepository,
        );

        $phaseService = new PhaseService(
            pdo: $pdo,
            ticketRepository: $ticketRepository,
            repository: $phaseRepository,
            recorder: $transitionRecorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
            taskRepository: $taskRepository,
        );

        $taskService = new TaskService(
            pdo: $pdo,
            phaseRepository: $phaseRepository,
            repository: $taskRepository,
            recorder: $transitionRecorder,
            ordering: $ordering,
            clock: $clock,
            config: $config,
        );

        $logService = new LogService(
            ticketRepository: $ticketRepository,
            phaseRepository: $phaseRepository,
            taskRepository: $taskRepository,
            repository: $logEntryRepository,
            clock: $clock,
            config: $config,
        );

        $projectListController = new ProjectListController(
            twig: $this->twig,
            projectService: $projectService,
            viewBuilder: new ProjectListViewBuilder(),
        );

        $ticketListController = new TicketListController(
            twig: $this->twig,
            projectService: $projectService,
            ticketService: $ticketService,
            viewBuilder: new TicketListViewBuilder(),
            tmCliRunner: $tmCliRunner,
        );

        $ticketDeepController = new TicketDeepController(
            twig: $this->twig,
            projectService: $projectService,
            ticketService: $ticketService,
            logService: $logService,
            viewBuilder: new TicketDeepViewBuilder(),
            projectList: $projectListController,
            ticketList: $ticketListController,
            ideConfigured: $ideCommand !== null,
        );

        $homeController = new HomeController(
            projectList: $projectListController,
            ticketList: $ticketListController,
            ticketDeep: $ticketDeepController,
        );

        // Ticket 164, requirement 201: built only when an IDE command is
        // configured, so the "ticket-open-ide" route below is registered
        // only in that case too — with none configured the route simply
        // does not exist, keeping the null out of both this controller and
        // IdeOpener's constructor.
        $ticketIdeController = $ideCommand !== null
            ? new TicketIdeController(
                ticketService: $ticketService,
                projectService: $projectService,
                workspaceResolver: new TicketWorkspaceResolver(),
                ideOpener: new IdeOpener($ideCommand),
            )
            : null;

        $ticketEditController = new TicketEditController(
            ticketService: $ticketService,
            tmCliRunner: $tmCliRunner,
            ticketDeepController: $ticketDeepController,
        );

        $ticketCreateController = new TicketCreateController(
            projectService: $projectService,
            tmCliRunner: $tmCliRunner,
            ticketListController: $ticketListController,
        );

        $phaseEditController = new PhaseEditController(
            phaseService: $phaseService,
            ticketService: $ticketService,
            tmCliRunner: $tmCliRunner,
            ticketDeepController: $ticketDeepController,
        );

        $taskEditController = new TaskEditController(
            taskService: $taskService,
            phaseService: $phaseService,
            ticketService: $ticketService,
            tmCliRunner: $tmCliRunner,
            ticketDeepController: $ticketDeepController,
        );

        $taskStatusController = new TaskStatusController(
            taskService: $taskService,
            phaseService: $phaseService,
            ticketService: $ticketService,
            tmCliRunner: $tmCliRunner,
        );

        $phaseStatusController = new PhaseStatusController(
            phaseService: $phaseService,
            ticketService: $ticketService,
            tmCliRunner: $tmCliRunner,
        );

        $routes = new RouteCollection();
        $routes->add('home', new Route('/', ['_controller' => $homeController]));
        $routes->add('static', new Route('/static/{path}', ['_controller' => new StaticController()], ['path' => '.+']));
        $routes->add('ticket-edit', new Route(
            '/ticket/{id}/edit',
            ['_controller' => $ticketEditController],
            ['id' => '\d+'],
            [],
            null,
            [],
            ['POST'],
        ));
        $routes->add('phase-edit', new Route(
            '/phase/{id}/edit',
            ['_controller' => $phaseEditController],
            ['id' => '\d+'],
            [],
            null,
            [],
            ['POST'],
        ));
        $routes->add('task-edit', new Route(
            '/task/{id}/edit',
            ['_controller' => $taskEditController],
            ['id' => '\d+'],
            [],
            null,
            [],
            ['POST'],
        ));
        $routes->add('phase-status', new Route(
            '/phase/{id}/status',
            ['_controller' => $phaseStatusController],
            ['id' => '\d+'],
            [],
            null,
            [],
            ['POST'],
        ));
        $routes->add('task-status', new Route(
            '/task/{id}/status',
            ['_controller' => $taskStatusController],
            ['id' => '\d+'],
            [],
            null,
            [],
            ['POST'],
        ));
        $routes->add('ticket-create', new Route(
            '/project/{id}/ticket/create',
            ['_controller' => $ticketCreateController],
            ['id' => '\d+'],
            [],
            null,
            [],
            ['POST'],
        ));
        if ($ticketIdeController instanceof TicketIdeController) {
            $routes->add('ticket-open-ide', new Route(
                '/ticket/{id}/open-ide',
                ['_controller' => $ticketIdeController],
                ['id' => '\d+'],
                [],
                null,
                [],
                ['POST'],
            ));
        }

        $matcher = new UrlMatcher($routes, new RequestContext());
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RouterListener($matcher, $this->requestStack));
        $dispatcher->addSubscriber(new NotFoundSubscriber($this->twig));
        $dispatcher->addSubscriber(new RequestLogSubscriber());

        return new HttpKernel(
            $dispatcher,
            new ControllerResolver(),
            $this->requestStack,
            new ArgumentResolver(),
        );
    }
}
