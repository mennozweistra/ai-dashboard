# `ai-dashboard` — Specification

This document specifies what `ai-dashboard` is, what it isn't, and the principles that govern it.

For the architecture, see `docs/architecture/architecture.md`. For the HTTP surface, see `docs/api/http.md`. For the page inventory and navigation model, see `docs/ui/information-architecture.md`. For the visual language and design tokens, see `docs/ui/design-system.md`. For toolset-wide architecture conventions, see `../ai-toolset-docs/docs/architecture/architecture.md` (sibling repo). For the data model owned by `ai-lib`, see `../ai-lib/docs/data-model.md`.

## 1. Purpose

`ai-dashboard` is the visual surface of the toolset. It renders the entity model owned by `tm` — projects, tickets, phases, tasks, logs, status transitions — as a quiet, scannable web interface. Its single audience is the supervising human: one developer, one machine, the dashboard living on the right half of a large monitor next to a Claude Code terminal.

`ai-dashboard` is a renderer. It owns no entities, no persistence, no workflow rules. It reads `ai-lib` through `ai-lib`'s public service interface and presents the result. Writes go through `ai-lib`'s CLI and MCP adapters or, later, through `wf`. The dashboard never mutates state.

## 2. Framing

### 2.1 A peripheral surface, not a primary one

The dashboard is read by a human who is supervising agents working in a terminal. The terminal has the primary attention; the dashboard is glanced at, scanned, and occasionally studied. This framing rules out everything that competes with the terminal for attention: motion, sounds, badges, notifications, auto-refresh, modal interruptions.

### 2.2 A peaceful study tool, not a live monitor

The user reads the dashboard to understand where the work is and what is going on. He does not want the page to change while he reads it. The dashboard does not poll, does not stream, does not auto-refresh. The browser refresh button is the entire refresh model. Manual reload is intentional, not a deficiency.

### 2.3 Rendering is the contract

The HTTP routes are stable URLs that humans and bookmarks rely on. The rendered page is the contract — its structure, its hierarchy, its information density. The dashboard is not intended for consumption by other tools, and is not a programmatic API in general; anything that needs structured access goes through `tm` directly. One deliberate, narrow exception exists: `POST /task/{id}/status` and `POST /phase/{id}/status` (ticket 159) return JSON, because the click-to-cycle status control on the ticket page needs the rollup-changed parent statuses back in one response. See `docs/api/http.md` §1.2.

### 2.4 Read-only in v1

V1 is read-only. There are no forms, no buttons that change state, no destructive actions. The user changes state through `tm`'s CLI or MCP, then refreshes the dashboard. A future write surface is possible but is explicitly not part of v1 and is not designed for here.

### 2.5 A small v1

V1 stays small on purpose. The §3.2 list below names the cases worth calling out explicitly, because they have come up in conversation or are easy to assume by accident.

Improvements, redesigns, and additional features are considered after v1 ships, not as part of v1.

## 3. Scope

### 3.1 In scope

- A single page at `/` whose content is determined by query parameters: project list, ticket list within a project, or deep view of a single ticket. Project and ticket are identified by integer id (`?project=<id>`, `?ticket=<id>`).
- A `show_done` toggle on the ticket list to include or exclude tickets whose status is `done`. Default: hidden.
- The deep ticket view: ticket header, phases as collapsible sections with the first non-done phase auto-opened, tasks as collapsible items showing description, result, and ai-tagged extras behind a `details` toggle.
- Phase descriptions on the deep ticket view: when a phase's `description` field is non-empty, it is surfaced as a hover tooltip on the phase `<summary>` element via a `data-desc` attribute and a pure-CSS `::before` pseudo-element. The tooltip appears on pointer hover and requires no JavaScript and no `localStorage` key. The `ai_description` field is not included in the tooltip; it follows the standard `ai_`-prefixed field rule (hidden behind the details toggle). See `docs/architecture/architecture.md` §6.4 for the full implementation detail.
- Status markers in a left gutter on every row, where the marker's **shape** is the primary signal and colour is secondary. Active is the only marker that wears the accent colour.
- Hiding `ai_description` and `ai_result` fields from default views. They live behind the `details` toggle, not on the surface.
- A dark-only colour theme tuned for long-duration use next to a terminal.
- Localhost-only HTTP binding. Default `127.0.0.1:8766`. Both `--host` and `--port` can be overridden by command-line argument.
- Server-rendered HTML. No client-side JS framework. Native `<details>` and `<input type=checkbox>` carry all interaction. A small handwritten `app.js` extends these with `localStorage` persistence for fold state (log entries, task open state, task extras checkboxes) and the `show_done` preference, so the UI state survives a browser refresh. Phase descriptions are shown as hover tooltips with no persistent state.
- A single binary named `ai-dashboard` that starts the HTTP server.

