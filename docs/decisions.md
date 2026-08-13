# `ai-dashboard` — Decisions Log

Small judgment calls made during implementation that the architecture did not anticipate. Appended to the end as work progresses. See `../AGENTS.md` for the format.

---

## 2026-05-08 — Phase 0 — Application takes no PDO in scaffold phase

**Decision:** `Application` constructor takes no arguments in Phase 0. The PDO and ai-lib services are added in Phase 2 when controllers first need to read from ai-lib.

**Reason:** PHPStan level max flags "property only written, never read" (`property.onlyWritten`). Storing a PDO that is not yet used in any route handler would require a baseline suppression, which the quality rules forbid. Adding the PDO in Phase 2 alongside the first controller that actually uses it keeps the constructor honest and PHPStan clean at every phase boundary.

**Where:** `src/Kernel/Application.php`

## 2026-05-08 — Phase 0 — Symfony adds `private` to Cache-Control

**Decision:** Accepted Symfony's default behaviour of adding `private` to Cache-Control alongside `no-store, must-revalidate`. The test asserts `assertStringContainsString` for the two required directives rather than an exact match.

**Reason:** Symfony's `ResponseHeaderBag` normalises cache directives and adds `private` by default when no explicit cache policy is set via `setCache()`. Overriding this with `setPublic()` or switching to raw header manipulation would suppress the behaviour but add fragile plumbing for no user-visible benefit. The relevant directives (`no-store`, `must-revalidate`) are present and correct; the extra `private` directive is conservative and harmless.

**Where:** `src/Http/HomeController.php`, `tests/Http/HomepageTest.php`

## 2026-05-08 — Phase 3 — Sort tiebreaker by id descending

**Decision:** `TicketListViewBuilder` sorts by `[createdAt DESC, id DESC]` rather than `createdAt DESC` alone.

**Reason:** In fast test runs both tickets are inserted within the same clock second, making `createdAt` equal. PHP's `usort` is not stable, so equal timestamps produce non-deterministic order and a flaky test. Using `id DESC` as a tiebreaker makes the sort deterministic at no cost to production correctness, since a higher id means more recently inserted.

**Where:** `src/View/TicketListViewBuilder.php`

## 2026-05-08 — Phase 3 — TM_DB env var for database path override

**Decision:** `public/index.php` checks `getenv('TM_DB')` first; if set it overrides the default `~/.ai-tm/store.db` path.

**Reason:** The user's real store carried an older, incompatible schema at that time. The `TM_DB` override allows dogfood against a seeded database with the current schema, without touching the user's own store.

As of ticket 161, the same `TM_DB` variable is honored by `bin/tm` and `bin/tm-mcp` too (both fall back to `~/.ai-tm/store.db` when unset), and the dashboard's write actions inherit the process environment when they shell out to `bin/tm` via `TmCliRunner`'s `proc_open()` call. So the dashboard's reads and its `bin/tm` write shell-outs share one database, selected by a single `TM_DB` value.

**Where:** `public/index.php`

## 2026-05-08 — Phase 4 — TicketService needs phaseRepository to load phases in showDeep

**Decision:** Added `phaseRepository: new PhaseRepository($pdo)` and `taskRepository: new TaskRepository($pdo)` to the `TicketService` constructor in `Application::buildKernel()`. Both are optional parameters defaulting to null; without them `showDeep` returns an empty phases array.

**Reason:** `TicketService::showDeep` guards `$this->phaseRepository instanceof PhaseRepository` before loading phases. Since the wiring example omitted these optional args, phases were silently empty. This was discovered by the view-builder tests failing on the phase open/close assertions.

**Where:** `src/Kernel/Application.php`

## 2026-05-08 — Phase 4 — Templates use the stylesheet's class names throughout

**Decision:** The Twig templates use the class names from `style.css` (`.ticket-head-line`, `.objective-toggle`, `.objective-toggle-label`, `.ai-description-toggle`, `.ai-description-toggle-label`, `.error`) rather than the names in the phase spec prompt (`.ticket-head-title`, `.objective-toggle-cb`, `.ai-desc-toggle-cb`, `.ai-desc-toggle`, `.error-block`).

**Reason:** `style.css` already used these names when it was written in Phase 1. Introducing new names would require adding duplicate CSS rules.

**Where:** `templates/ticket_deep.html.twig`, `templates/ticket_deep_error.html.twig`

## 2026-05-08 — Phase 4 — Phase template structure follows the stylesheet

**Decision:** The phase `<summary>` wraps phase name and id in a `<span class="phase-name-wrap">` and uses `class="marker marker-{{ phase.status }}"` for the gutter marker.

**Reason:** `style.css` has `.phase-name-wrap` for flex layout and `.marker-{status}` for status-specific marker shapes.

**Where:** `templates/ticket_deep.html.twig`

## 2026-05-08 — Phase 6 — No PHPUnit test for stderr logging

**Decision:** `RequestLogSubscriber` writes to STDERR but has no PHPUnit test asserting its output.

**Reason:** Capturing STDERR in PHPUnit requires process isolation or output-buffering tricks that are brittle and test the framework rather than behaviour. The subscriber's correctness is verifiable by running the server and observing the terminal. The two event-subscriber classes themselves are covered structurally by the 404 and empty-state controller tests, which exercise the kernel event lifecycle.

**Where:** `src/Http/RequestLogSubscriber.php`

## 2026-05-08 — Phase 7 — STDERR not available in cli-server SAPI

**Decision:** Replaced `fwrite(\STDERR, ...)` with `file_put_contents('php://stderr', ..., FILE_APPEND)` in `RequestLogSubscriber`.

**Reason:** `STDERR` is a constant defined only in the CLI SAPI. PHP's built-in web server uses the `cli-server` SAPI, which does not define `STDERR`, causing a fatal error on every request. `php://stderr` is a portable stream wrapper available in all SAPIs.

**Where:** `src/Http/RequestLogSubscriber.php`

## 2026-05-08 — Phase 7 — project-list CSS class mismatch

**Decision:** Fixed `<ul class="project-list">` to `<ul class="projects-list">` in `templates/project_list.html.twig`.

**Reason:** The ported CSS defines `.projects-list` (with trailing `s`) but the Phase 2 template used `project-list` (without `s`). The mismatch caused bullet points to appear instead of the hairline-separated rows.

**Where:** `templates/project_list.html.twig`

## 2026-05-12 — Ticket 20 — Log section CSS layout and fold label text

**Decision:** Rendered `.log-entry` as a 3-column grid (`auto minmax(0,1fr) auto`) with the `.log-type` badge styled like `.ticket-type` (mono, 12px, faint, uppercase, letter-spaced) rather than a colored pill. The fold label says "show all ▾" / "show fewer ▴", mirroring the `.objective-toggle-label` `::before` pattern.

**Reason:** The existing visual language uses small uppercase mono labels for type/status, not filled chips; `.ticket-type` was the closest match for a "small badge". Rows reuse the hairline-separator pattern from `.ticket-row`/`.phase`. The fold mechanism is the same `:checked ~` sibling-combinator used by `.objective-toggle` and `.ai-description-toggle`.

**Where:** `public/static/style.css` (Log section, before "Reduced motion")

## 2026-05-12 — Phase 38 (ticket 24) — Log view-model field names; interim template touch-up

**Decision:** `LogEntryViewModel` now carries `logType`, `title`, `detail` (mapped from `LogEntryOut::$aiContent`), `timestamp`. `TicketDeepViewModel` replaces `logsVisible`/`logsOverflow` with a single `logs` list (newest-first, no slicing). The Twig template was given a minimal interim update: it slices `model.logs` into the first 3 / the rest and renders `entry.title` in the existing markup, so `composer ci` stays green until task 122 rewrites the template. The Http test's `LogEntryIn(..., content: ...)` calls were changed to `title:` to match the renamed ai-lib schema.

