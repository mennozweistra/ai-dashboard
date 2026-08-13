# `ai-dashboard` — Architecture

This document describes `ai-dashboard`'s architecture. For toolset-wide conventions that apply across all four projects, see `../../../ai-toolset-docs/docs/architecture/architecture.md` (sibling repo). For `ai-lib`'s architecture, see `../../../ai-lib/docs/architecture/architecture.md` — `ai-dashboard` is a read adapter on `ai-lib` and follows its naming and field conventions.

## 1. Role in the toolset

`ai-dashboard` is the visual surface of the toolset. It owns no entities and no persistence. It reads `ai-lib` through `ai-lib`'s public service interface and renders the result as a single-page web interface for one human user, on localhost.

```
┌────────────────────┐         ┌──────────────────────┐
│  ai-dashboard      │  reads  │  ai-lib              │
│  (PHP, Twig, HTTP) │ ──────▶ │  (PHP, SQLite)       │
└────────────────────┘         └──────────────────────┘
```

The dashboard reads through `ai-lib` only. It does not depend on `wf` or `gd`. It does not speak MCP and does not open `ai-lib`'s SQLite database directly. As of ticket 269 (requirement 685), `composer.json` also requires `ai-toolset/tm` — not for reading data, but so `Application::defaultTmBinaryPath()` can locate `bin/tm` inside the installed package (`Composer\InstalledVersions::getInstallPath('ai-toolset/tm')`) instead of guessing a sibling-checkout path that only existed in the development layout; see §7 for the config-file side of the same requirement. A narrow exception exists to "does not call CLIs": a `POST` action route reads from `ai-lib` and then shells out to an external CLI through a thin wrapper class in the `Http` layer. Three instances exist so far: the ticket/phase/task edit-save routes added under ticket 153 (shell out to `bin/tm`); the create-ticket-from-project-page route added under ticket 160 (shells out to `bin/tm` to create the ticket); and the open-ide route added under ticket 164 (shells out to the machine-local `ide_command` read from `~/.ai-dashboard/config.toml`) — see §5's practical rules for the exact boundary. A fourth instance, the terminal-jump route added under ticket 137 (shelled out to `ai-tmux`), was removed under ticket 269 along with `ai-dashboard`'s entire dependency on `ai-tmux`.

Other projects do not depend on `ai-dashboard`. There is no library to consume; the dashboard is an end-application.

## 2. Layered structure

`ai-dashboard` is thinner than `ai-lib` because the data plane lives in `ai-lib`. The four-layer toolset model collapses into three layers here, plus templates and static assets.

```
src/
  Kernel/           ← HttpKernel wiring, routing table, container build
  Http/             ← controllers, request handling, response building
  View/             ← view-model classes and the builders that fill them
templates/          ← Twig templates
public/static/      ← style.css and any static assets
bin/ai-dashboard    ← console entry point that starts the HTTP server
tests/
  Http/             ← controller tests through HttpKernel
  View/             ← view-builder tests against ai-lib services
```

Dependencies flow inward only:

- `Kernel` depends on `Http` (registers controllers as services).
- `Http` depends on `View` (calls builders, hands view-models to templates) and on `ai-lib`'s services.
- `View` depends on `ai-lib`'s DTOs only.
- `templates/` are read by `Http` via Twig; they contain no PHP.
- `ai-lib` does not depend on `ai-dashboard` in any direction.

Cross-layer violations are caught at CI time by Deptrac.

## 3. HTTP entry and routing

The binary `ai-dashboard` boots a Symfony HttpKernel with the Routing and HttpFoundation components only. There is no full-stack Symfony, no Doctrine, no Messenger, no Security bundle. Reasons:

- The dashboard is a handful of routes against an in-process service. Most of the framework would be unused weight.
- The trade-off (no `bin/console`, fewer cookbook recipes) is acceptable because no recurring framework operations apply: no migrations of our own, no message bus, no authentication.

