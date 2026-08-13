# `ai-dashboard` — Information Architecture

This document is the page inventory for `ai-dashboard`. It names every page, what it displays, how the user reaches it, and how it links onward.

For HTTP routes, see `../api/http.md`. For visual language, see `design-system.md`. For scope, see `../../spec.md`.

## 1. Page inventory

There are three logical pages, all served by the single `GET /` route. The state is selected by query parameters; the user navigates between states via in-page links and one form (`show_done`).

```
        /
        │
        ├─ Project list  ← default state
        │     │
        │     └─▶ Ticket list  (?project=X)
        │            │
        │            └─▶ Ticket deep view  (?project=X&ticket=Y)
        │
        └─ Static assets   /static/style.css
```

The cross-project task list (`?tasks=<ids>`) and the "Pending tasks" header link that was the only way to reach it were removed under ticket 157 (requirement 142).

As of ticket 164, every page's header is a sticky two-row band, pinned to the viewport top while the page scrolls: a breadcrumb row on top, and — on the three pages below — a title row under it, filled by the `titleline` Twig block. The two error-page states in §3 (`ai-lib` error during deep-fetch, unknown route) render the breadcrumb row alone; neither defines a `titleline` block. Each page's own heading content (a section label, a project name, or the ticket header) now renders in the title row, not in the page body; each section below names it **Header title row**. See `design-system.md` §7 and §9 (`site-header-title`) for the CSS mechanism and the row's shared height and typography.

### 1.1 Project list

**URL.** `/`

**Header breadcrumb.** `tm`

**Header title row.** Section label `PROJECTS` in tiny tracked uppercase, dim.

**Body.**

- A list of project names, one per row, separated by hairline rules. Each row is a link to the ticket-list state for that project; the link's `href` is `/?project=<id>` while the visible text is the project's `name`. Order: as returned by `ai-lib`'s `ProjectService::list(includeArchived: false)`.
- Empty state: `No projects yet.` in italic muted grey.

**No actions.** No create button, no settings, no toggles.

### 1.2 Ticket list

**URL.** `/?project=<id>` (optionally with `&show_done=1`)

**Header breadcrumb.** `tm › <project-name>` — `tm` links back to the project list. The breadcrumb shows the project's name (read from the `ProjectOut` returned by `ai-lib`); only the URL parameter carries the id.

**Header title row.** Project name as an h2 next to an always-visible round "+" button (`.create-ticket-btn`). The h2 carries a `title` attribute with the full project name and never wraps, truncating with an ellipsis instead on a long name (requirement 197).

**Body.**

- A row holding only the `show done` / `hide done` toggle, right-aligned.
- The toggle is a single checkbox inside a small form that submits `GET` on change. When done is hidden, the label reads `show done`. When done is shown, the label reads `hide done`.
- A list of ticket rows. Each row is a single link comprising:
  - 16px gutter cell with a status marker shape (see `design-system.md` §3 for the shape catalogue).
  - Ticket id in monospace.
  - Created-at date in `YYYY-MM-DD` format, dim.
  - Truncated objective, single line, ellipsis on overflow.
  - Status word right-aligned in tracked uppercase, dim.
- Order: most recently created first.
- Empty state: `No tickets in this project.` if `show_done` is on, or `No tickets (excluding done) in this project.` if it is off. Italic muted grey.

