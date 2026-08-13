# `ai-dashboard` — Design System

This document specifies the visual language: tokens, type scale, marker shapes, status semantics, spacing, and layout rules.

For the page inventory, see `information-architecture.md`. For HTTP routes, see `../api/http.md`. For scope and intent, see `../../spec.md`.

## 1. Aesthetic intent

A cockpit, a command bridge. Calm, deliberate, expert. Quiet by default; loud only with reason. Structure replaces narrative — every visual decision should help the user *skip* content rather than read it. Compact, not cramped. Lives next to a terminal that already has the user's attention; never asks for it.

Hard rules that follow from this:

- **One earned accent.** Warm amber, reserved for the currently `active` state and for hover. Nothing else wears it.
- **Status by shape, not colour.** The left-gutter marker shape carries the primary signal; colour is secondary. The page must remain scannable in greyscale.
- **No motion.** No transitions on opacity or transform that draw the eye. The two short colour transitions on hover (120ms) are the only animation tolerated.
- **Dark mode only.** Light mode is not a v1 concern.

## 2. Tokens

All tokens live in `:root` in `public/static/style.css` as CSS custom properties. Colours are declared in OKLCH; the hue family is `~65–78` (warm amber-gold) for everything except semantic colours.

### 2.1 Colour

```
--bg:           oklch(0.16 0.005 65);  /* page background */
--surface:      oklch(0.20 0.006 65);  /* hovered row, slight lift */
--surface-2:    oklch(0.23 0.007 65);  /* second-level surface (rare) */
--text:         oklch(0.92 0.008 70);  /* primary text */
--text-muted:   oklch(0.66 0.008 70);  /* secondary text */
--text-faint:   oklch(0.50 0.008 70);  /* tertiary, dim labels */
--rule:         oklch(0.27 0.006 65);  /* hairlines */
--rule-strong:  oklch(0.34 0.008 65);  /* slightly heavier separators */

--accent:       oklch(0.80 0.13 78);   /* warm amber — earned, active only */
--accent-soft:  oklch(0.55 0.10 78);   /* dim amber for left rules and inactive borders */

--ok:           oklch(0.72 0.10 148);  /* green for ✓ */
--review:       oklch(0.84 0.13 95);   /* amber-yellow for ⚠ */
--failed:       oklch(0.64 0.15 28);   /* red for ✗ and blocked */
```

### 2.2 Type

```
--mono: ui-monospace, "JetBrains Mono", "Fira Code", "SF Mono", Menlo, monospace;
--sans: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", system-ui, sans-serif;
```

Body uses sans at 17px / 1.55. Identifiers (project names, ticket ids, phase ids, task ids, dates), code-shaped data (task descriptions), and the breadcrumb header use monospace.

### 2.3 Layout

```
--col: none;        /* content column max-width — unset; see §6 */
--pad-x: 100px;     /* left / right gutter on the main column */
```

The main column is full-width. Horizontal padding is 100px on each side. Page lives comfortably on the right half of a large monitor.

WCAG AA contrast applies for text against background. The OKLCH lightness values above are chosen for that contrast; do not adjust without re-measuring.

## 3. Status markers

Every row that displays an entity status (ticket row, phase summary, task summary) carries a 16px gutter cell on the left with one glyph, rendered via `.marker-<status>::before` in `style.css`. The glyph carries the primary signal; the colour is secondary.

| Status | Glyph | Size | Colour |
|---|---|---|---|
| `pending` | hollow circle (`○`) | 11px | `--text-faint` |
| `active` | thin bar (`\|`) | 14px, weight 300 | `--accent` |
| `done` | checkmark (`✓`) | 12px | `--ok` |
| `review` | warning triangle (`⚠`) | 11px | `--review` |
| `failed` | cross (`✗`) | 11px | `--failed` |
| `blocked` | cross (`✗`) | 11px | `--failed` |
| `skipped` | en dash (`–`) | 11px | `--text-faint` |

Rule of thumb: glyphs with a distinct footprint differ at a glance even in greyscale; `failed` and `blocked` deliberately share the same glyph and colour — the status word is what tells them apart. Do not redefine these.

## 4. Status semantics

Status colours appear in three places: the marker (above), the right-aligned status word, and a few row-level highlights.

| State | Marker colour | Status-word colour | Notable highlights |
|---|---|---|---|
| `pending` | `--text-faint` | `--text-faint` | None. |
| `active` | `--accent` | `--accent` | Ticket row's `ticket-id` also turns `--accent`. Active task's left body-rule is `--accent-soft`. |
| `done` | `--ok` | `--ok` | None. Marker and status word both go green. |
| `review` | `--review` | `--review` | Status word goes amber; nothing else flashes. |
| `failed` | `--failed` | `--failed` | Status word goes red; nothing else flashes. |
| `blocked` | `--failed` | `--failed` | Status word goes red; nothing else flashes. |
| `skipped` | `--text-faint` | `--text-faint` | None. Same tone as `pending`; distinguished by glyph only. |
| Opened task (independent of status) | unchanged | unchanged | Title and result-summary text turn `--accent`. This is a per-row "current focus" signal, distinct from the `active` state. |