The kernel is a single class wired in `src/Kernel/`. It builds a small DI container that injects `ai-lib`'s services, the Twig environment, and the application's own controllers. The routing table is loaded from a PHP file rather than annotations or YAML — it is short enough to read at a glance.

The single command-line entry point is `bin/ai-dashboard`. It accepts `--host` (default `127.0.0.1`) and `--port` (default `8766`). Internally it uses PHP's built-in development server (`php -S` style) for the run loop. There is no separate FPM / nginx setup for v1; the dashboard is a single-user local tool.

## 4. The HTTP surface is the contract

Bookmarkable URLs are the user-visible interface. The dashboard's stability commitment is to its URL shape, query parameters, and the structure of the rendered HTML — not to internal classes.

Implications:

- Routes are stable. Adding a query parameter is allowed; renaming or removing one is a breaking change.
- The dashboard does not expose JSON endpoints, with one deliberate exception: `POST /task/{id}/status` and `POST /phase/{id}/status` (ticket 159) return JSON, because the click-to-cycle status control needs the rollup-changed parent statuses back in one response, in a shape the frontend can apply without re-rendering markup. See `../api/http.md` §1.2 and §2.8/§2.9. Every other route stays HTML or plain text.
- HTML structure (class names used by the stylesheet, semantic element choices) is treated as part of the contract because the stylesheet relies on it.

See `../api/http.md` for the full HTTP surface.

## 4a. Cross-project task list and the pending-tasks header link (removed)

The `GET /?tasks=<ids>` state, `TaskListController`, `PendingTasksSubscriber`, and the `pending-tasks-link` header link described in this section were removed under ticket 157 (requirement 142) — the whole cross-project pending task list, not just the header link. `GET /` no longer resolves a `tasks` state at all. See `../ui/information-architecture.md` and `../api/http.md` for the current page inventory and HTTP surface.

## 5. Service consumption from `ai-lib`

`ai-dashboard` depends on `ai-toolset/ai-lib` via a Composer path repository pointing at the local `../ai-lib` directory. There is no published version, no Packagist entry, no semver number. Both projects always use the current local code.

Practical rules:

- The dashboard uses the same `ai-lib` service classes that any other in-process consumer would use. It does not reach into `ai-lib`'s repositories, models, or PDO connection.
- The dashboard accepts `ai-lib`'s output DTOs (`ProjectOut`, `ProjectRef`, `TicketOut`, `TicketDeepOut`, `PhaseDeepOut`, `TaskDeepOut`, etc.) as input to its own view-builders. It does not expose those DTOs to templates directly.
- The dashboard never calls an `ai-lib` operation that mutates state; `ai-lib` itself must never shell out, and that invariant does not change. Most v1 controllers are `GET` handlers that fetch and render. A narrow exception allows a `POST` action-route controller that reads from `ai-lib` and then shells out to an external CLI through a thin wrapper class kept in `ai-dashboard`'s own `Http` layer. Three instances exist so far: `TmCliRunner`, added under ticket 153, shells out to `bin/tm`'s `*:set` commands for the ticket/phase/task edit-save routes; `TicketCreateController`, added under ticket 160, uses `TmCliRunner` (`bin/tm ticket:add`) for the create-ticket-from-project-page route; and `IdeOpener`, added under ticket 164, shells out to the machine-local `ide_command` read from `~/.ai-dashboard/config.toml` (§7) for the `TicketIdeController` open-ide route (see `../../../ai-toolset-docs/docs/architecture/architecture.md` §3.8 for the toolset-wide data/action split, and `../api/http.md` §1.1 for the route-level rule). A fourth instance, `AiTmuxOpener` (added under ticket 137, shelled out to the external `ai-tmux` CLI for the terminal-jump route), was removed under ticket 269 along with `ai-dashboard`'s entire dependency on `ai-tmux`: that integration was private to the owner's machine and could not be depended on by a public dashboard.
- The dashboard expects `TicketService::showDeep()` to return a `TicketDeepOut` whose `phases` array carries `PhaseDeepOut` instances, each with full `TaskDeepOut` instances nested in its `tasks` array. The dashboard does not assemble the tree from flat refs; assembly is `ai-lib`'s responsibility. See `../plan.md` §"`ai-lib` prerequisites" Prerequisite C.
- The ticket deep view also reads `LogService::listByTicket(ticketId, 'desc')` for the ticket's log entries, newest first. `TicketDeepController` makes the call alongside `TicketService::showDeep()`; `TicketDeepViewBuilder` splits the entries into a visible head (first three) and an overflow tail for the fold described in `../ui/information-architecture.md` §1.3.3.