**Reason:** Task 121 only reshapes the view layer; the template rewrite and the new fold-behavior Http tests belong to tasks 122/123. The interim template change is the smallest edit that keeps the existing Log markup rendering against the new view model.

**Where:** `src/View/LogEntryViewModel.php`, `src/View/TicketDeepViewModel.php`, `src/View/TicketDeepViewBuilder.php`, `templates/ticket_deep.html.twig`, `tests/Http/TicketDeepTest.php`, `tests/View/TicketDeepViewBuilderTest.php`

## 2026-05-13 — Ticket 46 — Keep `isOpen` as always-false property on `PhaseRowViewModel`

**Decision:** Removed the `$foundOpen` tracking and `$isOpen = !$foundOpen && $phase->status !== 'done'` calculation from `TicketDeepViewBuilder::buildPhases()`. The builder now passes `isOpen: false` unconditionally to every `PhaseRowViewModel`. The `isOpen` property on `PhaseRowViewModel` and the `{% if phase.isOpen %} open{% endif %}` branch in the template are left in place.

**Reason:** Task 250's scope is the builder method only. Removing the `isOpen` property entirely would ripple to `PhaseRowViewModel`, the Twig template, and the existing view-builder tests that assert on it — broader than this task. Passing `false` unconditionally is the smaller change that produces the same end state (all phases closed on render); the client-side localStorage logic added by sibling tasks in this phase will drive the open/closed state from the browser.

**Where:** `src/View/TicketDeepViewBuilder.php` (`buildPhases()`)

## 2026-05-13 — Phase 103 (ticket 48) — TaskViewModel uses dedicated mini view-models for transitions; reuses LogEntryViewModel for logs

**Decision:** Added five new properties to `TaskViewModel`: `aiDescription` (string), `createdAt` (string, `Y-m-d H:i`), `updatedAt` (string, `Y-m-d H:i`), `transitions` (`list<TaskTransitionViewModel>`), `logs` (`list<LogEntryViewModel>`). Created a new dedicated mini view-model `TaskTransitionViewModel` carrying `fromStatus` (nullable string), `toStatus` (string), `timestamp` (string). Reused the existing `LogEntryViewModel` for the per-task logs list rather than introducing a new `TaskLogViewModel`.

**Reason:** Dedicated view-models give typed access in templates and keep PHPStan max happy without `array<string, string>` PHPDoc gymnastics. `LogEntryViewModel` already exposes exactly the fields the spec asks for (`logType`, `title`, `detail` mapped from `aiContent`, `timestamp`) and is already used at the ticket level by `TicketDeepViewBuilder::buildLogEntry()`; introducing a parallel `TaskLogViewModel` with identical shape would be duplication for no gain. `TaskTransitionViewModel` is task-specific because no equivalent existed; `fromStatus` is nullable to match `StatusTransitionOut::$fromStatus` in `ai-lib`. The builder in `TicketDeepViewBuilder::buildTask()` is intentionally left unchanged in this task; task 264 wires the new fields up and that is when `composer ci` will go green again.

**Where:** `src/View/TaskViewModel.php`, `src/View/TaskTransitionViewModel.php` (new)

## 2026-07-02 — Task 1078 (ticket 137) — `ai-tmux` binary path resolved as a sibling checkout, injectable for tests

**Decision:** `Application::__construct(PDO $pdo, ?string $aiTmuxBinaryPath = null)` gained an optional second parameter. When omitted, it resolves to `dirname(__DIR__, 3) . '/ai-tmux/bin/ai-tmux'` — the sibling `ai-tmux` checkout relative to `ai-dashboard`'s own root. Tests pass an explicit path pointing at `tests/Http/fixtures/fake-ai-tmux` instead.

**Reason:** The ai_description offered two options: a new `bin/ai-dashboard` CLI flag, or resolving the path once at boot. A CLI flag would need env-var plumbing through `bin/ai-dashboard`'s `passthru()` call into `public/index.php`, since `Application` is only ever constructed with a `PDO` today — no existing mechanism carries flags that far. Resolving the sibling path directly in `Application` mirrors the pattern `composer.json` already uses for `ai-toolset/ai-lib` (a local path repository one level up from `ai-dashboard`'s own root) and needs no new plumbing. The constructor parameter exists solely so tests can override it with a fake stub script, per AGENTS.md's no-mocks rule — the real ai-tmux binary is never invoked in `composer test`.

**Where:** `src/Kernel/Application.php`, `tests/Http/fixtures/fake-ai-tmux` (new)

## 2026-07-02 — Task 1078 (ticket 137) — `NotFoundSubscriber` also handles `MethodNotAllowedHttpException`

**Decision:** Extended `NotFoundSubscriber::onException()` to catch `Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException` (in addition to the existing `ResourceNotFoundException` handling) and respond `405 Method Not Allowed` with the exception's `Allow` header preserved, instead of letting it propagate as an uncaught exception.

**Reason:** `POST /ticket/{id}/open-terminal` is the first route in `ai-dashboard` restricted to one HTTP method. A `GET` against it (a plausible mistake — a bookmark, a stray link, a JS bug) previously produced an uncaught `MethodNotAllowedHttpException` with a raw stack trace, which is a worse experience than the existing 404 handling gives for unknown paths. A wrong method on a known route is a caller error in the same sense §1.4 of `docs/api/http.md` already describes for unknown routes and malformed query parameters, so it gets the same treatment: a clean status code, not an uncaught-exception 500.

**Where:** `src/Http/NotFoundSubscriber.php`

## 2026-07-02 — Task 1078 (ticket 137) — Route parameter read as `string`, cast to `int` in the controller

**Decision:** `TicketTerminalController::__invoke(string $id)` takes the route parameter as a string and casts it with `(int) $id` internally, rather than declaring `int $id` and relying on the argument resolver to coerce it.

**Reason:** No existing route in this codebase binds a typed route parameter directly (the `static` route's `{path}` is already a string; `TicketDeepController::__invoke(int $projectId, int $ticketId)` is called manually from `HomeController` with explicit `(int)` casts, never through Symfony's argument resolver). Matching that established pattern avoids relying on unverified implicit-coercion behaviour in vendor code for a value that is already guaranteed numeric by the route's `'id' => '\d+'` requirement.

**Where:** `src/Http/TicketTerminalController.php`

## 2026-07-02 — Task 1080 (ticket 137) — `#terminal-error` dialog uses static markup, not the `<template>`-clone pattern

**Decision:** `dialog#terminal-error` is rendered with its final markup already in `ticket_deep.html.twig` (a close button and an empty `<p class="terminal-error-message">`), and `app.js` sets the error text via `.textContent` on open and clears it again on the dialog's native `close` event. This differs from `dialog#task-panel`, which stays empty in the template and has its full content cloned in from a per-task `<template>` on each open.

**Reason:** `task-panel` clones a `<template>` because the content is per-task and generated server-side (title, status history, logs, etc.) — a different `<template>` exists for every task row on the page. The terminal-error dialog has exactly one piece of dynamic content (the plain-text error body) and no server-rendered variants to choose between, so a fixed structure with one field set via `.textContent` is simpler and needs no template element. The close-on-`close`-event text reset mirrors task-panel's pattern of using the dialog's native `close` event to clear state.

**Where:** `templates/ticket_deep.html.twig`, `public/static/app.js`

## 2026-07-03 — Task 1266 (ticket 153) — `TmCliRunner` is wired into `Application::buildKernel()` before any controller consumes it

**Decision:** `Application::buildKernel()` already constructs `new TmCliRunner($tmBinaryPath)` and assigns it to a local variable, even though no controller in this task reads it — the ticket/phase/task edit save routes that will use it are separate tasks (1268–1270) in the same phase.