### 3.2 Out of scope (deliberate)

The general rule is the §2.5 small-v1 rule. The items below are spelled out only because they are likely to come up by reflex.

- **Live refresh.** No polling, no server-sent events, no websockets, no `<meta http-equiv="refresh">`. The user reloads when ready.
- **Per-task transcripts.** Rendering Claude Code session jsonl files per task was abandoned as fragile, and the entity model carries no transcripts. The dashboard does not display transcripts and contains no transcript route.
- **Notes.** `tm` has no per-ticket notes feature, so the dashboard renders no notes section in v1. If notes are added to `tm` later, the dashboard adds the section as a discrete change.
- **Write operations.** No create / update / archive / delete from the dashboard. State changes go through `tm`'s CLI or MCP.
- **Cross-project ticket list or archive views.** No "all my open tickets across all projects" page. The user navigates project → tickets → ticket. (A cross-project task list keyed on explicit task ids was added under ticket 31; it was removed again under ticket 157, requirement 142.)
- **Archive views.** Archived projects and archived tickets are hidden. No "show archived" toggle in v1.
- **Theme switcher, light mode, mobile / responsive layouts, internationalisation, authentication, multi-user, remote access.** None of these are in v1.

## 4. Architectural principles

`ai-dashboard` follows the toolset-wide principles in `../ai-toolset-docs/docs/architecture/architecture.md`. The principles most load-bearing for the dashboard specifically:

- **Layered: controllers → view-models → templates, with `ai-lib` as the only data dependency.** Controllers receive an HTTP request, call `ai-lib`'s services, build a view-model, and hand it to a Twig template. Controllers do not contain rendering logic; templates do not call services.
- **In-process consumption of `ai-lib`.** The dashboard depends on `ai-toolset/ai-lib` via a Composer path repository pointing at the local `ai-lib` directory. It calls `ai-lib`'s public service interface directly; it never opens `ai-lib`'s SQLite database itself, never invokes `ai-lib`'s CLI, never speaks MCP.
- **DTOs at the boundary.** The dashboard accepts whatever DTOs `ai-lib`'s services return and converts them to a small, presentation-only view-model before passing to a template. Templates do not see `ai-lib` DTOs directly.
- **No own server-side persistence.** The dashboard has no database, no local files of state, no session store. Every page renders from the current state of `tm`. Restarting the dashboard loses nothing. The browser stores a small set of UI-state preferences in `localStorage` (fold state, `show_done` preference, and task extras checkboxes) through `app.js`; these are client-side display preferences only and carry no `tm` entity data. Phase descriptions are shown as hover tooltips and have no `localStorage` key.
- **Templates are dumb.** Twig templates loop, conditionally render, and call a small set of registered filters. Business logic, formatting that involves more than one field, and any decision that touches `tm`'s rules live in PHP, not in Twig.
- **CSS is the design system.** All visual decisions live in `static/style.css` as CSS custom properties at the `:root` level. There is no preprocessor, no build step, no atomic-CSS framework. The token names and values are documented in `docs/ui/design-system.md`.

## 5. Public surface

`ai-dashboard` exposes its functionality through one surface:

- **HTTP** — for the human user via a browser. Documented in `docs/api/http.md`.

There is no CLI surface beyond the single command that starts the server, no MCP surface, and no JSON / programmatic API. The PHP service classes inside the dashboard are internal; they are not part of any public contract and may change at any time.

## 6. Configuration

`ai-dashboard` has no configuration file for its own startup. The single command-line entry point accepts:

- `--host` (default `127.0.0.1`).
- `--port` (default `8766`).

Both can be omitted entirely, in which case the defaults apply. Anything beyond these two is added only when a concrete need is demonstrated.

Beyond its own startup, the dashboard reads what it needs from `tm`'s state at request time — no separate configuration file backs that. As of ticket 164, one feature is the exception: it reads a machine-local, read-only file at `~/.ai-dashboard/config.toml`, on every request rather than once at startup. See §7 for what it configures and how a missing or malformed file behaves.

## 7. Storage and runtime

- Binary name: `ai-dashboard`.
- Data directory: none.
- Database: none.
- Config file: `~/.ai-dashboard/config.toml`, read-only, optional. As of ticket 164 this replaces the earlier "none" — a v1 simplicity choice, now lifted, not a reversal of the write stance in the next paragraph: the dashboard reads this file and never writes to it, and never creates the `~/.ai-dashboard/` directory. The file started under ticket 164 with one setting, `ide_command` — a single command word, no arguments, for example `code` — controlling whether the ticket page's IDE button (`.ticket-ide-btn`, `docs/ui/design-system.md` §9) is rendered. Ticket 269 (requirement 684) added `address` and `port`, the defaults `bin/ai-dashboard` binds the web process to when no `--host`/`--port` flag is given. All three are read by `DashboardConfig` (`src/Kernel/DashboardConfig.php`, one method per key: `readIdeCommand()`, `readAddress()`, `readPort()`). An absent file, or a present file where a key is missing, blank, or the wrong type, all mean the caller falls back to its own default — no error, the setting or feature is simply off or defaulted. Malformed TOML is different: the parser's exception is not caught, so it propagates (to the HTTP response for `ide_command`, read per request; to the process's stderr at startup for `address`/`port`) and every request or server start fails with the parse error until the file is fixed. No `tm`-binary key exists here — ticket 269 (requirement 685) resolves that path from the `ai-toolset/tm` composer dependency instead, computed from the installed vendor layout. See `docs/architecture/architecture.md` §1 and §7 for the parsing mechanism and the tm-binary resolution.

The server-side dashboard process writes nothing to disk during a session, with that one read-only exception. Logs go to stderr. Templates and static assets are read from the package install location. The browser writes UI-state preferences (`show_done`, fold state for log entries and tasks, and task extras checkboxes) to `localStorage` via `public/static/app.js`; this is browser-local and not visible to the server. Phase descriptions are surfaced as hover tooltips and have no `localStorage` key.

The dashboard is named `ai-dashboard`, matching the toolset's other package names.

## 8. Data dependency on `ai-lib`

The dashboard reads, but never writes:

- Projects (only those not archived).
- Tickets within a project (filtered by archived flag and, on the dashboard side, by status when `show_done` is off).
- A single ticket's deep view: ticket fields, phases nested with their full tasks, status transitions.

The dashboard expects `ai-lib`'s `TicketService::showDeep(int $id)` to return a `TicketDeepOut` whose `phases` array contains `PhaseDeepOut` instances, each with a `tasks` array of `TaskDeepOut` instances carrying full task data (`description`, `aiDescription`, `result`, `aiResult`, `outcome`, status, ids, timestamps). This is a single complete tree; the dashboard does not make follow-up per-task fetches. The `ai-lib` change to widen `TicketDeepOut` from flat refs to a nested tree is a prerequisite of the dashboard build (see `docs/plan.md` §"`ai-lib` prerequisites").

The dashboard does not call any `ai-lib` operation that mutates state, even in test or development mode.

The dashboard treats `ai-lib`'s service interface as a local PHP API. If `ai-lib` adds a field, the dashboard may render it; if `ai-lib` removes a field, the dashboard breaks at compile or render time, not at runtime in production. This is the deliberate consequence of the in-process composer path link: tight coupling is the point.

### 8.1 Field rendering rule

Per `ai-lib`'s field naming pattern (`spec.md` §8.1 in `ai-lib`):

- Fields without an `ai_` prefix (`name`, `description`, `result`, `outcome`) are **default-visible** — they appear on the surface of the dashboard.
- Fields with an `ai_` prefix (`ai_description`, `ai_result`) are **hidden by default** — they appear only behind a `details` toggle, and only on detail views, never on list views.

The rule applies uniformly: the ticket header's `aiDescription` appears behind the same `details ▾` toggle as task AI fields, on the deep view only. List views never show any `ai_` field.

If `tm` introduces a new field following the same pattern, the dashboard renders it under the same rule without further design work.

The task `outcome` field is a regular non-`ai_` field used by the dashboard to pick the result-line glyph (`✓` for `ok`, `⚠` for `review`, `✗` for `failed`, `·` when unset). The `outcome` column on `ai-lib`'s `tasks` table is a prerequisite of the dashboard build (see `docs/plan.md` §"`ai-lib` prerequisites").

## 9. Decisions

- **HTTP framework: Symfony HttpKernel + Routing components, not full-stack Symfony.** A single small kernel, a router, and a handful of controllers. We do not need security, doctrine, messenger, or the full framework bundle. The dashboard is one page and a couple of routes; the framework should be that small.
- **Template engine: Twig.** Mature, idiomatic in PHP, and it supports the `<details>`-driven layout the pages need.
- **No CSS preprocessor, no JS bundler, no asset build step.** `static/style.css` and any future template-bound JS are served as-is. Tokens live in `:root` as CSS custom properties.
- **No JS framework.** Native `<details>` for collapsible sections, `<form>` + `<input type=checkbox>` for the `show_done` toggle, `<a>` for navigation. `public/static/app.js` is the small handwritten script that adds `localStorage` persistence for fold state (log entries, task open state, task extras checkboxes) and the `show_done` preference. Phase descriptions are surfaced as hover tooltips via a pure-CSS `::before` pseudo-element and require no JavaScript. No framework is used or planned.
- **One CSS file, hand-organised.** `style.css` is written and maintained by hand, section by section. No preprocessor, no framework, no build step.
- **A flat routing surface.** `GET /` with optional `project`, `ticket`, `show_done` query parameters. No `/projects/:id` REST shape — query strings are bookmarkable and simpler. There is no `/task/:id/transcript` route, because transcripts are out of scope.

## 10. Resolved

- **Composer package name: `ai-toolset/ai-dashboard`.** Namespace `AiToolset\AiDashboard\`. Binary `ai-dashboard`.
- **Default port: `8766`.** Override via `--port` if needed.
- **URLs identify entities by integer id.** `?project=<id>` and `?ticket=<id>`. Reasons: `tm`'s service surface is keyed on integer ids; the breadcrumb still shows the project name; bookmarking by id is stable across renames.
- **Data access: Composer path repository to `../ai-lib`.** Same pattern `wf` will use. No publishing.
- **No write surface in v1.** Re-evaluated when a concrete need from real use emerges, not on speculation.
- **No live refresh.** Manual browser reload is the entire refresh model. The user has explicitly stated this is a peaceful study tool and the page should not change while he is reading.
- **Notes deferred.** `tm` has no tagged-notes feature, so the dashboard renders no notes section in v1. If notes are added to `tm` later, the dashboard adds the section as a discrete change.

## 11. Build approach

The detailed build plan, with phases, tests, and dogfood steps, lives in `docs/plan.md`. Each phase is dogfooded before the next is built.

The dogfood mode: after each phase, run the dashboard against the user's real `tm` database, navigate the affected pages in a browser, and confirm the output matches the design intent. Visual regressions are caught by eye, not by automated screenshot diff.
