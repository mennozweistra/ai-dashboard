# `ai-dashboard` — Implementation Plan

This document is the build plan for `ai-dashboard`. Each phase is a contiguous unit of work that ends in a working, tested artifact. Implementation runs in order — later phases depend on earlier ones.

For what `ai-dashboard` is and what it does, see `../spec.md`. For the architecture, see `architecture/architecture.md`. For the HTTP surface, see `api/http.md`. For the page inventory, see `ui/information-architecture.md`. For the visual language, see `ui/design-system.md`.

## Conventions

**TDD throughout.** For every controller and every view-builder: write the test first, watch it fail, then write the smallest implementation that passes. View-builder tests use `tm` services to seed an in-memory SQLite database, then assert on the returned view-model. Controller tests drive the HttpKernel with a `Request` and assert on response status, headers, and a small set of substring or DOM queries against the body.

**No mocks.** All tests run against a real SQLite database in memory, opened through `tm`'s normal connection setup. The schema is built directly in test fixtures (not via migrations) so tests stay fast.

**Layer discipline from day one.** Deptrac runs in CI from phase 0. Controllers do not call `tm`'s repositories or models. Templates do not contain logic. View-builders do not return Twig output.

**Each phase ends with a dogfood step.** After the layer is implemented and tested, run the dashboard on `127.0.0.1:8766` against the user's real `tm` database and navigate the affected pages in a browser. The dogfood line in chat names the actual visual outcome, not "looks fine".

**Scope rule.** V1 stays small: the four scope decisions in `../spec.md` §3.2 govern what the dashboard does and does not do.

Two consequences worth naming: URLs identify projects by integer id (`?project=<id>`) rather than by name, and there is no Notes section. Both are documented in `../spec.md` §10 and §3.2.

## `ai-lib` prerequisites

Three changes must land in `ai-toolset/ai-lib` **before** Phase 0 of the dashboard build starts. They are sequenced first because the dashboard's tests and view-builders rely on them.

### Prerequisite A — In-memory test database helper

A new public helper class `\AiToolset\AiLib\Testing\InMemoryDatabase` with a static `create(): \PDO` method that:

- Opens a fresh `sqlite::memory:` PDO connection.
- Runs every Phinx migration in `migrations/` against that connection so the schema is fully built.
- Returns the connection ready for use.

`ai-lib`'s own `tests/Service/BaseServiceTest.php` switches to use this helper and **deletes** the hand-written `CREATE TABLE` SQL block currently inside it. This makes the Phinx migration files the single source of truth for the schema; both `ai-lib`'s tests and the dashboard's tests use the same helper. `ai-lib`'s `composer ci` must remain green after the switch.

### Prerequisite B — `outcome` column on the `tasks` table

A new Phinx migration adds an `outcome` column to the `tasks` table:

- Type: TEXT (nullable). Allowed values: `ok`, `review`, `failed`, or NULL.
- Default: NULL.

`TaskOut` and `TaskDeepOut` both expose the new field as `public ?string $outcome`.

`TaskService::set()` and the "task done" service path both accept an optional `?string $outcome` parameter. The rules:

- When a `result` value is being written, `outcome` must be supplied in the same call. Violation throws an `InvalidArgumentException` (or a new domain exception, at the implementer's discretion) with the message `outcome is required when result is set; pass outcome=ok|review|failed`.
- `outcome` may be set on its own, without changing `result`.
- When a task is reopened (returned to status `pending`), `outcome` is cleared along with `result`.

The CLI's `task done` and `task set` commands accept `--outcome=ok|review|failed`. The MCP equivalents accept the same parameter.

### Prerequisite C — Nested deep DTO for ticket detail

`TicketService::showDeep(int $id)` is changed so the returned `TicketDeepOut` carries:

- `phases: array<PhaseDeepOut>` — full `PhaseDeepOut` instances, **not** `PhaseRef`.
- Each `PhaseDeepOut` already has its `tasks` array filled with full `TaskDeepOut` instances (descriptions, results, outcomes, AI fields, timestamps).

The `tasks` and (now redundant) flat-tasks-array on `TicketDeepOut` is removed; only the nested tree remains. Existing `tm` consumers that previously walked the flat list are migrated to walk the nested tree in the same change.

### Prerequisite ordering

Land in order: A, then B, then C. Each is a separate commit in `ai-toolset/ai-lib` with `composer ci` green. Phase 0 of the dashboard build does not start until all three are merged.

## Phase 0 — Scaffold

Goal: a repository that runs PHPUnit, PHPStan, PHP CS Fixer, Deptrac, and Rector cleanly on an empty codebase, with `composer install` producing a working environment, and an `ai-dashboard` binary that boots an HttpKernel and responds `200 OK` with an empty body to `GET /`.

Tasks:

- `composer.json` with package name `ai-toolset/ai-dashboard`, PHP 8.4+ requirement, autoload PSR-4 mapping `AiToolset\AiDashboard\` → `src/`, and dev dependencies for the five tools (PHPUnit, PHPStan, PHP CS Fixer, Deptrac, Rector). Runtime dependencies: `symfony/http-kernel`, `symfony/routing`, `symfony/http-foundation`, `twig/twig`, and `ai-toolset/ai-lib` via a Composer path repository pointing at `../ai-lib`.
- Directory tree: `src/Kernel/`, `src/Http/`, `src/View/`, `templates/`, `public/static/`, `tests/Http/`, `tests/View/`, `bin/`.
- Console-script entry for `ai-dashboard` in `composer.json`. Stub in `bin/`.
- `phpunit.xml` configured for `tests/` with strict mode.
- `phpstan.neon` at level `max`, no baseline.
- `.php-cs-fixer.dist.php` with PER Coding Style 2.0.
- `deptrac.yaml` encoding the layer rules from architecture §2 (`Kernel → Http → View`, `View → tm DTOs only`).
- `rector.php` targeting PHP 8.4.
- `composer scripts` shortcuts: `composer test`, `composer stan`, `composer fix`, `composer deptrac`, `composer rector`, `composer ci` running everything.
- A minimal `Application` class in `src/Kernel/` that wires HttpKernel, Routing, and Twig, with one route registered (`GET /`) returning an empty `200 OK` for now.

Dogfood: `composer install` clean; `composer ci` green; `bin/ai-dashboard --port 8766` starts; `curl -i http://127.0.0.1:8766/` returns `200 OK` with `Cache-Control: no-store, must-revalidate`.

## Phase 1 — Static asset route and styling skeleton

Goal: the dashboard serves `style.css` from `public/static/`, and the empty `GET /` returns a minimal HTML document that loads it. Visual: dark page, no content yet.

Tasks:

- A static-file route in the routing table for `GET /static/{path}`. Path-traversal guard: reject any resolved path outside `public/static/`.
- A minimal Twig environment registered in the kernel, with `templates/` as the loader root.
- A base layout template `templates/layout.html.twig` with `<head>` linking the stylesheet and an empty `<body>` for now.
- Write the design tokens in `style.css` — colour custom properties, type, layout, header, marker shapes — sections §1 to §4 of `ui/design-system.md`. Skip everything below "Phases & tasks".
- A trivial controller test that `GET /` returns HTML containing the linked stylesheet href.
- A controller test for the static route: returns the CSS, returns 404 for an unknown path, returns 404 for a path-traversal attempt.

Dogfood: open `http://127.0.0.1:8766/` in a browser; page is dark, no content, no errors in the console; `style.css` loads.

## Phase 2 — Project list page

Goal: `GET /` (no query parameters) renders the project list against the user's real `tm` database.

Tasks:

- A `ProjectListController` in `src/Http/`. Calls `tm`'s `ProjectService::list(includeArchived: false)`, hands the result to `ProjectListViewBuilder`, hands the view-model to `templates/project_list.html.twig`.
- A `ProjectListViewModel` and `ProjectListViewBuilder` in `src/View/`. The view-model carries an array of project rows, each with `id` and `name`. The link's href is keyed by `id`; the displayed text is `name`.
- The Twig template renders the breadcrumb (`tm`), the section label `PROJECTS`, the list of project rows with hairlines, and the empty-state text.
- Port the relevant CSS sections from `style.css` (`.projects-list`, header styles).
- View-builder test: seed two projects, archive one, builder returns one row.
- Controller test: response contains `<a href="/?project=<id>">tm</a>` (literal id of the seeded project) for a seeded project named `tm`.

Dogfood: open `http://127.0.0.1:8766/` and confirm the project list shows every project, one row each.

## Phase 3 — Ticket list page

Goal: `GET /?project=<id>` renders the ticket list. The `show_done` form toggle works.

Tasks:

- A `TicketListController` that handles `GET /` when `project` is a positive integer and `ticket` is not present. Reads the `show_done` query parameter (presence-only, any value).
- Resolves the project: calls `ProjectService::show(int $id)`. If `NotFoundException` is thrown, the controller falls through to the project-list state (no error block).
- Calls `tm`'s `TicketService::list(int $projectId, bool $includeArchived = false)`, then filters out tickets whose status is `done` when `show_done` is absent, then sorts by `createdAt` descending. The status filter is done in PHP because `tm`'s `list()` does not accept a status argument.
- A `TicketListViewModel`, `TicketListViewBuilder`, `templates/ticket_list.html.twig`.
- The breadcrumb shows `tm › <project-name>` with `tm` linking to `/`. The project name comes from the `ProjectOut` returned by `show()`.
- Each ticket row: marker shape, monospace ticket id, ISO date, truncated objective (the ticket's `description` truncated to one line), right-aligned status word. The row's link href is `/?project=<project-id>&ticket=<ticket-id>`.
- The `show_done` toggle: a `<form method="get" action="/">` with a hidden `project` field carrying the project id, the checkbox, and the dotted-underline label. The single line of JavaScript in the dashboard, `onchange="this.form.submit()"`, fires the submission.
- Empty-state strings as in `ui/information-architecture.md` §3.
- An unknown project id (`?project=999999`) falls through to the project list (no error).
- A non-integer project value (`?project=foo`) is treated identically to a missing project (project-list state).
- Port the relevant CSS sections (`.ticket-list-head`, `.toggle-form`, `.ticket-list`, `.ticket-row`, status-word colour rules, marker shapes).
- View-builder tests: status filter respects `show_done`; sort order is created-at descending; active tickets carry the `data-status="active"` value.
- Controller tests: known project id renders ticket list; unknown project id renders project list; `?show_done=1` includes done tickets; non-integer project value renders project list.

Dogfood: navigate from project list to a ticket list and toggle `show done`.

## Phase 4 — Ticket deep view: header and phase headings

Goal: `GET /?project=<project-id>&ticket=<ticket-id>` renders the ticket header and phases as collapsed `<details>` blocks. No tasks yet. No notes section (notes are deferred from v1, see `../spec.md` §3.2).

Tasks:

- A `TicketDeepController` that handles `GET /` when both `project` and `ticket` are positive integers.
- Resolves the project via `ProjectService::show(int $id)`; on `NotFoundException`, falls through to the project-list state. Resolves the ticket via `TicketService::showDeep(int $id)`; on `NotFoundException`, falls through to the ticket-list state for the resolved project. If the ticket resolves but its `projectId` does not match the requested `project` id, also falls through to the ticket-list state.
- Catches every other `\AiToolset\AiLib\Domain\Exception\DomainException` thrown by the deep fetch and renders an error-only response: page header (breadcrumb `tm › <project-name> › <ticket-id>`) plus only the red error block in the body, carrying `$exception->getMessage()`. No deep-view layout, no ticket-list fallback.
- A `TicketDeepViewModel` carrying: header (name, id, status, objective, `isLongObjective` boolean, `aiDescription` string) and phases (list of phase view-models with status-derived `isOpen` flag for the first non-done one). No notes field.
- `templates/ticket_deep.html.twig` rendering the two sections.
- The `more ▾` toggle for long objectives: hidden checkbox + label, CSS clamps the objective to 6 lines closed, full when checked.
- The `details ▾` toggle for the ticket header's `aiDescription`: hidden checkbox + label, hidden by default, revealed when checked. Independent of the `more ▾` toggle.
- Phase-prefix-stripping Twig filter: removes the leading `Phase N:` from phase names.
- Port relevant CSS (`.ticket-head`, `.objective`, `.objective-toggle`, the new `aiDescription` toggle styling, `.phase` summary).
- View-builder tests: long-objective threshold (more than 4 newlines OR more than 320 chars); auto-open-first-non-done logic with various phase status combinations; `aiDescription` rendered when present and absent when empty.
- Controller tests: known ticket renders deep view; unknown ticket falls through to ticket list; mismatched project + ticket falls through to ticket list; a `DomainException` from `showDeep` renders the error-only page.

Dogfood: navigate to a ticket deep view, expand the long objective if any, expand `details ▾` to view the AI description, and confirm the phase auto-opening.

## Phase 5 — Ticket deep view: tasks

Goal: phases reveal their tasks; tasks reveal description, result block, and `ai_result` extras. The nested deep DTO from `tm` (Prerequisite C) is the data source — the dashboard does not make per-task fetches.

Tasks:

- Extend `TicketDeepViewModel` and the phase view-model to carry tasks. The phase view-model's tasks come from `PhaseDeepOut::$tasks` already nested by `tm`.
- Each task view-model carries: id, status, title (the `name` field), description, has-distinct-description boolean, result text, outcome (`ok` / `review` / `failed` / null read directly from `TaskDeepOut::$outcome`), `aiResult` text, and the booleans `_hasSummary` / `_hasExtras` / `_hasDesc`, resolved in PHP, not in Twig.
- The Twig task block renders the structure documented in `ui/information-architecture.md` §1.3.2 / "Task items": result line with glyph, optional description above (behind `details ▾`), optional `ai_result` below (behind the same toggle), actions row.
- The glyph is selected from `outcome` (`ok` → ✓, `review` → ⚠, `failed` → ✗, null → ·). No text-pattern derivation.
- The result line shows `no summary` in italic muted grey when the task has been marked done but `result` is empty.
- Drop the transcript link from the actions row (transcripts are out of scope per `spec.md` §3.2). The actions row stays in the markup as an empty placeholder.
- The `details ▾` toggle uses the same hidden-checkbox + label trick. Port the CSS rules that gate description and `aiResult` visibility on the toggle's checked state.
- Port relevant CSS (`.tasks`, `.task` summary, `.task-body`, `.task-desc`, `.task-result`, outcome glyph colours, `task-extras-toggle`).
- View-builder tests: glyph selection from `outcome`; the four-way combination of has-description / has-result / has-ai-result / is-done produces the right boolean trio; status colour overrides on `data-status="active"`.
- Controller test: the rendered HTML contains the expected task structure for a seeded task.

Dogfood: open a ticket with a mix of done, active, and pending tasks; expand each shape; confirm the result-block colour and the `details ▾` toggle behaviour.

## Phase 6 — Empty-state polish, error block, 404

Goal: every empty and error state defined in `ui/information-architecture.md` §3 renders correctly. Unknown routes produce a minimal `404 Not Found`.

Tasks:

- Audit each state ("no projects", "no tickets" both variants, "no plan yet for this ticket", "no tasks") and verify the template produces the exact text and styling.
- The error block: red top border, error text from the `DomainException` message. Verify it is the only body content of the deep-view URL when the deep fetch fails (no deep-view layout, no ticket-list fallback). The breadcrumb still renders.
- A 404 handler in the kernel for unknown routes. Body: plain text `Not Found`. Status: 404.
- Logging on stderr: timestamp, method, path, status, duration. One line per request. Errors include exception class and message.
- Controller tests for each empty state and the 404.

Dogfood: navigate to `/?project=999999` (project list, no error); `/?project=<valid-id>&ticket=999999` (ticket list, no error); a route that does not exist (`/foo`, plain `Not Found`); a deep URL where `showDeep` throws a non-`NotFoundException` `DomainException` (page header plus error block only); restart the dashboard and confirm stderr logging is one line per request.

## Phase 7 — Visual sweep and final dogfood

Goal: the dashboard matches `ui/design-system.md` on every page.

Tasks:

- Run the dashboard on `127.0.0.1:8766`.
- Walk through every page and every state listed in `ui/information-architecture.md` §3: project list, ticket list (both `show_done` states), ticket deep view (long and short objective, with and without `aiDescription`, mix of phase and task statuses).
- Compare by eye. Note every visible difference.
- Resolve each difference by editing CSS or templates to match the design system — not by redesigning.
- Update `decisions.md` with any small judgment calls made during the sweep.
- Confirm `composer ci` is fully green.

Dogfood: report the sweep outcome: "n pages walked; m differences found and resolved".

## Post-v1 additions

Work done after the v1 build, recorded here so the plan stays the build history of record.

- **Ticket 20 — Log section on the ticket detail page.** A `Log` section was added below `Phases` on the ticket deep view: the ticket's log entries (type, content, timestamp), newest first, with the first three shown and a checkbox + label fold for the rest, and `No log entries yet.` when there are none. `TicketDeepController` fetches the entries via `LogService::listByTicket(ticketId, 'desc')`; `TicketDeepViewBuilder` maps them to `LogEntryViewModel` and splits them into a visible head and an overflow tail. Tests in `tests/Http/TicketDeepTest.php` and `tests/View/TicketDeepViewBuilderTest.php`. See `architecture/architecture.md` §5 and `ui/information-architecture.md` §1.3.3.

## Open items deferred to post-build

Items not in v1 that may surface as v2 work after the dashboard has been used in real workflows:

- A live-refresh mode for the user when he genuinely wants to watch a task (per-task or per-page opt-in, not default-on; see `feedback_dashboard_no_live_refresh.md` for the reasoning).
- Cross-project views: "all my active tasks" or "all my blocked tickets".
- A write surface for low-risk operations (toggle `show_done`, mark a task `done` after a quick visual review).
- Theme switcher, light mode.
- Filter and search on long ticket lists.
- Archive views.

None of these are designed for in v1. Each is a discrete v2 conversation, started by the user when real use suggests it.

## Decisions to revisit if they turn out wrong

- **Symfony HttpKernel + Routing only, no full-stack Symfony.** If the routing or container wiring becomes painful, switch to full-stack Symfony with the framework bundle. Cost: re-do the kernel.
- **Twig.** If templates feel too constraining (the dumb-template rule pushes too much into PHP), evaluate whether a different engine helps. Likely no — the rule is the right rule.
- **No JS framework.** If a future feature genuinely needs richer interaction, add a small handwritten script first; consider a framework only if the script grows past a few hundred lines.
- **In-process consumption of `tm` via path repository.** If `tm`'s public service interface keeps changing in ways that break the dashboard, consider promoting `tm` to a versioned library. Likely unnecessary while both projects are pre-1.0.