**Reason:** Task 1266's scope is the wrapper class, its result class, and the `Application` constructor's binary-path default/override, mirroring `AiTmuxOpener` exactly (ticket 137, task 1078). Constructing the runner now, in the same place `AiTmuxOpener` is constructed, keeps `buildKernel()`'s wiring pattern consistent and gives the later save-route tasks one line to change (pass `$tmCliRunner` into a controller) instead of also having to add the construction call. This differs from Phase 0's `Application`-takes-no-PDO decision above: that case was a *property* PHPStan flags as `property.onlyWritten`; a local variable inside a method is not flagged the same way, confirmed by a clean `composer stan` run.

**Where:** `src/Kernel/Application.php`

## 2026-07-03 — Task 1266 (ticket 153) — `run()`'s option keys are escaped together with `--`, not separately

**Decision:** `TmCliRunner::run()` builds each option as `' --' . escapeshellarg($key) . ' ' . escapeshellarg($value)` — the literal `--` prefix stays outside the `escapeshellarg()` call, only the bare key name (e.g. `name`, `ai-description`) is escaped.

**Reason:** The task's ai_description says command lines are "`--key value` pairs ... with both key and value passed through `escapeshellarg()`". Escaping `--key` as a whole (e.g. `escapeshellarg('--name')`) would still be shell-safe (an unquoted `--` adjacent to a quoted token concatenates into one argument either way), but escaping only the bare key keeps the visible `--` prefix identical across every option in the built command string, which is easier to read in test assertions and in the fake stub's argument parsing.

**Where:** `src/Http/TmCliRunner.php`

## 2026-07-03 — Task 1267 (ticket 153) — Padlock glyphs are the literal emoji characters, and `.edit-lock` is a `<button>`, not an `<a>`

**Decision:** `.edit-lock::before` uses the emoji glyphs 🔒 (closed, `data-mode="view"`) and 🔓 (open, `data-mode="edit"`) rather than a text symbol. `.row-view-link` is rendered as `<button type="button">`, not an `<a>`, even though requirement 126 calls it a "link".

**Reason:** The task's ai_description asks for a "closed padlock glyph" / "open padlock glyph", which the Unicode padlock emoji represent unambiguously; no plain-text symbol reads as a padlock the way `✓`/`✗`/`○` read as check/cross/circle in the existing marker sets. For `.row-view-link`, the element performs a client-side JS action (open a panel) rather than navigating, matching the existing `.ticket-terminal-btn` pattern (a button styled to look like inline text); using `<a href="#">` would need a dummy href and a `preventDefault()` on every click for no benefit.

**Where:** `public/static/style.css`, `templates/_task.html.twig`, `templates/ticket_deep.html.twig`

## 2026-07-03 — Task 1267 (ticket 153) — `.panel-view`/`.panel-edit` toggle with `display: contents`, and `.edit-lock` is wired via one document-level delegated listener

**Decision:** `[data-mode="view"] .panel-view` / `[data-mode="edit"] .panel-edit` are shown with `display: contents` rather than `display: block` or `flex`. The `.edit-lock` click handler is a single `document.addEventListener('click', ...)` in `app.js`, not a per-element listener attached at `DOMContentLoaded`.

**Reason:** `.task-panel-body` (and any future panel body) lays out its direct children with `display: flex; flex-direction: column`; wrapping the existing sections in `.panel-view` would otherwise turn that wrapper into a single flex item and break the per-section spacing rules already in `style.css`. `display: contents` makes the wrapper disappear from the box tree while keeping it in the DOM for the `data-mode` selector to key off, so the children lay out exactly as before. The task panel's content is cloned from a `<template>` into the dialog on every open (§6.5 of architecture.md), so any `.edit-lock` button inside it does not exist in the DOM at `DOMContentLoaded` time; a delegated listener on `document` handles that case, and also means later tasks adding `.edit-lock` to the ticket-head or phase panels need no JS wiring of their own.

**Where:** `public/static/style.css`, `public/static/app.js`

## 2026-07-03 — Task 1268 (ticket 153) — `TicketHeaderViewModel` keeps `objective` instead of adding a duplicate `description` property

**Decision:** The new read-only columns added to `TicketHeaderViewModel` are `projectId`, `createdAt`, `updatedAt`, `archivedAt`, `sessionId`, `priority`. No separate `description` property was added; the ticket panel's "Description" field and the edit form's `description` textarea both read the existing `objective` property, which already carries the `tickets.description` column's value.

**Reason:** `objective` and the `description` column have always been the same value — `TicketDeepViewBuilder` has set `objective: $deep->description` since Phase 4. Adding a second property with an identical value under a different name would be pure duplication for no behavioural gain; the panel just labels the existing field "Description" instead of "Objective".

**Where:** `src/View/TicketHeaderViewModel.php`, `src/View/TicketDeepViewBuilder.php`, `templates/ticket_deep.html.twig`

## 2026-07-03 — Task 1268 (ticket 153) — Read-only ticket-panel columns render outside `.panel-view`/`.panel-edit`, not duplicated inside both

**Decision:** The `.task-panel-meta` section listing `id`/`project`/`status`/`type`/`created`/`updated`/`archived`/`session`/`priority` is a direct child of `.task-panel-body` in `#ticket-panel`, a sibling of `.panel-view` and `.panel-edit`, not duplicated inside each. Only `name`/`description`/`ai_description` — the three editable fields — are rendered twice (once read-only in `.panel-view`, once as form controls in `.panel-edit`).

**Reason:** Requirement 137 asks that the panel show every column, and the task's ai_description says the non-editable columns "render read-only in both modes". Placing them outside the `data-mode`-gated wrapper satisfies "both modes" for free — they are simply always in the DOM and always visible, since nothing about them changes between view and edit. Duplicating nine unchanging fields into both `.panel-view` and `.panel-edit` would be pure markup duplication with no behavioural difference.

**Where:** `templates/ticket_deep.html.twig`

## 2026-07-03 — Task 1268 (ticket 153) — `#ticket-panel` reuses the `.task-panel` CSS class instead of new dialog-level rules

**Decision:** `<dialog id="ticket-panel">` carries `class="task-panel"`, and its header/body/section markup reuses `.task-panel-header`, `.task-panel-body`, `.task-panel-section`, `.task-panel-meta` verbatim. No new dialog-sizing, backdrop, or section CSS was written for the ticket panel.

**Reason:** Every one of `dialog.task-panel`'s existing selectors in `style.css` is class-based, not id-based (`dialog.task-panel`, `.task-panel-header`, `.task-panel-body`, etc.), so a second dialog carrying the same class gets identical sizing, backdrop, and section styling automatically. This is the literal reading of the task's "styled identically to `#task-panel` per design-system.md" instruction, and avoids maintaining two copies of the same rules.

**Where:** `templates/ticket_deep.html.twig`, `public/static/style.css`

## 2026-07-03 — Task 1268 (ticket 153) — `TicketDeepController::render()` extracted so the edit-failure re-render reuses the same ai-lib reads

**Decision:** `TicketDeepController::__invoke()` is now a one-line delegate to a new public `render(int $projectId, int $ticketId, bool $editOpen = false, ?string $editError = null, ?string $editName = null, ?string $editDescription = null, ?string $editAiDescription = null): Response` method, which does the project/deep-fetch/log-fetch/build/render steps that used to live directly in `__invoke()`. `TicketEditController` calls `render()` on a failed save instead of duplicating the `ProjectService`/`TicketService`/`LogService` calls.

**Reason:** The task's ai_description explicitly asks to "delegate to the same rendering path `TicketDeepController` uses — extract or reuse its logic rather than duplicating the ai-lib calls". Adding the edit-context parameters to the existing method (rather than a parallel method) keeps one single code path for "read a ticket and render its deep view", so future changes to that path (new DTOs, new error handling) do not need to be kept in sync across two implementations.

**Where:** `src/Http/TicketDeepController.php`, `src/Http/TicketEditController.php`

## 2026-07-03 — Task 1268 (ticket 153) — `RedirectResponse` for the success path