`ai-lib`'s public service interface is a hard dependency. If `ai-lib` renames a field, the dashboard adapts; if `ai-lib` adds a field, the dashboard chooses whether to render it under the field-rendering rule (§6.2).

## 6. Templates and view-models

### 6.1 Templates are dumb

Twig templates loop, conditionally render, and call a small set of registered filters. Anything that requires more than a single field, that depends on more than one entity at once, or that involves a non-trivial decision lives in PHP, not in Twig.

Computed booleans like `_has_summary` / `_has_extras` / `_has_desc`, note grouping, and `loop.first` / `loop.last` machinery all belong to the view-builders. The template renders a flat structure already prepared for display.

The benefit is testable formatting logic. Service-level tests can assert that a `TaskViewModel` produced from a given `TaskOut` has the expected outcome glyph, the expected description-vs-result split, and the expected extras visibility — without booting a Twig environment.

### 6.2 View-models

Every page hands the template one root view-model object. The view-model is plain PHP — readonly classes with typed public properties, no methods, no logic. Builders in `src/View/` take `ai-lib` DTOs and produce view-models.

Builders apply the field-rendering rule from `spec.md` §8.1:

- Fields without `ai_` prefix (`name`, `description`, `result`) are surfaced on the default view.
- Fields with `ai_` prefix (`ai_description`, `ai_result`) are gated behind the `details` toggle and never appear on list views.

If `ai-lib` introduces a new field following the same naming pattern, the dashboard renders it under the same rule. New fields that violate the pattern require a builder change and a small architectural decision in `decisions.md`.

### 6.3 CSS as the design system

All visual decisions live in `public/static/style.css` as CSS custom properties at the `:root` level. There is no preprocessor, no atomic-CSS framework, no build step. Token names and values are documented in `../ui/design-system.md`.

Templates use plain class names that map one-to-one to selectors in `style.css`. Inline styles and template-level `style="..."` attributes are not allowed.

### 6.4 Phase description hover tooltip (ticket 42)

Phases carry two text fields: `description` (human-written) and `ai_description` (AI detail). The ticket deep view surfaces the human-written `description` as a hover tooltip on the phase `<summary>` element. The `ai_description` field continues to follow the standard `ai_`-prefixed field rule (§6.2): hidden by default, visible only behind the details toggle, never in the tooltip.

**Rendering rule.** The `data-desc` attribute is added to the `<summary>` element only when `phase.description` is non-empty. When the description is empty, the attribute is absent and no tooltip appears. The conditional in `ticket_deep.html.twig` is `{% if phase.description %}`. No toggle block, no checkbox, no label is rendered.

**Pure-CSS tooltip.** The tooltip is implemented entirely in CSS using the `::before` pseudo-element on `summary[data-desc]`. The rule `summary[data-desc]:hover::before` sets `content: attr(data-desc)` and positions the element above the summary. No JavaScript is required and no `localStorage` key is involved. The tooltip appears on pointer hover and disappears when the pointer leaves; there is no persistent open/closed state.

**View-model.** `PhaseRowViewModel` exposes `description` (string) and `aiDescription` (string) properties. `TicketDeepViewBuilder` reads them from `PhaseDeepOut` and passes them through unchanged. The empty-string / non-empty check is performed in the template, not in the builder.