The status word always renders in tracked uppercase at 13px with 0.10em letter-spacing, regardless of status. Status colour acts on the existing word, not on a chip or pill background.

## 5. Task result block

The result line carries no glyph and no status colour of its own — deliberately (see `.task-result`'s comment in `style.css`). Status is already signalled by the marker and status word on the row above (§3/§4); repeating it on the result line would be redundant. `.task-result-text` renders in plain `--text`; if the task is `done` with an empty result, the line shows `no summary` in italic `--text-faint` instead.

A task with hidden extras (its description above the result, `ai_result` below) gets a small `▾`/`▴` fold toggle (`.task-extras-label`) inline at the end of the result line.

Glyph is 14px wide, centre-aligned, weight 600. The glyph and the result text live on a single baseline; the text wraps under the glyph as a hanging indent in long results.

If the task is `done` but `result` is empty, the line shows the neutral glyph followed by `no summary` in italic muted grey.

## 6. Verification markers

The requirements section uses a different marker set from the status markers in §3. Each `.requirement` row carries a `data-verification` attribute; CSS attribute selectors on that attribute drive the marker glyph and colour without JavaScript.

| Verification | Glyph | Colour |
|---|---|---|
| `unverified` | `○` (hollow circle, 11px) | `--text-faint` |
| `met` | `✓` (checkmark, 12px) | `--ok` |
| `unmet` | `✗` (cross, 11px) | `--failed` |

The glyph is rendered as the `content` of a `::before` pseudo-element on `.verification-marker`. The `.verification-marker` element itself is a 16px × 16px inline-block gutter cell, matching the 16px gutter used for status markers. The `.verification-word` right-aligned label tracks the same rules as `.status-word` (13px, 0.10em letter-spacing, tracked uppercase); `met` renders in `--ok`, `unmet` in `--failed`, `unverified` inherits `--text-muted`.

## 7. Spacing and rhythm

- **Page header.** Sticky two-row band (ticket 164): a breadcrumb row (`.site-header-inner`, 14px top / 6px bottom padding) over an optional title row (`.site-header-title`, 2px top / 14px bottom padding), filled by the `titleline` block. Hairline border below in `--rule`, at the bottom of whichever rows are present — identical position on the three primary pages (both rows render, ~88px total height), shorter on pages with no `titleline` content (breadcrumb row only, ~43px). The title row's `h1`/`h2` is 19px.
- **Main column.** 20px gap between top-level sections; 64px bottom padding.
- **Section labels.** 13px, weight 600, 0.12em letter-spacing, uppercase, colour `--text-faint`. 8px bottom margin.
- **Project list rows.** 14px vertical padding. Hairlines top and bottom of each row.
- **Ticket list rows.** 12px vertical padding. Columns: gutter, name+id, date, type, priority, status — name+id is the only flexible column and claims all spare width; exact track sizes live in `.ticket-row a` in `style.css`. 16px gap. Hairlines top and bottom.
- **Phase summary.** 8px vertical padding. Columns: gutter, content, status; exact track sizes live in `style.css`. Hairlines top and bottom of the whole `<details>`.
- **Task list under a phase.** 16px left padding (indent under the phase gutter).
- **Task summary.** 4px vertical padding. Same three-column grid as phase. 3px border radius for hover background.
- **Task body (expanded).** 4px / 100px / 8px / 24px padding (top / right / bottom / left). 11px left margin. 1px solid left rule in `--rule` (or `--accent-soft` for active tasks). 6px gap between body children.

The `padding-right: 100px` on the task body deliberately reserves the column where the status word lives in the row above, so a long result text never visually crashes into the right-hand `DONE` / `ACTIVE` column.

## 8. Interaction surfaces

### 8.1 Hover

- Project list link: text colour transitions to `--accent` over 120ms.
- Ticket row link: background transitions to `--surface`; the `ticket-id` transitions to `--accent`.
- Phase summary: background transitions to `--surface`; the `phase-name` transitions to `--accent`.
- Task summary: background transitions to `--surface`; the `task-title` transitions to `--accent`.
- Header breadcrumb anchors: text transitions to `--accent`.

All hover transitions use `120ms ease-out` on `color` and / or `background`. No transform, no opacity.

### 8.1a Hover-reveal view link and panel edit-lock mode (ticket 153, requirements 126/127)

Hovering the ticket header (`.ticket-head-line`), a phase summary (`.phase > summary`), or a task row (`.task`) reveals a small `.row-view-link` button (labelled "view", never "edit") via a 120ms `opacity` transition — the one deliberate exception to the "no opacity/transform transitions" rule in §1, scoped narrowly to this reveal-on-hover affordance. The link also becomes visible on keyboard focus (`:focus-visible`), so it is reachable without a pointer. Clicking it opens the corresponding entity's panel read-only.

A panel — any element carrying a `data-mode` attribute — starts at `data-mode="view"` and switches to `data-mode="edit"` only when its `.edit-lock` button is clicked (see `app.js`). The lock glyph (closed padlock in view mode, open padlock in edit mode) is rendered via a `::before` pseudo-element keyed off the ancestor `data-mode`, the same mechanism used for `.marker` (§3) and `.verification-marker` (§6). The panel's read-only markup (`.panel-view`) and its edit `<form>` (`.panel-edit`) are both always rendered by the server; CSS shows exactly one, based on the same `data-mode` attribute. Edit mode is pure client-side UI state — not persisted anywhere — so it always resets to `view` the next time the panel is (re)opened.

This is the reusable mechanism; the ticket-head, phase, and task panels each apply it with their own fields in later tasks. As of ticket 153 task 1267, the task panel (`dialog#task-panel`) carries the mechanism as a working example: its `.task-panel-header` has the `.edit-lock` button and its body is split into `.panel-view` / `.panel-edit`, with `.panel-edit` currently a placeholder pending the task-panel-specific editing task.

### 8.2 Fixed truncation (ticket objective, phase description/AI description)

The ticket objective and each phase's description and AI description are truncated to a fixed 4-line clamp via the shared `.text-clamp` class (`-webkit-line-clamp: 4`), with no expand control. Reading the full text happens by opening the corresponding entity's panel — the ticket panel (`#ticket-panel`, ticket 153 task 1268) or a phase's panel (`#phase-panel`, ticket 153 task 1269) — via its hover-revealed `.row-view-link` (§8.1a). The panel's `.panel-view` always renders the untruncated text; truncation on the page itself is a CSS/visual concern only, not a data concern.

Before ticket 153 task 1271, the objective and the ticket AI description sat behind checkbox-driven `more ▾` / `less ▴` expand toggles (`.objective-toggle`, `.ai-description-toggle`) and a phase's description/AI description were separated by a `.phase-ai-toggle` checkbox-and-label fold. All three were removed once the ticket and phase panels became the place to read the full text; see `docs/decisions.md` for the removal note.

**The ticket AI description ("Details") no longer belongs to this family as of ticket 157.** It is a section-level open/closed toggle (`.ai-details`, `.details-toggle`), the same checkbox + label mechanism as the Requirements/Phases/Log section toggles, defaulting open and persisted per browser via `localStorage` (`tm_details_open`). When open, `.ai-description` shows the complete, untruncated text — no `.text-clamp`, no line limit, no gray box background — with `white-space: pre-wrap` and `word-break: break-word` so long unbroken tokens wrap instead of overflowing. When closed, the text is hidden entirely rather than clamped. There is no separate panel-based "full text" view for this field; the on-page toggle is the only place to read it. `.objective`, `.phase-desc`, and `.phase-ai-desc` are unchanged by this and remain fixed-truncated per the paragraphs above.

### 8.3 Toggle (task `details ▾`)

Same checkbox + label trick. Closed state: only the result block is visible. Open state: description appears above the result; `ai_result` appears below it. The label sits in the actions row.

### 8.4 Toggle (`show done` form)

A standard form `GET` submission, not a CSS toggle. The checkbox is visually hidden; the label is styled with a dotted underline and acts as the visible target. JavaScript `onchange="this.form.submit()"` triggers the submission — the only line of JS in the dashboard.

### 8.5 `<details>` for phases and tasks

Native `<details>` and `<summary>`. The default disclosure triangle is suppressed (`::-webkit-details-marker { display: none }` and `summary { list-style: none }`). The marker shape in the gutter takes its place visually.

The first non-`done` phase is auto-opened by the server emitting `<details open>`; phase open/closed state is not persisted across page loads.

As of ticket 198 task 2364, `.requirement` and `.question` rows use the same mechanism: each is a native `<details>` wrapping a `<summary>` (the marker/name/id/kind line) and its detail block (`.requirement-desc` or `.question-detail`). Unlike phases, no requirement or question row auto-opens — every row starts closed, and open state is not persisted across page loads. This replaces the previous `is-expanded` click toggle (`app.js`) that only un-truncated a long name; the single `<details>` toggle now does both jobs at once (reveals the detail block, lets a truncated name wrap). It is a passive display toggle: it changes what is visible, not what is editable. It does not reopen requirement 368 ("Answering happens in conversation; the dashboard stays read-only") — nothing on the row becomes answerable, editable, or otherwise mutable from the dashboard by opening it.

## 9. Class-name catalogue

Templates and CSS are coupled through class names. The canonical list:

| Class | Used on | Purpose |
|---|---|---|
| `site-header`, `site-header-inner`, `home`, `sep` | header | Breadcrumb row. As of ticket 164, `.site-header` is `position: sticky; top: 0` with an opaque `background: var(--bg)`, so it stays pinned above the page while the page scrolls under it and scrolled content never shows through. |
| `site-header-title` | div | Second row of the sticky band, filled by the `titleline` Twig block (ticket 164, requirements 191/192/195/197). Only rendered when a page defines that block with non-empty content — `not_found.html.twig` and `ticket_deep_error.html.twig` never do, so the band shrinks to the breadcrumb row alone there (requirement 195's error-page exemption). Same `--col`/`--pad-x` horizontal rhythm as `.site-header-inner` and `main`, so a right-aligned child (the ticket page's `.ticket-head-buttons`, via its `margin-left: auto`) lands on the same vertical line as content below. Its `h1`/`h2` rule is the one shared CSS rule set that gives the title row identical height and typography across the three primary pages, replacing the pre-164 `.ticket-list-head h2` and `.ticket-head h1` rules that each declared their own 24px size. |
| `section-label` | h2/h3 elements | Tracked uppercase section heading |
| `empty` | p | "No projects yet" / "No tickets" / "No plan yet" |
| `error` | div | Red top-bordered error block |
| `marker`, `marker-pending`, `marker-active`, `marker-done`, `marker-review`, `marker-failed`, `marker-blocked`, `marker-skipped` | span | Gutter status shape |
| `projects-list` | ul | Project landing list |
| `ticket-list-head`, `toggle-form`, `is-on` | div / form | As of ticket 164, holds only the show-done toggle — the heading it used to hold alongside the toggle now renders in the sticky title band (`.site-header-title`) via the `titleline` block, so this row right-aligns its single remaining child instead of space-between. |
| `ticket-list-title` | div | Wraps the project title `<h2>` and `.create-ticket-btn`. As of ticket 164, rendered inside the sticky title band via the `titleline` block rather than inside `.ticket-list-head` (ticket 160 task 1433 introduced the pairing; ticket 164 moved it into the band). The `<h2>` carries a `title` attribute with the full project name and never wraps (`white-space: nowrap; overflow: hidden; text-overflow: ellipsis`), truncating with an ellipsis instead — requirement 197. |
| `create-ticket-btn` | button | Always-visible round "+" button next to the project title; opens `#create-ticket-panel` (ticket 160 task 1433, requirements 154/155) |
| `create-ticket-panel`, `create-ticket-error`, `create-ticket-form`, `create-ticket-field`, `create-ticket-actions`, `create-ticket-save` | dialog / div / form / div / div / button | Create-ticket popup on the project page (ticket 160 task 1433, requirements 154/155): `title`/`description`/template `<select>` fields, POSTing to `/project/{id}/ticket/create` (§2.9 of `../api/http.md`, added by task 1432). Reuses the shared `task-panel` class for identical dialog chrome and joins the `ticket-edit-*`/`phase-edit-*`/`task-edit-*` combined selectors for identical form styling, but carries no `data-mode` — it has no read-only state to toggle away from, unlike the edit panels. `create-ticket-error` renders the parsed `bin/tm` error message after a failed create; absent on a normal render. The dialog auto-opens on load via `data-open-on-load` when the view model reports the popup should be open (a failed create re-render), the same convention `ticket-panel` uses. |
| `ticket-list`, `ticket-row`, `ticket-name-wrap`, `ticket-objective`, `ticket-id`, `ticket-date`, `ticket-type`, `ticket-priority`, `priority-chip`, `status-word` | ul / li / spans | Ticket row layout |
| `ticket-head`, `objective` | section / p | As of ticket 164, holds only the ticket objective (fixed-truncated per §8.2), rendered only when the objective is non-empty. The title/id/type/status/buttons line that used to live here moved into the sticky title band — see `ticket-head-line` below. |
| `ticket-head-line`, `ticket-head-id`, `ticket-status` | div / span | Ticket title row: title, `[id]`, type, status, the "view" button, and `.ticket-head-buttons`. As of ticket 164, rendered inside the sticky title band via the `titleline` block, and `.ticket-head-line` itself now carries `data-status="{status}"` (previously carried by the ancestor `.ticket-head`, before the line moved out of it) — the five `.ticket-head-line[data-status="…"] .ticket-status` colour rules and the `h1`/`.ticket-type` styling are anchored on it. The `<h1>` carries a `title` attribute with the full ticket name and never wraps (`white-space: nowrap; overflow: hidden; text-overflow: ellipsis`), truncating with an ellipsis instead — requirement 197. `app.js`'s `applyTicketStatus()` (used by the phase/task status-rollup response handler, requirement 173) reads and writes `data-status` here now, not on `.ticket-head`. |
| `ticket-head-buttons` | div | Ticket 164 task 1592: flex wrapper at the right end of `.ticket-head-line` holding `.ticket-ide-btn` when rendered (empty otherwise). Carries `margin-left: auto` (pushing the button to the row's right edge) and `flex: 0 0 auto` (a fixed size, so title truncation per requirement 197 never eats into it). Ticket 269 removed the sibling `.ticket-terminal-btn` this wrapper used to hold alongside `.ticket-ide-btn`. |
| `text-clamp` | p / div | Shared 4-line `-webkit-line-clamp` truncation, no expand control (§8.2, ticket 153 task 1271); applied to `.objective`, `.phase-desc`, `.phase-ai-desc` |
| `ai-details`, `details-toggle` | section / input | Details section (ticket AI description); section-level open/closed toggle identical in mechanism to Requirements/Phases/Log, defaulting open, persisted via `localStorage` (`tm_details_open`) (§8.2, ticket 157) |
| `ai-description` | div | Full, untruncated ticket AI description text inside the Details section; monospace, word-wrapped, no `.text-clamp` (§8.2, ticket 157 — previously fixed-truncated alongside `.objective`) |
| `phase-desc`, `phase-ai-desc`, `phase-sep` | div / hr | A phase's description and AI description, each fixed-truncated via `.text-clamp` (§8.2); `.phase-sep` is a hairline rendered between them only when both are present |
| `ticket-ide-btn` | button | Round single-character ("i") button inside `.ticket-head-buttons` (ticket 164 task 1592, requirement 204); posts to `POST /ticket/{id}/open-ide` (`../api/http.md` §2.10) to launch the configured editor in the ticket's workspace directory. Styled identically to `.create-ticket-btn` (26x26px accent-soft circle) via the shared selector list on that rule. Rendered only when `TicketDeepViewModel::$ideConfigured` is `true` — i.e. `~/.ai-dashboard/config.toml` carries a usable `ide_command` (requirement 202, ticket 164 task 1591). Ticket 269 removed the sibling `.ticket-terminal-btn` ("t") button (ticket 137, jumped to the ticket's `ai-tmux` terminal) and `ai-dashboard`'s entire dependency on `ai-tmux`. |
| `requirements` | section | Requirements section container |
| `requirement` | details | Single requirement row; carries `data-verification="unverified\|met\|unmet"`. As of ticket 198 task 2364, wraps its `<summary>` (marker/name/id/verification word) and `.requirement-desc` in a native `<details>`, collapsed by default and disclosed by clicking the summary — same idiom as `.phase` (§8.5) |
| `verification-marker` | span | 16px gutter cell; glyph rendered via `::before` pseudo-element keyed on `data-verification` |
| `requirement-name-wrap` | span | Flex group wrapping `.requirement-name` + `.requirement-id`; carries the row's `flex: 1 1 auto` sizing (same idiom as `.ticket-name-wrap`/`.phase-name-wrap`), so the id badge sits immediately after the name instead of being pushed to the row's right edge (ticket 198 QA pass 3, fixes the id badge alignment bug found in dogfooding) |
| `requirement-name` | span | Requirement name, ellipsis-truncated within `.requirement-name-wrap` while `.requirement` is closed, word-wrapped once opened (`.requirement[open] .requirement-name`) |
| `requirement-id` | span | Requirement's `[id]` badge next to `.requirement-name`, inside `.requirement-name-wrap` (ticket 198 task 2363), joined into the shared mono/13px/`--text-faint` selector alongside `.phase-id`/`.task-id`/`.ticket-id` (ticket 198 QA pass, closes question 41) |
| `verification-word` | span | Right-aligned verification status label (tracked uppercase, 13px; colour follows §6) |
| `requirement-desc` | p | Optional requirement description, monospace 13px, `--text-muted`. As of ticket 198 task 2364, rendered inside `.requirement`'s `<details>`, collapsed by default and revealed only when the row is opened — a passive display toggle, not a new interactive control |
| `questions` | section | Questions section container, adjacent to `requirements` (ticket 192 task 2223, requirements 367/368/386) |
| `question` | details | Single question row; carries `data-group="open\|resolved_unprocessed\|done"`, the same three-way grouping every question listing in the toolset uses. As of ticket 198 task 2364, wraps its `<summary>` (marker/name/id/kind/state/group label) and `.question-detail` in a native `<details>`, collapsed by default and disclosed by clicking the summary — same idiom as `.phase` (§8.5) |
| `question-group-marker` | span | 16px gutter cell; glyph rendered via `::before` pseudo-element keyed on `data-group` — hollow circle (`open`), warning triangle (`resolved_unprocessed`), checkmark (`done`); reuses the existing pending/review/done glyphs and colours instead of introducing new ones, so `--accent` stays reserved for the `active` entity status (§1) |
| `question-name-wrap` | span | Flex group wrapping `.question-name` + `.question-id`; carries the row's `flex: 1 1 auto` sizing (same idiom as `.ticket-name-wrap`/`.phase-name-wrap`/`.requirement-name-wrap`), so the id badge sits immediately after the name instead of being pushed to the row's right edge (ticket 198 QA pass 3, fixes the id badge alignment bug found in dogfooding) |
| `question-name` | span | Question name, ellipsis-truncated within `.question-name-wrap` while `.question` is closed, word-wrapped once opened (`.question[open] .question-name`, ticket 198 task 2364 — replaces the earlier `is-expanded` click toggle in `app.js`) — same mechanism as `.requirement-name` |
| `question-id` | span | Question's `[id]` badge next to `.question-name`, inside `.question-name-wrap` (ticket 198 task 2363), joined into the shared mono/13px/`--text-faint` selector alongside `.phase-id`/`.task-id`/`.ticket-id` (ticket 198 QA pass, closes question 41) |
| `question-kind` | span | The question's kind (`ask`/`check`), plain uppercase muted text styled like `.ticket-type` |
| `question-state` | span | The question's `state` (`open`, or its resolution once resolved: `accepted`/`answered`/`withdrawn`), tracked uppercase like `.verification-word` |
| `question-group-label` | span | Right-aligned group label (`needs you`/`needs agent`/`done`), tracked uppercase; colour follows the same group as the marker |
| `question-detail`, `question-detail-field` | div / p | Block below a question row holding its question/background/recommendation/answer text, one labelled paragraph per non-empty field. As of ticket 198 task 2364, rendered inside `.question`'s `<details>` (same mechanism as `.requirement-desc` above), collapsed by default and revealed by clicking the row's `<summary>` (name/id/kind line) — a passive display toggle, not a new interactive control. This does not reopen requirement 368's "dashboard stays read-only" decision: nothing becomes editable or answerable here that was not already displayed |
| `phases`, `phase`, `phase-name-wrap`, `phase-name`, `phase-id`, `phase-status` | section / details | Phase block |
| `phase-attempts` | span | Phase's run count in the summary row (`N/M runs`), rendered next to `.phase-name` only when `max_attempts > 0` (ticket 179 task 2072, requirement 306); styled identically to `.task-attempts` (mono, 13px, `--text-faint`, non-shrinking). Also drives the `Max attempts`/`Attempts` rows in the phase panel's `.task-panel-meta` `<dl>`, mirroring the task panel's equivalent conditional rows. The marker's visible word is "runs", not "attempts" — `attempts` now counts finished runs rather than fix-rounds within one run, so the shorter word reads correctly for a check that ran once and passed (`1/3 runs`, not `0/3 attempts`); `.task-attempts`'s marker text was updated to match in the same commit. The `<dl>` row labels themselves stay `Max attempts`/`Attempts`, unchanged, matching the underlying `max_attempts`/`attempts` column names. |
| `tasks`, `task`, `task-title-wrap`, `task-title`, `task-id`, `task-status` | ul / li / spans | Task row in the phase list (clickable; opens the side panel) |
| `task-model` | span | Task's model id in the compact row (ticket 174 task 2062, requirement 281), plain single-line-truncated text (same ellipsis idiom as `.project-name`/`.task-title`), rendered next to `.task-attempts`/`.task-actor`; omitted from the row entirely when the task has no model set. Not a tier-keyed icon/badge — `model` is a free-form string with no fixed vocabulary to key a badge scheme off, unlike `.task-actor`'s two-value emoji. The detail panel's own `<dt>Model</dt><dd>{{ task.model\|default('—') }}</dd>` pair in `.task-panel-meta` reuses that section's plain `<dl>` markup and needs no class of its own. |
| `status-cycle` | span | Added to `.task-status` and `.phase-status` (ticket 159); marks the status word itself as a distinct click target that steps to the next status in its entity's cycle. Cursor is `pointer`; hover/focus-visible affordance is an underline fading in via a `text-decoration-color` transition (120ms, the sanctioned hover timing), so the status word's semantic colour from §4 is never overridden and no layout shift occurs. The click handler and the cycle order live in `app.js`; the ticket header's `.ticket-status` does not carry this class and stays non-interactive. |
| `task-panel`, `task-panel-close`, `task-body`, `task-desc` | dialog / button / divs | Task detail side panel (right-edge sliding sheet) and its body content. `task-panel` is a shared class, not tied to `#task-panel`'s id — `#ticket-panel` (ticket 153 task 1268) and `#phase-panel` (ticket 153 task 1269) also carry it to get identical dialog/header/body/section styling without duplicating rules. |
| `row-view-link` | button | Hover-revealed (or focus-revealed) "view" link on `.ticket-head-line`, `.phase > summary`, and `.task`; opens the entity's panel read-only (ticket 153 task 1267, requirements 126/127). On a phase row the link carries `data-phase-id` and sits inside `.phase-name-wrap`; clicking it opens `#phase-panel` (ticket 153 task 1269) without toggling the enclosing `<details>`, since `initRowViewLinks()` already binds `preventDefault()`/`stopPropagation()` to any `.row-view-link` nested in a `<summary>`. |
| `edit-lock` | button | Lock-icon button inside a panel; toggles the panel's `data-mode` between `view` and `edit`; glyph keyed off the ancestor `data-mode` (ticket 153 task 1267) |
| `panel-view`, `panel-edit` | div | Panel wrapper pair; both always rendered server-side, CSS shows exactly one based on the panel's `data-mode` (ticket 153 task 1267) |
| `task-result`, `task-result-text` | div / span | Task result line (inside the side panel); `task-result-text` carries `.is-empty` when the task is `done` with no result |
| `task-extras-toggle`, `task-extras-label`, `extras-desc`, `extras-details` | input / label / div | Task `details ▾` mechanism (inside the side panel) |
| `task-actions` | div | Bottom-of-panel action row |
| `terminal-error`, `terminal-error-close`, `terminal-error-message` | dialog / button / p | Centered, dismiss-only error dialog. The class/id name predates the terminal-jump button it was originally built for (ticket 137) and is unchanged now that button is gone (ticket 269). Two entry points remain: the IDE-open `fetch POST` (ticket 164 task 1592, `.ticket-ide-btn`) returning non-2xx or failing at the network level (click-driven, no page reload); and the phase/task click-to-cycle status controls (ticket 159, requirement 171) reporting a failed `POST /task\|phase/{id}/status`. Both paths reuse the same `showTerminalError()` JS function and render into the same dialog; they never occur on the same page load. Ticket 269 removed the dialog's third, load-driven entry point (a failed `ai-tmux open` after a create-ticket-from-project-page submission, reported via the `terminal_error` query parameter) along with the `data-open-on-load` wiring that drove it — see below. |
| `ticket-edit-error`, `ticket-edit-form`, `ticket-edit-field`, `ticket-edit-actions`, `ticket-edit-save` | div / form / div / div / button | The `name`/`description`/`ai_description` edit form inside `#ticket-panel`'s `.panel-edit` (ticket 153 task 1268, requirements 122/123/130-133). `ticket-edit-error` renders the parsed `bin/tm` error message after a failed save; absent on a normal render. |
| `phase-edit-error`, `phase-edit-form`, `phase-edit-field`, `phase-edit-actions`, `phase-edit-save` | div / form / div / div / button | The phase-panel equivalent of the `ticket-edit-*` family above: the `name`/`description`/`ai_description` edit form inside a phase's `<template id="phase-data-{id}">`, cloned into `#phase-panel`'s `.panel-edit` (ticket 153 task 1269, requirements 122/124/130-133/137). Named separately per entity rather than shared, matching the existing per-entity naming convention; styled identically to `ticket-edit-*` via a combined CSS selector. `phase-edit-error` renders the parsed `bin/tm` error message after a failed save on that phase; absent on a normal render. |
| `task-edit-error`, `task-edit-form`, `task-edit-field`, `task-edit-actions`, `task-edit-save` | div / form / div / div / button | The task-panel equivalent of the `ticket-edit-*`/`phase-edit-*` families above: the `name`/`description`/`ai_description`/`actor`/`max_attempts` edit form inside a task's `<template id="task-data-{id}">`, cloned into `#task-panel`'s `.panel-edit` (ticket 153 task 1270, requirements 124/126/127/130-133/136/137/139). Named separately per entity rather than shared, matching the existing per-entity naming convention; styled identically to `ticket-edit-*`/`phase-edit-*` via a combined CSS selector, including added rules for the `actor` `<select>` and the `max_attempts` number input those two entities' forms do not have. `task-edit-error` renders the parsed `bin/tm` error message after a failed save on that task; absent on a normal render. Unlike the ticket/phase panels, `.task-panel-meta` sits outside both `.panel-view` and `.panel-edit` in the task panel's `<template>` (mirroring how `#ticket-panel`/`#phase-panel` already keep their own meta section always visible), so the task's own read-only columns (`phaseId`, `order`, `archivedAt` among them, added under task 1270 to complete requirement 137's "every column" rule) stay visible in both modes while status-transition history and log entries stay inside `.panel-view` only, per requirement 137's "own row columns only" rule for edit mode. |