**Decision:** `TicketEditController` returns `new RedirectResponse("/?project={$ticket->projectId}&ticket={$ticketId}")` on a successful save, rather than a plain `Response` with a manually-set `Location` header.

**Reason:** `Symfony\Component\HttpFoundation\RedirectResponse` already defaults to status 302 and sets the `Location` header from its constructor argument; it is the idiomatic Symfony class for exactly this case and was already a direct dependency of the project (`symfony/http-foundation`). No behavioural difference from a hand-built `Response`, just less code.

**Where:** `src/Http/TicketEditController.php`

## 2026-07-03 — Task 1270 (ticket 153) — `#task-panel`'s meta section moves outside `.panel-view`/`.panel-edit`, matching the ticket/phase panels

**Decision:** In the per-task `<template id="task-data-{id}">`, `.task-panel-meta` (id, phase, status, actor, order, created, updated, archived, attempts) is now a direct child of `.task-panel-body`, a sibling of `.panel-view`/`.panel-edit`, instead of living inside `.panel-view` as it did before this task.

**Reason:** Requirement 137 asks the panel to show every column in both modes, and status-transition history and log entries must stay view-only. Before this task, the task panel had no edit mode at all, so its whole content sat inside `.panel-view` as a placeholder. Moving the meta section outside both wrappers reuses the exact pattern task 1268 already established for `#ticket-panel` and task 1269 for `#phase-panel` — the "always visible, both modes" columns render once, outside the `data-mode`-gated wrappers, while transitions/logs stay inside `.panel-view` only.

**Where:** `templates/ticket_deep.html.twig`

## 2026-07-03 — Task 1270 (ticket 153) — Task-edit form field ids are static, not suffixed by task id

**Decision:** The `<form class="task-edit-form">` inputs use fixed ids (`task-edit-name`, `task-edit-description`, `task-edit-ai-description`, `task-edit-actor`, `task-edit-max-attempts`), identical across every `<template id="task-data-{id}">` on the page, rather than an id suffixed with the task's own id.

**Reason:** Matches the existing `phase-edit-*` convention from task 1269 exactly (`id="phase-edit-name"`, not `phase-edit-name-{id}`). The ids only ever exist inside inert `<template>` elements; only one task's content is ever cloned into the single live `#task-panel` dialog at a time, so there is no runtime DOM id collision to guard against.

**Where:** `templates/ticket_deep.html.twig`

## 2026-07-03 — Task 1270 (ticket 153) — Actor `<select>` only preserves `agent`/`human`, not an arbitrary invalid submitted value