**Create ticket (ticket 160, requirements 154/155/156/157/158/159).** The "+" button next to the project name (`.create-ticket-btn`) is always visible, regardless of whether the project has any tickets. It opens `<dialog id="create-ticket-panel">`, a popup reusing the shared `.task-panel` chrome (see `design-system.md`'s class catalogue), with three fields — title, description, and a template `<select>` populated from `tm template list` — and a Create button that stays disabled until title or description has content. Submitting the popup does a normal (non-AJAX) form `POST` to `/project/{id}/ticket/create` (`../api/http.md` §2.9), which creates the ticket via `bin/tm`. On success the response redirects to the new ticket's deep view. On a `bin/tm` failure (for example an unknown template), the project page re-renders with the popup already open, the parsed error message shown inside it, and the submitted field values preserved. Ticket 269 removed the follow-on `ai-tmux` terminal session this route used to start for the new ticket, along with the `terminal_error`/`terminal_warning` redirect parameters that reported its outcome — see `../api/http.md` §2.9.

### 1.3 Ticket deep view

**URL.** `/?project=<project-id>&ticket=<ticket-id>`

**Header breadcrumb.** `tm › <project-name>` — `<project-name>` links back to the ticket list (using the project id in the href). As of ticket 164, the breadcrumb no longer carries a third `<ticket-id>` segment; the ticket id now renders in the title row instead (`[id]` next to the h1 — see §1.3.1), so showing it a second time in the breadcrumb would be redundant.

**Browser tab title (ticket 195).** `<ticket-id> - <ticket-name>`, e.g. "195 - Show ticket number in browser tab title", instead of the app-wide default "tm". Every other page keeps the default title.

**Body.** Five sections, in this order. As of ticket 164, the title/id/type/status/buttons part of the first section below renders in the sticky title row, not in the page body — see the note at the top of §1; only the objective paragraph still renders in the body.

#### 1.3.1 Ticket header

- Ticket name as h1 (or the ticket id if no name is set), in the title row. The h1 carries a `title` attribute with the full ticket name and never wraps, truncating with an ellipsis instead on a long name (requirement 197).
- Ticket id in brackets next to the name, dim, monospace, in the title row.
- Type and status word, dim, tracked uppercase, in the title row.
- The "view" button (`.row-view-link`; see `design-system.md` §8.1a), in the title row, opening the ticket panel read-only.
- Objective (the ticket's `description` field) as a single paragraph in the page body, below the title row, rendered only when the ticket has one (fixed 4-line truncation via `.text-clamp`, no expand control — `design-system.md` §8.2); nothing renders in the body here when the objective is empty.
- If the ticket has an `aiDescription` (the `ai_description` field from `ai-lib`), it is hidden by default and revealed by a `details ▾` toggle in the Details section further down the page.

**IDE control (ticket 164 requirements 201/202/204).** A round single-character button (`.ticket-head-buttons`) sits at the right end of the title row, styled like the ticket-list page's round "+" button: a circle, 26x26px, filled on hover. `i` (`.ticket-ide-btn`) opens the ticket's workspace directory in a configured IDE; it renders only when a machine-local IDE command is configured (see `../architecture/architecture.md` §7 and `../api/http.md` §2.10) — with none configured, `.ticket-head-buttons` renders empty. Ticket 269 removed the sibling `t` terminal-jump button (`.ticket-terminal-btn`, added under ticket 137) and the dashboard's entire dependency on `ai-tmux`; see `AGENTS.md` for the current guardrails.

On click (requirement 204), the button issues a background `fetch POST` to the open-ide route (`../api/http.md` §2.10) with no page reload. On a successful (2xx) response, nothing visibly changes on the page — the observable feedback is the IDE window coming to the front. On a non-2xx response, or on a network failure, the button shows the returned plain-text message in a centered, dismiss-only error dialog (`#terminal-error` — the id/class name predates the IDE button and is unchanged by ticket 269): a visible close button, a backdrop click, or Escape closes it; the dialog never auto-dismisses and its appearance or dismissal never shifts the surrounding page layout.

The same dialog is also reused by the phase/task click-to-cycle status controls (§1.3.3) to report a failed status update — see below.

#### 1.3.2 Requirements

- Section heading `Requirements` (h2), above phases.
- A read-only list of the ticket's requirements, sourced from `TicketDeepViewBuilder.buildRequirements()`, in the order returned by `ai-lib` (the `order` field).
- Each row is a `<div class="requirement">` carrying a `data-verification` attribute whose value is one of `unverified`, `met`, or `unmet`.
  - A 16px gutter cell with a `.verification-marker` shape (see `design-system.md` §6 for the shape catalogue).
  - The requirement name in `.requirement-name`.
  - The verification word right-aligned in `.verification-word`, tracked uppercase.
- If the requirement has a description, it is rendered below the row as a `<p class="requirement-desc">` in monospace muted text.
- Empty state: if the ticket has no requirements, the body renders `No requirements.` in italic muted grey.

#### 1.3.2a Questions (ticket 192, requirements 367/368/386)

- Section heading `Questions` (h2), adjacent to Requirements — same section-toggle mechanism (`#questions-toggle`, persisted via `tm_questions_open`).
- A read-only list of the ticket's questions, sourced from `TicketDeepViewBuilder.buildQuestions()`, in the order `ai-lib` returns them (creation order). The dashboard never calls `QuestionService` directly and has no answer controls of any kind — requirement 368 defers a question detail screen and answer controls to a follow-up ticket; answering happens in conversation with the agent.
- Each row is a `<div class="question">` carrying a `data-group` attribute whose value is one of `open`, `resolved_unprocessed`, or `done` — the same three-way grouping `QuestionService::isInGroup()` derives from `state` + `processed_at`, so the dashboard reads the same as `bin/tm question:list --group` and the MCP `question:list` tool (requirement 386).
  - A 16px gutter cell with a `.question-group-marker` shape: hollow circle for `open` ("needs you"), warning triangle for `resolved_unprocessed` ("needs agent"), checkmark for `done` — reusing the existing pending/review/done glyphs and colours rather than introducing new ones (`--accent` stays reserved for the `active` entity status per `design-system.md` §1).
  - The question name in `.question-name` (same click-to-expand ellipsis behaviour as `.requirement-name`).
  - The kind (`ask` or `check`) in `.question-kind`.
  - The state (`open`, or the resolution once resolved — `accepted`, `answered`, `withdrawn`) in `.question-state`.
  - The group label (`needs you`, `needs agent`, `done`) right-aligned in `.question-group-label`.
- The question text, background, recommendation, and (once resolved) answer are always rendered below the row in a `<div class="question-detail">`, one labelled paragraph per non-empty field — the same "plain rendering, no toggle" idiom `.requirement-desc` uses, not a new interaction pattern.
- Empty state: if the ticket has no questions, the body renders `No questions.` in italic muted grey.

#### 1.3.3 Phases

- Section heading `Phases` (h2).
- Each phase is a `<details>` block. Phase row contents:
  - 16px gutter cell with a phase-status marker shape.
  - Phase name (with the `Phase N:` prefix stripped by a Twig filter).
  - Phase id in brackets, dim, monospace.
  - Status word, dim, tracked uppercase.
- The first non-`done` phase is auto-opened (`<details open>`); all others are closed by default.
- Phases with no tasks render the body text `no tasks` in muted grey.
- Phases with tasks render an unordered list of task items.

**Status click-to-cycle (ticket 159, requirements 161/162/171/172/173).** The phase's status word is a separate click target from the rest of the row (the `.status-cycle` class on `.phase-status`; see `design-system.md` §9). Clicking it steps the displayed status through pending → active → done → pending locally in the browser, with no request per click; a status outside that cycle steps to pending on the first click. The click stops there — it does not toggle the enclosing `<details>` open or closed. After the display settles, `public/static/app.js` sends the final value once to `POST /phase/{id}/status` (`../api/http.md` §2.8) and applies the returned phase and ticket statuses everywhere they render — this row's status word and marker, the phase panel's header and meta Status line if that phase's panel is open, and the ticket header's status word if the change rolled up. A non-2xx response or network failure reverts the display to the server-confirmed statuses and reuses the shared error dialog (§1.3.1).

##### Task items

Each task is a clickable row, not an inline `<details>` block. Task row contents:

- 16px gutter cell with a task-status marker shape.
- Task title (the `name` field) or, if name is empty, the task `description`.
- Task id in brackets, dim, monospace.
- Status word, dim, tracked uppercase.

**Status click-to-cycle (ticket 159, requirements 161/162/171/172/173).** The task's status word is a separate click target from the rest of the row (the `.status-cycle` class on `.task-status`; see `design-system.md` §9). Clicking it steps the displayed status through pending → active → blocked → done → pending locally in the browser, with no request per click; a status outside that cycle steps to pending on the first click. The click stops there — it does not open the task's detail panel. After the display settles, `public/static/app.js` sends the final value once to `POST /task/{id}/status` (`../api/http.md` §2.7) and applies the returned task, phase, and ticket statuses everywhere they render — this row's status word and marker, the task panel's header and meta Status line if this task's panel is open, the owning phase's status word if that phase's panel is open, and the ticket header's status word — reflecting any rollup. A non-2xx response or network failure reverts the display to the server-confirmed statuses and reuses the shared error dialog (§1.3.1).

Clicking the row opens the task's detail in a sliding side panel anchored to the right edge of the viewport (full viewport height, fixed width). The panel is a single shared `<dialog id="task-panel">` element in the page layout; on click, `public/static/app.js` clones the matching `<template id="task-data-<id>">` payload into the dialog and opens it. The currently open task is reflected in the `?task=<id>` query parameter, so the panel state is bookmarkable, linkable, and survives a refresh. Closing the panel (close button, Escape, or backdrop click) removes the query parameter and empties the dialog. Only one task panel is open at a time. See `architecture/architecture.md` §6.5.

The panel body shows in this vertical order:

1. **Description** (only if the task has a description distinct from the title; behind the `details ▾` toggle if a result is also present).
2. **Result block** — only if the task has a `result` value or has been marked done. The glyph is selected from the task's `outcome` field: ✓ green for `ok`, ⚠ amber for `review`, ✗ red for `failed`, · neutral when `outcome` is null/unset. The glyph is followed by the `result` text. If `result` is empty but the task has been marked done, render `no summary` in italic muted grey with the neutral glyph.
3. **AI-tagged extras** (only if `ai_result` is set; behind the `details ▾` toggle).
4. **Actions row** — for v1, this row is empty, because transcripts are out of scope per `spec.md` §3.2. The row stays in the markup as a placeholder for future actions.

The `details ▾` toggle inside the panel uses the checkbox + label trick. When closed, only the result block is visible. When open, description appears above the result and `ai_result` appears below it.

If neither `result` nor `ai_result` is present (a pending or active task), the description is shown directly without the toggle, and the result block is omitted.

**No actions.** No status-change buttons, no add-task button, no edit, no archive.

#### 1.3.4 Log

- Section heading `Log` (h2), below `Phases`.
- The section is collapsed by default. The body reveals behind a `more ▾` control — the checkbox + label trick (no JS), the same mechanism as the long-objective `more ▾` toggle. The control toggles between `more ▾` and `hide ▴`. It appears only when the ticket has at least one log entry.
- When expanded, the body shows the complete log — every entry, no truncation, no overflow tail — newest first, sourced from `LogService::listByTicket(ticketId, 'desc')` called in `TicketDeepController`. `TicketDeepViewBuilder` maps each entry to a `LogEntryViewModel`.
- Each entry is one line: the log `type` badge, the human-readable `title`, and the `timestamp`.
- An entry whose AI-legible detail (`ai_content`) is non-empty also carries a per-entry `detail ▾` control on that line — again the checkbox + label trick — that reveals the detail text below the line. Entries with empty `ai_content` have no per-entry control.
- When the ticket has no log entries, the section body reads `No log entries yet.` in muted grey, with no `more ▾` control.
- The checkbox folds keep the hidden content in the rendered HTML; collapsing only hides it visually.
- Read-only. No add-entry control, no editing.

## 2. Navigation rules

- The breadcrumb is the only back-navigation. The browser back button works as expected.
- All links in the body of a page navigate within the dashboard (same tab). External links would open in a new tab via `target="_blank" rel="noopener"`, but v1 has no external links.
- The `show_done` form is the only form in the dashboard. Its `GET` submission preserves `project` as a hidden field.
- Refreshing the page is the user's mechanism to see new state. The dashboard does not refresh itself.

## 3. Empty and error states

| Situation | Rendering |
|---|---|
| No projects | Project list with body text `No projects yet.` |
| Project has no tickets, done hidden | Ticket list with `No tickets (excluding done) in this project.` |
| Project has no tickets, done shown | Ticket list with `No tickets in this project.` |
| Ticket has no plan / phases | Ticket deep view with body text `No plan yet for this ticket.` |
| Phase has no tasks | Phase body shows `no tasks` |
| Ticket has no log entries | Ticket deep view `Log` section body shows `No log entries yet.` |
| Unknown `project` query parameter | Falls through to project list, no error |
| Unknown `ticket` query parameter (project resolves) | Falls through to ticket list, no error |
| `ai-lib` raises an error during deep-fetch | Page header (breadcrumb to the broken ticket) plus only the red error block in the body. No deep view layout, no ticket-list fallback. |
| Unknown route | `404 Not Found` with body text `Not Found` |

The dashboard never shows a "page not found" styled page in v1. The choice to fall through silently when a query parameter does not resolve is intentional.

## 4. Hierarchy summary

```
project list
    ↳ ticket list (show_done toggle)
        ↳ ticket deep view
            ├─ header (objective, more ▾ if long; details ▾ for ai_description)
            ├─ requirements (read-only list; verification markers: unverified/met/unmet)
            ├─ questions (read-only list; group markers: open/resolved_unprocessed/done)
            ├─ phases (auto-open first non-done)
            │   └─ tasks (collapsed; details ▾ for description + ai_result)
            └─ log (collapsed by default; more ▾ reveals the full log, one line per entry, detail ▾ per entry)
```

This is the surface area of v1, plus the ticket-detail `Log` section added post-v1 under ticket 20 and reshaped under ticket 24. The cross-project task list and pending-tasks header link added under ticket 31 were removed under ticket 157 (requirement 142). Ticket 179 task 2073 added a separate `Code Fixes` section (later removed by task 2077, requirement 297): `code_fix`-typed log entries only ever appear in the regular `Log` section. Ticket 192 task 2223 added the read-only `Questions` section next to `Requirements` (requirements 367/368/386).