A `data-status` attribute on `.ticket-row`, `.phase`, `.task`, `.ticket-head-line` carries the status string for the colour rules in §4. Templates set `data-status="{{ status }}"`; CSS reads it via attribute selectors. (Before ticket 164, the ticket page carried this attribute on `.ticket-head`; it moved to `.ticket-head-line` when that line moved into the sticky title band.)

A `data-verification` attribute on `.requirement` carries one of `unverified`, `met`, or `unmet`. CSS reads it to select the correct glyph and colour for `.verification-marker` and `.verification-word`, as described in §6.

A `data-group` attribute on `.question` (ticket 192 task 2223) carries one of `open`, `resolved_unprocessed`, or `done` — `TicketDeepViewBuilder` derives it from the question's `state`/`processedAt` the same way `QuestionService::isInGroup()` does. CSS reads it via attribute selectors to select the glyph and colour for `.question-group-marker` and the colour for `.question-group-label`, reusing the existing pending/review/done tokens rather than introducing new ones.

A `data-mode` attribute on a panel element carries `view` (read-only, default) or `edit`. CSS reads it to pick the `.edit-lock` glyph and to show `.panel-view` or `.panel-edit`, as described in §8.1a. `app.js`'s `initRowViewLinks()` and its delegated `.edit-lock` click handler implement the JS side of the mechanism.