**Decision:** The edit form's actor field is a two-option `<select>` (`agent`/`human`) with `selected` set by comparing `task.editActor` against each literal option value. If a failed save's submitted `actor` value is neither (only reachable by a raw HTTP request bypassing the UI, since the UI's own `<select>` cannot produce a third value), no option is marked `selected` and the browser defaults to showing the first one.

**Reason:** `TaskService::validateActor()` only accepts `agent`/`human`, so the UI's own `<select>` cannot submit an invalid value in the first place — the only way a failed save carries an invalid `actor` is a request built by hand outside the browser form. Building an `<option>` dynamically to preserve an arbitrary invalid string in a two-choice `<select>` would add real complexity for a case the UI itself cannot trigger.

**Where:** `templates/ticket_deep.html.twig`, `src/View/TaskViewModel.php`

## 2026-07-03 — Task 1270 (ticket 153) — `#task-panel`'s edit-mode auto-open skips the `?task=<id>` URL bookkeeping the read-only open path uses

**Decision:** `app.js`'s `openTask(id, mode)` only calls `history.replaceState()` to set `?task=<id>` in the URL when opening read-only (the default). When `data-open-on-load`/`data-open-task-id` drive an edit-mode reopen after a failed `POST /task/{id}/edit`, the URL is left untouched.

**Reason:** `#phase-panel`'s equivalent `data-open-on-load` handling (task 1269) never touches the URL either — a plain full-page reload with an unchanged URL is required on save per requirement 132, and the page the browser is showing after a failed POST is the `/task/{id}/edit` target, not the ticket page's own URL. Writing `?task=<id>` onto that POST-target URL would produce an odd combined URL (`/task/{id}/edit?task={id}`) for no benefit, since the mechanism that reopens the panel on this render is the server-rendered `data-open-on-load` flag, not the query parameter.

**Where:** `public/static/app.js`

## 2026-07-03 — Task 1270 (ticket 153) — `TaskViewModel`'s new required fields also needed updating `TaskListController`

**Decision:** Adding the required `phaseId`, `order`, `archivedAt` constructor parameters to `TaskViewModel` (needed for requirement 137 on the task panel) also required updating `TaskListController::buildFound()`, the only other place in the codebase that constructs a `TaskViewModel` directly (for the cross-project `?tasks=` list, unrelated to this ticket's edit-panel work). It now passes those three fields from the same `TaskOut` it already has in scope; `maxAttempts`/`attempts` are left at their existing defaults there, unchanged from before this task.

**Reason:** `TaskViewModel` is a single shared view-model used by both the ticket-deep task panel and the cross-project task list; making its new columns required (matching how `PhaseRowViewModel`'s task-1269 columns were added) surfaces every construction site at the type level via `composer stan`/`composer test`, rather than silently defaulting them to zero-value placeholders in a class that is supposed to represent "every column on the row". Extending `maxAttempts`/`attempts` in `TaskListController` as well was out of scope for this task and left untouched.

**Where:** `src/Http/TaskListController.php`, `src/View/TaskViewModel.php`

## 2026-07-03 — Task 1271 (ticket 153) — Single shared 4-line `.text-clamp` class instead of a per-field clamp value

**Decision:** Replaced the ticket objective's and ticket AI description's checkbox-driven expand toggles, and the phase description/AI description's `.phase-ai-toggle` separator fold, with one shared CSS class `.text-clamp` (`-webkit-line-clamp: 4`) applied to all four elements (`.objective`, `.ai-description`, `.phase-desc`, `.phase-ai-desc`). The pre-existing design-system.md §8.2 documented a 6-line clamp for the objective's old closed state, but that markup had already been removed under ticket 51 ("remove objective clamp/toggle, always display full description text") well before ticket 153 started; no line-clamp CSS currently existed anywhere in `style.css` to match against. 4 lines was chosen as a value inside the task's suggested 3–6 line range, applied uniformly rather than per-field, since all four elements now serve the same purpose (a short on-page preview; the full text lives in the ticket/phase panel's `.panel-view`).

**Reason:** A single shared class keeps the CSS and the class-name catalogue simple, and a uniform value avoids an arbitrary-seeming difference between the ticket-level and phase-level excerpts that the task did not require. `.phase-sep` (the hairline between a phase's description and AI description) is kept, rendered only when both are present, since it is still visually useful to separate the two truncated blocks.

**Where:** `templates/ticket_deep.html.twig`, `public/static/style.css`, `docs/ui/design-system.md`

## 2026-07-03 — Task 1353 (ticket 157) — `.ai-description` uses `margin: 0`, relying on `.section-label`'s existing bottom margin for spacing under the header

**Decision:** After dropping `.ai-description`'s boxed look (`background`, `border-radius`, `padding`), the replacement spacing rule is `margin: 0`, not an added top margin. The section header (`h2.section-label`) already carries `margin: 0 0 8px`, matching how `.requirement-desc` and the `<ul class="requirements-list">`/`<p class="empty">` siblings in the Requirements section rely on the header's own bottom margin rather than adding their own top margin.

**Reason:** `.phase-desc` (`margin: 6px 0 0`) sits inside `.phase-body`'s CSS grid, which has its own `row-gap: 4px` and a phase title without a bottom margin above it — a different layout context that needs an explicit top margin to create spacing. `.ai-details` is a plain block section structured like `.requirements`/`.phases`/`.logs` (checkbox, `h2.section-label`, one content node), not a grid, so following the Requirements section's existing pattern (header's bottom margin only, no extra margin on the content node) is the closer match and keeps `.ai-description` visually and structurally consistent with the other three toggle sections.

**Where:** `public/static/style.css`

## 2026-07-03 — Task 1423 (ticket 159) — Status-controller tests seed state directly through ai-lib services instead of the fake `bin/tm` stub

**Decision:** `TaskStatusController`/`PhaseStatusController` always re-read task/phase/ticket statuses from ai-lib after the `bin/tm` CLI call, on both the success and failure path, rather than branching the read logic by outcome. The fake `bin/tm` stub used by the controller tests (`tests/Http/fixtures/fake-tm`) never touches the test database — it is a bash script, and the in-memory SQLite connection lives inside the PHPUnit process, so no subprocess can see it. Extended the stub with a second failure trigger (`--status fail-me`, alongside the existing `--name fail-me`) so status-route tests can select the failure path the same way the edit-route tests do. For the tests that assert a successful status change (including the autoStatus-rollup case), the test itself applies the target status directly through a locally built `TaskService`/`PhaseService`/`TicketService` before issuing the request, standing in for what a real `bin/tm task:set`/`phase:set` invocation would already have persisted in production, where the dashboard and `bin/tm` share the same on-disk database.

**Reason:** The dashboard's own `TaskService`/`PhaseService` instances (built in `Application::buildKernel()`) are never wired with a `RollupService`, unlike the instances `bin/tm`'s own CLI `Application` builds — rollup after a status change is `bin/tm`'s job, not the dashboard's, matching the existing "no controller mutates ai-lib state" rule (the dashboard only ever shells out). So there was no way to exercise the rollup-reflected-in-parent-statuses behaviour (requirement 173) by driving only the fake CLI and the routes; the controller's re-read step needed the underlying data to already look like a completed, rolled-up write. Unifying the re-read to run once, after the CLI call, regardless of outcome, also matches the task instructions' description of the flow as one linear sequence (resolve, shell out, re-read, respond) rather than two diverging branches.

**Where:** `src/Http/TaskStatusController.php`, `src/Http/PhaseStatusController.php`, `tests/Http/fixtures/fake-tm`, `tests/Http/TaskStatusControllerTest.php`, `tests/Http/PhaseStatusControllerTest.php`

## 2026-07-03 — Task 1425 (ticket 159) — Lifted `showTerminalError` out of its original `if` guard; added `data-open-phase-id` tracking to `openPhase()`

**Decision:** Two small refactors to existing `app.js` code, made so the new click-to-cycle status feature could reuse what was already there instead of duplicating it. First, `showTerminalError` and the `terminalErrorDialog`/`terminalErrorMessage` lookups (originally declared only inside `if (terminalErrorDialog) { ... }` under ticket 137) are now declared right after the lookup, so they exist regardless of the guard; the guard still wraps only the terminal-button-specific listener wiring. The `#terminal-error` dialog markup is unconditionally rendered by every page, so this is always defined in practice. Second, `openPhase(id, mode)` now stamps `phaseDialog.dataset.openPhaseId = String(id)` when it populates the dialog, mirroring the existing `.task.is-open` row-class mechanism that already lets the task-status code tell whether `#task-panel` currently holds the clicked task's content. The phase panel had no equivalent marker before this task, since nothing previously needed to know, from outside `openPhase()`, which phase's markup the dialog currently holds.

**Reason:** Requirement 171 says a failed status POST reuses "the existing error dialog that the terminal button uses" — reusing the same function was more direct than writing a second copy of the same modal-plus-message logic. Keeping an open panel in sync (part of "every rendered occurrence" in the task's ai_description) needed a way to check, from the click handler, whether the currently open `#phase-panel` belongs to the phase being cycled; the task-panel side already had this via `.is-open`, so the phase side needed the same capability added.

**Where:** `public/static/app.js`

## 2026-07-04 — Task 1431 (ticket 160) — `BaseHttpTest` now defaults to the fake `bin/tm` stub, not the real sibling binary

**Decision:** `TicketListController` now calls `TmCliRunner::run('template:list', [])` on every render of the project page (requirement 155's dropdown feeds off the result; this task only wires the data, no template change). That route is exercised, directly or via `HomeController`, by most of the existing `tests/Http` suite (`TicketListTest`, `HomepageTest`, `TicketDeepTest`, `TicketDeepTasksTest`, `EmptyStatesTest`, `ProjectListTest`), none of which previously overrode `Application`'s `$tmBinaryPath`. `BaseHttpTest::setUp()` is changed to build `Application` with `tmBinaryPath: self::TM_STUB` (the existing shared `tests/Http/fixtures/fake-tm` stub) instead of leaving it null. Subclasses that need a specific tm behaviour (`TicketEditControllerTest`, `PhaseEditControllerTest`, `TaskEditControllerTest`) already rebuild `$this->app` in their own `setUp()` with an explicit stub, so this default does not change their coverage; `TicketTerminalControllerTest` and `AiTmuxOpenerTest` never route through `TicketListController`, so the change is inert for them too.

**Reason:** Without this change, every one of those test files would silently start shelling out to the real sibling `ai-tm/bin/tm` on every request, which opens the real `~/.ai-tm/store.db` (Application's own default when no PDO-backed test double is supplied, per `AiToolset\Tm\Cli\Application::__construct()`). That is a real-environment dependency the existing test suite has never had, is slow, and is exactly what the fake-stub convention already in this file exists to prevent. `tests/Http/fixtures/fake-tm` was given a `template:list` branch returning a fixed, already-sorted `{"templates":["bugfix","feature"]}` (per the task's own instruction), so defaulting to it costs nothing for tests that do not care about the value.

**Where:** `tests/Http/BaseHttpTest.php`, `tests/Http/fixtures/fake-tm`

## 2026-07-04 — Task 1431 (ticket 160) — `TicketListViewBuilder::build()`'s new `$templates` parameter defaults to `[]`

**Decision:** `TicketListViewBuilder::build()` gained a fourth parameter, `array $templates = []`, rather than a required parameter.

**Reason:** Five pre-existing tests in `TicketListViewBuilderTest` call `build()` without a templates argument; none of them concern the template dropdown. A default keeps that existing coverage untouched rather than padding every unrelated test call with an empty-array argument it does not care about. `TicketListController` (the only real caller) always passes the actual list explicitly.

**Where:** `src/View/TicketListViewBuilder.php`

## 2026-07-04 — Task 1433 (ticket 160) — Create-ticket popup has no `data-mode`/`.panel-view`

**Decision:** `dialog#create-ticket-panel` does not carry a `data-mode` attribute and does not use the `.panel-view`/`.panel-edit` split that `#ticket-panel`/`#phase-panel`/`#task-panel` use. It always renders the create form directly inside `.task-panel-body`.

**Reason:** The view/edit toggle mechanism exists because those three panels show an existing entity's read-only data by default and switch to editing it via `.edit-lock`. The create-ticket popup has no existing entity to show read-only — it only ever presents the create form — so the toggle machinery would be dead weight. It still reuses the shared `.task-panel` class for identical dialog/header/body chrome, per the ticket instructions.

**Where:** `templates/ticket_list.html.twig`, `public/static/app.js`

## 2026-07-04 — Task 1433 (ticket 160) — `.create-ticket-*` classes joined into the existing combined edit-form selectors

**Decision:** Named the popup's form classes `.create-ticket-error`/`.create-ticket-form`/`.create-ticket-field`/`.create-ticket-actions`/`.create-ticket-save`, following the same per-entity naming convention as `.ticket-edit-*`/`.phase-edit-*`/`.task-edit-*`, and added them to the same combined CSS selectors in `style.css` rather than writing a parallel rule block.

**Reason:** Keeps the popup visually identical to the existing edit forms without duplicating declarations, matching the stylesheet's existing comment ("the rules below are declared once with a combined selector so all three stay visually identical").

**Where:** `public/static/style.css`

## 2026-07-04 — Task 1433 (ticket 160) — Submit button ships `disabled` in the server-rendered HTML; JS clears it

**Decision:** `.create-ticket-save` carries a hardcoded `disabled` attribute in the Twig template. `app.js` computes the real state from the title/description field values on `DOMContentLoaded` and on every `input` event, overriding the hardcoded attribute either way.

**Reason:** The dashboard's other dialogs (open/close, edit-lock toggling) already depend entirely on JS with no no-JS fallback, so there is no established no-JS contract to preserve here. Starting from `disabled` is the safer default before JS attaches its listeners, and the state-update function runs once immediately after wiring, so a failed-submit re-render with preserved non-empty values re-enables the button right away.

**Where:** `templates/ticket_list.html.twig`, `public/static/app.js`

## 2026-07-04 — Task 1432 (ticket 160) — `TicketCreateController` reads the project before calling `ticket:add`, not only after success

**Decision:** The controller calls `ProjectService::show($projectId)` as its very first step, 404-ing immediately on an unknown project, rather than deferring that read until after a successful `ticket:add` (which is the point the task instructions' step ordering suggested, mirroring where `TicketTerminalController` reads `$project->path`). The read serves two purposes at once: the 404 guard, and supplying `$project->path` for the later `ai-tmux open` call.

**Reason:** Reading the project upfront avoids a corner case on the failure path: `TicketListController::render()` returns `null` when its own internal `ProjectService::show()` call fails to resolve the project, and `TicketCreateController` cannot return `null` (its own return type is `Response`). Resolving the project upfront means that corner case cannot occur in practice for this route — an unknown project 404s before `ticket:add` is ever attempted — and it removes a second, redundant `ProjectService::show()` call from the success path.

**Where:** `src/Http/TicketCreateController.php`

## 2026-07-04 — Task 1432 (ticket 160) — Missing `title`/`description`/`template` fields are sent to `ticket:add` as empty strings, not omitted

**Decision:** Unlike `TicketEditController`'s "missing field means leave unchanged" rule for `ticket:set` (§2.5 of `docs/api/http.md`), `TicketCreateController` always forwards all three fields to `ticket:add`, substituting an empty string for any field absent from the POST body or not a string.

**Reason:** `ticket:add` has no existing stored value to fall back to — there is no prior ticket state a missing field could mean "leave unchanged" against. An empty `--name`/`--description`/`--template` reaches `bin/tm` as an empty value and is validated (or not) by `ai-lib` the same way a real empty form submission would be, which is the correct behaviour for a create route.

**Where:** `src/Http/TicketCreateController.php`

## 2026-07-04 — Task 1432 (ticket 160) — Failed-create re-render always uses `showDone: false`

**Decision:** When `ticket:add` fails and `TicketCreateController` re-renders the project page via `TicketListController::render()`, it always passes `showDone: false`, rather than trying to recover whatever `show_done` state the project page was in before the popup was submitted.

**Reason:** The create-ticket form posts to `/project/{id}/ticket/create` with no `show_done` context in its body or the route's own path, so there is nothing to recover from this request alone. `false` is the same default `TicketListController::__invoke()` itself uses when a caller does not specify a value, and a freshly created ticket is never "done" so its visibility does not depend on the toggle anyway.

**Where:** `src/Http/TicketCreateController.php`

## 2026-07-04 — Task 1432 (ticket 160) — `AiTmuxOpener::open()` gained an optional third parameter rather than a second method

**Decision:** Added `?string $prompt = null` as a third parameter to the existing `AiTmuxOpener::open()` method instead of introducing a separate `openWithPrompt()` method.

**Reason:** `open <session-id> <working-dir> [prompt]` is one `ai-tmux` verb with an optional trailing operand (per `ai-tmux`'s own `cmd_open.sh`, ticket 160 task 1428), not two different operations — a second PHP method would suggest a distinction that does not exist on the CLI side. `TicketTerminalController`'s existing two-argument calls are unaffected by the added default parameter.

**Where:** `src/Http/AiTmuxOpener.php`

## 2026-07-04 — Task 1434 (ticket 160) — Reused the existing `#terminal-error` dialog element itself, not just its CSS classes

**Decision:** The `terminal_error` GET parameter is rendered straight into the same `<dialog id="terminal-error">` that ticket 137's click-driven `open-terminal` failure path already uses, rather than adding a second, separate error element that copies its class names. `TicketDeepViewBuilder` sets the message text and `TicketDeepViewModel::$terminalError` drives a `data-open-on-load` attribute on that one dialog, following the exact convention `#ticket-panel`/`#phase-panel`/`#task-panel`/`#create-ticket-panel` already use for a failed-submit re-render. `app.js` gained one `if (terminalErrorDialog.hasAttribute('data-open-on-load')) { terminalErrorDialog.showModal(); }` check alongside its existing click-driven `showTerminalError()` path; the two paths never run in the same request so they cannot clash.

**Reason:** The task instructions said to reuse the dialog's styling "if it fits", and it fits exactly: both paths report the identical failure (`ai-tmux open` did not start a terminal session) and the existing dialog already has the close button, backdrop-click dismissal, and the message-clearing `close` listener needed for a "small, dismissible error message". A second element would duplicate all of that markup, JS wiring, and CSS for no behavioural difference.

**Where:** `src/View/TicketDeepViewBuilder.php`, `src/View/TicketDeepViewModel.php`, `src/Http/TicketDeepController.php`, `src/Http/HomeController.php`, `templates/ticket_deep.html.twig`, `public/static/app.js`

## 2026-07-04 — Task 1511 (ticket 160) — Wrapper prompt loosened from a title/description edit to full research access, and `ai_description` added

**Decision:** `TicketCreateController::buildWrapperPrompt()`'s text no longer frames the AI's job as "rewrite and improve this ticket's title and description ... sharpening them while keeping the user's intent unchanged. This is an editing job on an existing ticket, not an instruction to create anything new." It now grants the AI full tool access, including any MCP connectors available in the session, to research and improve the ticket, and explicitly names all three updatable fields — `title`, `description`, `ai_description` — with what each is for, instead of only `title`/`description`. It also states explicitly that this is not full requirements discovery.

**Reason:** Requirements discovery for this ticket found the original wording too narrow: it read as an editing job on the user's own text, which blocked the intended workflow of pointing the AI at external context (for example a Jira ticket number) to research and build the ticket out. The discovery conversation also surfaced that the original prompt never mentioned `ai_description`, the field every ticket in this system carries for AI-facing technical detail alongside the human-facing `description`, so the AI had no way to know that field existed or what belongs in it.

**Where:** `src/Http/TicketCreateController.php`, `docs/api/http.md` §2.10, `tests/Http/TicketCreateControllerTest.php`

## 2026-07-08 — Task 2062 (ticket 174) — Fixed pre-existing test breakage from `ai-lib`'s new required `TaskIn::$model` parameter

**Decision:** `ai-lib` task 2058 (committed earlier in this same ticket) added `?string $model` to `TaskIn`'s constructor as a required, no-default, positional parameter (right after `name`, before `description`). That broke every existing `new TaskIn(...)` call site in `ai-dashboard`'s own test suite with `ArgumentCountError`, in six files unrelated to this task's own scope (`TaskStatusControllerTest.php`, `TaskEditControllerTest.php`, `TicketDeepTasksTest.php`, `TicketDeepTest.php`, `TaskViewModelBuildTest.php`, `TicketDeepViewBuilderTest.php`). `composer test` failed with 20 errors before any of this task's own edits. Added `model: null` as a named argument to all 21 call sites so the suite compiles and passes again; picked `null` everywhere except this task's own new test, since none of those pre-existing tests exercise the model field.

**Reason:** `composer ci` must be green before commit, and it cannot be green with a broken baseline. The break was mechanical (a required constructor parameter with no default was added upstream) and had one correct fix per site (supply the parameter), not a design decision — so it did not warrant a stop-and-ask. Left the parameter ordering exactly as `ai-lib` shipped it; renaming or reordering `TaskIn` is out of this task's scope (`ai-lib` is read-only here).

**Where:** `tests/Http/TaskStatusControllerTest.php`, `tests/Http/TaskEditControllerTest.php`, `tests/Http/TicketDeepTasksTest.php`, `tests/Http/TicketDeepTest.php`, `tests/View/TaskViewModelBuildTest.php`, `tests/View/TicketDeepViewBuilderTest.php`

## 2026-07-08 — Task 2062 (ticket 174) — `task-model` given its own CSS class rather than reusing `.task-attempts`

**Decision:** The compact row's model span gets a new `.task-model` class (monospace, 13px, `--text-faint`, single-line ellipsis truncation) instead of reusing `.task-attempts`'s class as-is.

**Reason:** `.task-attempts` is `flex: 0 0 auto` (never shrinks) because its content (`N/M attempts`) is always short and bounded. A model id is free-form and can be long, so it needs `min-width: 0` plus `overflow: hidden; text-overflow: ellipsis; white-space: nowrap` to actually truncate inside `.task-title-wrap`'s flex layout — the same idiom `.task-title` and `.project-row .project-name` already use, per the task's instructions not to invent a new truncation mechanism. Catalogued in `docs/ui/design-system.md` §9 in the same commit, per that section's own cross-check rule.

**Where:** `public/static/style.css`, `templates/_task.html.twig`, `docs/ui/design-system.md`

## 2026-07-09 — Task 2072 (ticket 179) — Marker word changed to "runs", `<dl>` row labels left as "Attempts"

**Decision:** Both the task marker (`.task-attempts`, `_task.html.twig`) and the new phase marker (`.phase-attempts`, `ticket_deep.html.twig`) now say `"N/M runs"` instead of `"N/M attempts"`. The task panel's existing `<dt>Attempts</dt>` row and the new mirrored phase panel `<dt>Max attempts</dt>`/`<dt>Attempts</dt>` rows keep that wording unchanged.

**Reason:** `attempts` now counts finished runs of a check (not fix-rounds within one run), per this task's instructions, so a check that ran once and passed shows `1/3`, not `0/3` — the compact row marker is the one place this reads as actively misleading ("1/3 attempts" implies 2 failures before success), so its word changed to "runs" for both entities, kept consistent between them as instructed. The `<dl>` rows were left alone: they sit right next to labels that already spell out the underlying `max_attempts`/`attempts` column names elsewhere in this same panel (e.g. the edit form's "Max attempts" input), so renaming just the row label there would create a mismatch with the column name instead of resolving one. Scope was deliberately kept to the two markers per the task's explicit instruction ("review the label word ... on the existing task marker and your new phase marker").

**Where:** `templates/_task.html.twig`, `templates/ticket_deep.html.twig`

## 2026-07-09 — Task 2073 (ticket 179) — `LogEntryViewModel` also gained `taskId`, not just `taskName`; section collapsed by default

**Decision:** `LogEntryViewModel` gained both `?int $taskId = null` and `?string $taskName = null`, not just `taskName` as the task text named. The new "Code Fixes" section's checkbox starts unchecked (collapsed by default), matching `Log`'s behavior rather than `Requirements`'s (checked/open by default).

**Reason:** Requirement 2 asks the row to show "which task the fix is scoped to (name and id)" — rendering the id needs the id on the view model, not just the name, so `taskId` was added alongside `taskName` (both default to `null`, so every other `LogEntryViewModel` call site is unaffected). For the fold state: the task text names both `Requirements` (open) and `Log` (collapsed) as the pattern to follow without picking one; `Code Fixes` is supplementary diagnostic detail like `Log`, not core ticket structure like `Requirements`, so it defaults collapsed.

**Where:** `src/View/LogEntryViewModel.php`, `templates/ticket_deep.html.twig`

## 2026-07-10 — Task 2223 (ticket 192) — Question group glyphs reuse existing pending/review/done tokens; `Application.php` wired with `QuestionRepository`

**Decision:** The three question groups (`open`/`resolved_unprocessed`/`done`, requirement 386) get `.question-group-marker` glyphs and colours borrowed verbatim from existing semantics rather than new ones: hollow circle / `--text-faint` for `open` (same as the pending/unverified marker), warning triangle / `--review` for `resolved_unprocessed` (same as the `review` entity status — it is, in effect, "flagged, needs a look"), checkmark / `--ok` for `done` (same as done/met). `--accent` was deliberately not used for `open`, even though it is the group that most needs the user's attention, because design-system.md §1 reserves it strictly to the `active` entity status and hover. Also wired `QuestionRepository` into `Application::buildKernel()`'s `TicketService` construction (it was previously omitted, unlike `requirementRepository`), since without it `TicketService::showDeep()` always returns an empty `questions` array in the real app — the tests use their own `TicketService` instantiation and would have stayed green while the feature was dead in production.

**Reason:** The design system explicitly favors shape-over-colour and a constrained palette (§1, §3, §6); reusing established glyph/colour pairs for a new three-state marker keeps the page's existing "one earned accent" rule intact and needed no new CSS custom property. The `Application.php` gap was found by reading `TicketService::showDeep()`'s implementation (`$questions = $this->questionRepository instanceof QuestionRepository ? ... : []`) against `buildKernel()`'s actual constructor call — a straightforward miss to complete, not a design choice, so it is fixed in the same commit rather than flagged as a gap.

**Where:** `public/static/style.css`, `src/Kernel/Application.php`

## 2026-07-11 — Task 2364 (ticket 198) — Question/requirement rows collapsed via native `<details>`/`<summary>`; does not reopen requirement 368

**Decision:** Each `.question` and `.requirement` row is now a native `<details>` element (requirement 503): the `<summary>` holds the existing one-line row (marker, name, id badge, kind/verification word) and the existing detail block (`.question-detail` for questions, `.requirement-desc` for requirements) moved inside as the disclosed content, collapsed by default. No row auto-opens — unlike phases, there is no natural "first" question or requirement to default open. The separate `is-expanded` click handler in `app.js` that only un-truncated a long `.question-name`/`.requirement-name` was retired; a single CSS rule keyed on the `<details>` `open` attribute now does both jobs (reveals the detail block and lets a truncated name wrap), one interaction instead of two.

**Reason:** This is explicitly scoped by requirement 503 as "a passive display toggle only — no new controls, no way to edit or answer from the dashboard," and requirement 503 itself calls out that it does not reopen requirement 368 ("Answering happens in conversation; the dashboard stays read-only" — a question is answered in conversation with the agent, which records the answer and marks it resolved; the dashboard gets no answer controls, and an accept-checkbox, answer text field, or question detail screen was explicitly deferred to a follow-up ticket "decided after real question volume is seen"). Collapsing behind `<details>` changes only whether the existing read-only text is visible on load; it adds no accept-checkbox, no answer field, and no detail screen, so requirement 368's decision stands unchanged.

**Where:** `templates/ticket_deep.html.twig`, `public/static/style.css`, `public/static/app.js`, `docs/ui/design-system.md`

## 2026-07-11 — Task 2356 (ticket 198) — Styled `.requirement-id`/`.question-id`, joining the shared `.phase-id`/`.task-id`/`.ticket-id` selector

**Decision:** Added `.requirement-id` and `.question-id` to the existing combined CSS selector in `style.css` that already styles `.phase-id`, `.task-id`, `.ticket-id`, `.ticket-head-id`, `.project-id` (mono, 13px, `--text-faint`, `flex: 0 0 auto`), rather than writing separate rules or leaving them unstyled.

**Reason:** Task 2365's own documentation change (previous entry above it in this log's ticket 198 history) had already flagged, correctly, that these two badges render but carry no CSS rule, tracked as tm question 41. The QA pre-merge check (task 2356) verified the gap in the browser and closed it: both badges use the identical bracketed-id idiom the other three already use, so joining the shared selector is the smallest change that matches the established convention, and resolves question 41 without needing a new requirement or design discussion.

**Where:** `public/static/style.css`, `docs/ui/design-system.md`

## 2026-08-06 — Ticket 203 — `ai-tmux` resolved from `PATH` first, sibling checkout demoted to a fallback

**Decision:** `Application::defaultAiTmuxBinaryPath()` no longer returns the sibling checkout's path directly. It now defers to the new `AiTmuxBinaryResolver` (`src/Kernel/`), which checks three sources in order: the `TM_AI_TMUX_BIN` environment override, then each directory in `PATH` for an executable named `ai-tmux`, then — only if neither yields one — the sibling `ai-tmux/bin/ai-tmux` beside `ai-dashboard`'s own root, which stays as the last-resort fallback. This reverses the 2026-07-02 entry above (task 1078, ticket 137), which made the sibling path the primary route. The injectable constructor parameter is unchanged, so tests still point at `tests/Http/fixtures/fake-ai-tmux`.

**Reason:** The sibling path assumes `ai-lib`, `ai-tm`, `ai-dashboard` and `ai-tmux` all sit side by side, which holds in the main checkout and fails in a per-ticket git worktree: the grind Setup phase checks out only the repositories a ticket touches. Serving the dashboard from ticket 164's worktree, which held `ai-dashboard` and `ai-lib` and no `ai-tmux`, made the terminal button fail with `…/tickets/ticket-164-sticky-header-band/ai-tmux/bin/ai-tmux: not found`. Working from a per-ticket worktree is the normal way work happens here, so that is not an edge case. `PATH` does not have the same weakness: these commands are installed globally as symlinks under `~/bin`, so `ai-tmux` resolves from any working directory. The original 2026-07-02 reason — that a bare command name failed because nothing had put `ai-tmux` on `PATH` — was answered by the `~/bin/ai-tmux` symlink created on 2026-07-11, and the resolver keeps the sibling path as a fallback for a machine where that is still true. `ai-tm`'s grind runner (`AiTmuxRunner::fromEnvironment()`) already used this override-then-`PATH` order, so the two now agree.

**Where:** `src/Kernel/AiTmuxBinaryResolver.php`, `src/Kernel/Application.php`, `tests/Kernel/AiTmuxBinaryResolverTest.php`, `docs/architecture/architecture.md`

## 2026-08-05 — Task 1590 (ticket 164) — Empty `.ticket-head` skipped; `.ticket-list-head` right-aligns its lone child; `applyTicketStatus()` re-anchored; `.row-view-link`/`.ticket-status` gained `flex: 0 0 auto`

**Decision:** Four small follow-on fixes made moving `.ticket-head-line`/`.ticket-list-title`/the "Projects" `<h2>` into the new sticky title band (`.site-header-title`, filled by the `titleline` block) actually work, beyond the template/CSS moves the task instructions spelled out directly:
1. `ticket_deep.html.twig`'s leftover `<section class="ticket-head">` (now holding only the objective paragraph) is wrapped in `{% if model.header.objective %}`, so a ticket with no objective renders no empty section at all, instead of an empty flex container still claiming `main`'s 20px inter-section gap.
2. `.ticket-list-head` (in both `project_list.html.twig` and `ticket_list.html.twig`, now holding only the show-done toggle after its heading moved to the band) changed from `justify-content: space-between` to `flex-end`, so the toggle stays right-aligned with only one child instead of collapsing to the left.
3. `app.js`'s `applyTicketStatus()` read `.ticket-head[data-status]` to refresh the status word after a phase/task status-rollup response (requirement 173); re-pointed to `.ticket-head-line[data-status]`, since `data-status` and `.ticket-status` both moved there with the line.
4. `.row-view-link` and `.ticket-head-line .ticket-status` gained explicit `flex: 0 0 auto`, matching the task's requirement 197 instruction ("the id, type and status spans, the view button ... keep their space") — `.ticket-terminal-btn` and the four `*-id` classes already had it; these two did not.

**Reason:** All four are consequences of the DOM move the task already specifies, not new features: none is visible by reading the moved markup in isolation, only by checking what still points at the old location (a CSS ancestor selector, a `querySelector`) or what renders when the moved content is absent (no objective). Leaving any of the four unfixed would have been a silent behaviour regression (status rollup stops updating the header; an empty section leaves a stray gap; the toggle jumps to the wrong side) introduced by a template move the task explicitly asked for.

**Where:** `templates/ticket_deep.html.twig`, `public/static/style.css`, `public/static/app.js`

## 2026-08-13 — Task 3653 (ticket 269) — `IdeCommandConfig` renamed to `DashboardConfig`; `tm` binary resolved via `Composer\InstalledVersions`

**Decision:** Three related choices made while wiring requirements 684/685:
1. `Application::defaultTmBinaryPath()` no longer guesses `dirname(__DIR__, 3) . '/ai-tm/bin/tm'` (a sibling-checkout path that only exists in the development layout). It now calls `Composer\InstalledVersions::getInstallPath('ai-toolset/tm') . '/bin/tm'`. `ai-toolset/tm` was added to `composer.json` `require` (and a `../ai-tm` path repository added next to the existing `../ai-lib` one) so the package is always present. The method was made `public static` (was `private`) so `tests/Kernel/ApplicationTest.php` can assert the resolved path exists without constructing a full `Application` (which needs a `PDO`).
2. `src/Kernel/IdeCommandConfig.php` (added under ticket 164 for the single `ide_command` key) is renamed to `src/Kernel/DashboardConfig.php`, since requirement 684 adds two more keys — `address` and `port` — to the same `~/.ai-dashboard/config.toml` file. Rather than one method returning a value object with all three fields, each key gets its own static method (`readIdeCommand()`, `readAddress()`, `readPort()`) sharing a private `readKey()` helper — no caller needs more than one or two of the three keys at once (`public/index.php` reads only `ide_command` per request; `bin/ai-dashboard` reads only `address`/`port` once at startup), so a combined value object would be unused surface.
3. `bin/ai-dashboard`'s `--host`/`--port` flags still win when given; absent a flag, the config file's `address`/`port` are the default; absent both, the hardcoded `127.0.0.1:8766` (the pre-existing default) applies. This precedence was not specified by the task text beyond "used as defaults" — flag-overrides-config-overrides-hardcoded is the same shape ticket 164 already established for `ide_command` (config overrides "off"), and matches ordinary CLI-tool convention.

**Reason:** (1) `InstalledVersions::getInstallPath()` reads the value Composer itself recorded at install time, so it resolves correctly whether `ai-toolset/tm` landed as a path-repository symlink (development layout, `vendor/ai-toolset/tm -> ../../../ai-tm/`) or a real mirrored copy (composer install layout) — verified against both in this task (see task result). Guessing a path relative to `ai-dashboard`'s own file location cannot work here the way `SchemaChecker::defaultMigrationsPath()` does for `ai-lib`, because the target binary lives in a *different* package than the code doing the resolving. (2) The rename keeps the class name honest — "IdeCommandConfig" reading a `port` key would mislead a reader — and the per-key static methods avoid a value object with fields most callers do not need. (3) No requirement text pinned the precedence order down; this is the one product-behavior call in this task without an automated test asserting it (recorded to `tm` as a `check` question, per the task's own instructions).

**Where:** `src/Kernel/Application.php`, `src/Kernel/DashboardConfig.php` (renamed from `IdeCommandConfig.php`), `bin/ai-dashboard`, `public/index.php`, `composer.json`, `tests/Kernel/ApplicationTest.php`, `tests/Kernel/DashboardConfigTest.php` (renamed from `IdeCommandConfigTest.php`)