**CSS selectors.**

| Selector | Role |
|---|---|
| `summary[data-desc]` | Targets only summary elements that have a non-empty description |
| `summary[data-desc]:hover::before` | Renders the tooltip text above the summary on hover |

### 6.5 Task detail side panel (ticket 48)

The ticket deep view no longer renders task detail as an inline `<details>` fold under the task row. Instead, the task row is a clickable element and the full task content opens in a sliding side panel.

**Markup pattern.** Each task in the phase list renders two elements next to each other: a clickable task row (with the marker, title, id, and status word), and a sibling `<template id="task-data-<id>">` element that holds the full task content (description, result block, AI-tagged extras, actions row). The template is not rendered by the browser; it is an inert HTML payload attached to the row.

**Panel rendering.** A single shared `<dialog id="task-panel">` element lives in the page layout. On click of a task row, JS in `public/static/app.js` clones the contents of the matching `task-data-<id>` template into that dialog and opens it. Closing the dialog empties it again. There is only ever one panel open at a time.

**Side-sheet styling.** The dialog is styled as a side sheet — pinned to the right edge, full viewport height, fixed width — not a centered modal. This is a CSS choice on `dialog#task-panel`; the markup is the standard `<dialog>` element with the native `show()` / `close()` API.

**URL state.** The currently open task is reflected in the `?task=<id>` query parameter (see §7). Setting the parameter on click and clearing it on close is `app.js`'s responsibility; the server does not read the parameter.

**Why this shape.** The pattern keeps server-side rendering — the templates render every task's full content into the page on the initial response — and avoids introducing a JSON endpoint or a separate task-detail route. The dashboard remains a single-page render with no asynchronous data fetching.

## 7. No own server-side persistence

The dashboard has no database, no local files of state, no session store, no cookies, and writes nothing of its own to disk during a session. Logs go to stderr. Restarting the dashboard loses nothing on the server side.

One write is not the dashboard's own: as of ticket 269 (requirement 685), `public/index.php` migrates the shared `ai-lib` store up to the schema this release ships before it opens it, by calling `ai-lib`'s `Services\SchemaMigrator` — the same call `bin/tm` and `bin/tm-mcp` make, described in the toolset architecture §4.7. The dashboard installs through Composer like the rest of the toolset, and after an update that ships a migration the first thing the user opens may well be this page; refusing to render, or rendering against a stale schema, would both be worse than migrating. The router has no boot phase — `php -S` runs it fresh on every request — so the cost matters: when the schema is current, `SchemaMigrator` opens the store, reads `phinxlog` once, and returns without taking a lock or writing anything. Only the first request after an update pays for a migration, and concurrent starts of the dashboard, the CLI, and the MCP server are serialised by `SchemaMigrator`'s `flock()` on `<store>.migrate.lock`. A store written by a newer release (the user downgraded) is never migrated down: `SchemaAheadException` propagates to the HTTP response exactly as the `DashboardConfig` parse error below does, and every request fails loudly with it until the user resolves the downgrade.