A `data-open-on-load` attribute (present/absent, no value) on `#ticket-panel` (ticket 153 task 1268) tells `app.js` to call `showModal()` on `DOMContentLoaded` without resetting `data-mode` first. The server renders it, alongside `data-mode="edit"`, only when a `POST /ticket/{id}/edit` save failed and the page was re-rendered with the edit error — mirroring the `?task=<id>` auto-open-on-load pattern used for the task panel, but as a server-rendered flag instead of a URL query parameter, since a plain full-page reload with an unchanged URL is required on save (requirement 132).

`#phase-panel` (ticket 153 task 1269) carries the same `data-open-on-load` / `data-mode="edit"` pair after a failed `POST /phase/{id}/edit`, plus `data-open-phase-id="{id}"` naming which phase's panel to open — needed because, unlike the single per-page ticket panel, several phases can exist on one page. On load, `app.js` reads `data-open-phase-id`, clones that phase's `<template id="phase-data-{id}">` into `#phase-panel` (the same cloning mechanism `#task-panel` uses), and opens it directly in edit mode.

`#task-panel` (ticket 153 task 1270) gains the same `data-open-on-load` / `data-mode="edit"` pair after a failed `POST /task/{id}/edit`, plus `data-open-task-id="{id}"` naming which task's panel to open, mirroring `#phase-panel`'s `data-open-phase-id`. This sits alongside `#task-panel`'s pre-existing `?task=<id>` URL-based auto-open used for plain read-only opens (§2.1's task-row click path): on load, `app.js` first checks the `?task=` query parameter for a read-only open, and only falls back to `data-open-on-load`/`data-open-task-id` when that parameter is absent, since a failed save's re-render never carries the query parameter (the browser's address bar reflects the `POST /task/{id}/edit` target, not a `?task=`-bearing URL).

`#terminal-error` no longer carries `data-open-on-load` or any load-driven auto-open path. Ticket 160 task 1434 originally wired `TicketDeepViewBuilder` to set that attribute from a `terminal_error` GET parameter on the create-ticket redirect; ticket 269 removed the `ai-tmux` call that parameter reported on, and with it that wiring — `#terminal-error` now only ever opens click-driven, via `showTerminalError()` (see the class-name catalogue above). `#terminal-warning`, the softer sibling dialog ticket 160 task 1482 added for the `EX_PROMPT_UNCONFIRMED` case, was removed in the same ticket 269 change; it no longer exists in `ticket_deep.html.twig` or `style.css`.

`#create-ticket-panel` (ticket 160 task 1433) uses the same attribute after a failed `POST /project/{id}/ticket/create` (`../api/http.md` §2.9), the same way `#ticket-panel` uses it after a failed edit save.

There are no class names for a transcript route (`block-text`, `block-thinking`, `block-tool-use`, `block-tool-use-group`, `block-diff`, `diff-*`), because transcripts are out of scope, and none for notes (`notes`, `notes-tag`), because the notes section is deferred from v1.