Configuration comes from command-line flags: `bin/ai-dashboard` accepts `--host` and `--port` (see §3). An explicit flag always wins; absent a flag, `bin/ai-dashboard` falls back to the `address`/`port` keys read from `~/.ai-dashboard/config.toml` (ticket 269, requirement 684; see below), and absent those too, to `127.0.0.1:8766`. One deliberate exception outside this fallback chain: `public/index.php` reads the `TM_DB` environment variable to select the SQLite database path, falling back to `~/.ai-tm/store.db` when it is unset or empty — the same selection rule `bin/tm` and a freshly launched `bin/tm-mcp` use (`tm`'s persistent Claude Code session server is the one exception; see `../../../ai-tm/docs/architecture/architecture.md` §9). See `../../../ai-tm/docs/api/cli.md` §1.5 for the full mechanism and the test-database workflow it enables.

As of ticket 164, a second exception exists: the dashboard MAY read a machine-local config file at `~/.ai-dashboard/config.toml`, read-only. This lifts the flat "no config file, no `~/.ai-dashboard/` directory" rule that stood here before ticket 164. That earlier rule was a v1 simplicity choice, not a statement that the dashboard would never read a config file — it is not reversed here, only narrowed: the dashboard still never writes to `~/.ai-dashboard/config.toml` or creates the `~/.ai-dashboard/` directory, so the no-write principle stated at the top of this section still holds without exception. `DashboardConfig` (`src/Kernel/DashboardConfig.php`) parses the file with `PhpCollective\Toml\Toml::decodeFile()`. It started under ticket 164 with one setting, `ide_command` — a single command word, no arguments, for example `code` or `subl`, read by `readIdeCommand()`. Ticket 269 (requirement 684) added two more keys read from the same file, each with its own method: `address` (`readAddress()`) and `port` (`readPort()`), the bind defaults `bin/ai-dashboard` uses (see above). No `tm`-binary key was added here — requirement 685 resolves that path from the `ai-toolset/tm` composer dependency instead (§1), not from this file, so there is nothing for a user to configure or get wrong; a later override key remains possible if ever needed. Every key follows the same permissive-on-absence shape: a missing file, a missing key, a blank value, or a value of the wrong type (a string where `port` expects an integer, for instance) all resolve to `null`, and the caller substitutes its own default — no error, the feature or setting is simply off or defaulted. `ide_command` absent/blank/wrong-type means the ticket page's "open IDE" button (`.ticket-ide-btn`; see `../ui/design-system.md` §9) is not rendered. Malformed TOML is different from a missing or wrong-type key: `Toml::decodeFile()`'s parse exception is not caught by any `DashboardConfig` method, so it propagates through whichever entry point called it (`public/index.php` per request, or `bin/ai-dashboard` once at process start) to the HTTP response or the process's stderr. Every request (or the server start) fails loudly with the parse error until the file is fixed, rather than the setting being silently dropped the way an absent file or a missing key is.

The browser stores a small set of UI-state preferences in `localStorage` via `public/static/app.js`. These are client-side only and affect the display layer: the `show_done` toggle preference, the log section open/closed state, per-log-entry open/closed state, and the per-phase open/closed state. Phase descriptions are surfaced as hover tooltips and have no `localStorage` key. Task detail visibility is also not stored in `localStorage` — it is URL state (see below). No server state is involved; clearing browser storage resets these preferences to their defaults.

`app.js` manages the following `localStorage` keys:

- `tm_show_done` — `"1"` when the ticket list's show-done toggle is on; `"0"` otherwise.
- `tm_logs_open` — `"1"` when the log section is expanded; `"0"` otherwise.
- `tm_log_open_<id>` — `"1"` when a log entry with the given id is open; `"0"` otherwise.
- `tm_phase_open_<id>` — `"1"` when a phase detail block with the given id is open; `"0"` otherwise.

Task detail visibility is URL-state, not localStorage. The `?task=<id>` query parameter is set when a task's detail panel is open and removed when it closes. This means task detail is bookmarkable and linkable, and a refresh keeps the active task panel open.

The phase description no longer has a `localStorage` key. It is displayed as a pure-CSS hover tooltip with no persistent open/closed state (see §6.4).

## 8. Single-process model

The dashboard runs as a single PHP process. SQLite access happens through `ai-lib`'s services, which open the same `~/.ai-tm/store.db` that any other `ai-lib` consumer opens. SQLite's file locking handles the case where the dashboard and another `ai-lib` consumer (CLI invocation, MCP session, `wf`) are active simultaneously.

The dashboard does not assume single-reader. The user may have an MCP session running and may execute CLI commands while the dashboard is open. The dashboard re-reads on every request, so any committed change becomes visible on the next manual refresh.

There is no in-memory cache between requests. The cost of re-reading is acceptable at this scale (one user, a few projects, a few hundred entities at most). If the database grows past that, caching is a v2 concern.

## 9. Testing

Tests that touch `ai-lib` state run against a real SQLite database (in-memory) opened through `\AiToolset\AiLib\Testing\InMemoryDatabase::create()`. That helper opens a fresh `:memory:` SQLite connection and runs every Phinx migration in `ai-lib`'s `migrations/` folder against it, so the schema is the same one `ai-lib` ships in production. The dashboard does not maintain its own copy of the schema. There are no mocked services, no mocked repositories. The same TDD discipline as `ai-lib` applies: red → green → refactor, no exceptions.

Three practical categories:

**View-builder tests** (`tests/View/`): seed `ai-lib`'s in-memory database via `ai-lib`'s services, fetch a DTO, hand it to a view-builder, assert the resulting view-model. These cover the field-rendering rule, the description-vs-result split, the `more ▾` threshold, and similar formatting guarantees.

**Controller tests** (`tests/Http/`): drive the HttpKernel with a `Request`, assert the response status, response headers, and a small set of substring or DOM queries against the response body. These cover the route table, the query-parameter handling, and the error-rendering path. They do not assert on full HTML — that is the design system's territory and changes too often.

**Kernel/utility tests** (`tests/Kernel/`, added under ticket 164): exercise a pure PHP unit that has no `ai-lib` database dependency and no HTTP request to drive. No `ai-lib` seeding applies here because the unit under test never reads from `ai-lib`; what these tests do use is the real filesystem (and, for `ApplicationTest`, the real Composer-installed `ai-toolset/tm` package this test suite itself runs against). `DashboardConfigTest` writes real temporary files and asserts what each `DashboardConfig::read*()` method returns for its key (file absent, key absent, key blank or wrong type, key present, malformed TOML). `ApplicationTest` (ticket 269) asserts `Application::defaultTmBinaryPath()` resolves to a `bin/tm` file that actually exists, catching a resolution that returns a plausible-looking but nonexistent path under either layout.

All three categories share `ai-lib`'s testing principles: no Mockery, no `createMock()`, no coverage padding, only tests that catch a real bug. See your global `CLAUDE.md` ("Useful tests only — no test padding") for the underlying rule.

There are no end-to-end browser tests in v1. The dogfood step at each phase boundary is the visual regression check, performed by eye in the running dashboard.

## 10. Code quality tools

The same five tools as `ai-lib`. All are Composer dev dependencies. `composer ci` runs them all and must pass before any commit.

**PHPUnit** — test runner. All tests live under `tests/`. See §9 for testing conventions.

**PHPStan** — static analysis at level `max`. No baseline. Every violation is fixed before merging.

**PHP CS Fixer** — code style at PER Coding Style 2.0. Fixes applied automatically; CI gate checks no unfixed violations remain.

**Deptrac** — layer boundary enforcement. Configured in `deptrac.yaml` to encode the rules in §2: `Kernel → Http → View`, `View → ai-lib DTOs`, no reverse, no skipping a layer.

**Rector** — automated upgrades and dead-code removal. Targets PHP 8.4. Runs before commits.

## 11. Public surface

See `../api/http.md` for the full HTTP surface — the route table, query parameters, error responses.

See `../ui/information-architecture.md` for the page inventory, navigation paths, and what each page displays.

See `../ui/design-system.md` for the visual language: colour tokens, type scale, marker shapes, status semantics, spacing, layout rules.

The PHP classes inside `src/` are internal. They are not part of any public contract and may change at any time.

## 12. Build approach

The detailed build plan with phases, tests, and dogfood steps lives in `../plan.md`. Each phase is dogfooded before the next is built.

The dogfood mode for the dashboard: after each phase, run the dashboard against the user's real `tm` database, navigate the affected pages in a browser, and confirm the output matches the design intent. Visual regressions are caught by eye, not by automated screenshot diff.
