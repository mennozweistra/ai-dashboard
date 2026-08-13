# `ai-dashboard` — HTTP Reference

This document specifies every HTTP route `ai-dashboard` exposes: query parameters, response status, response body shape, and error rendering.

For scope and intent, see `../../spec.md`. For the architecture, see `../architecture/architecture.md`. For the page inventory and navigation model, see `../ui/information-architecture.md`. For the visual language, see `../ui/design-system.md`.

## 1. Conventions

### 1.1 Methods

Most routes are `GET` and read-only. As of ticket 137, one exception exists: `POST` action routes are permitted when the only effect of the request is to shell out to an external command (`bin/tm`, or a machine-local `ide_command`) through a thin wrapper class in the `Http` layer. An action route still never calls an `ai-lib` operation that writes; it reads what it needs from `ai-lib` and then invokes the external command. No `PUT`, `PATCH`, or `DELETE` routes exist, and no route mutates `ai-lib` state directly.

**Security stance for action routes.** The dashboard is a local, single-user tool bound to `127.0.0.1`. Action routes carry no CSRF token and no authentication in v1 — this is a deliberate decision, not an oversight, because the only caller is the same user's own browser on the same machine. Revisit this stance if the dashboard is ever exposed beyond localhost.

### 1.2 Response content type

- HTML routes return `text/html; charset=utf-8`.
- Static asset routes return the appropriate MIME type for the file.
- As of ticket 159, two routes are a deliberate, user-approved exception to "no JSON": `POST /task/{id}/status` and `POST /phase/{id}/status` (§2.7, §2.8) return `application/json`. The click-to-cycle status control needs the rollup-changed parent statuses (a task status change can change its phase's and ticket's status; a phase status change can change its ticket's status) back in one response so the frontend can update every affected element in place without a page reload. HTML could not carry that back in a shape the frontend could consume without also re-rendering markup, which the feature's local-cycling design deliberately avoids. Every other route stays HTML or plain text, unchanged.

### 1.3 Cache headers

Every dynamic HTML response sets `Cache-Control: no-store, must-revalidate`. The browser revalidates on every navigation. The reason: this is a single-user development tool with rapidly evolving templates and CSS, and route-shape changes (for example, the partial-vs-full HTML split) must never be masked by a stale cached response.

Static assets under `/static/` are served with default caching (no explicit `Cache-Control` override). The browser may cache them for the session.

### 1.4 Error rendering

Two failure modes:

- **Caller error** (404 unknown route, malformed query parameters that the route cannot interpret): respond with the appropriate HTTP status and a minimal HTML body. No designed error page in v1.
- **`ai-lib` error** during a deep-fetch (for example, a `DomainException` thrown when a referenced row is missing or invalid): respond with `200 OK`. The page header renders normally with the breadcrumb to the broken ticket; the page body contains only the red error block carrying `$exception->getMessage()`. The deep-view layout is not rendered.

The dashboard does not catch unexpected exceptions and present them as friendly pages. A `500 Internal Server Error` from an uncaught exception is the right response in v1; the user sees the stack trace, fixes the bug, restarts.

### 1.5 Logging

Each request produces a single line on stderr: timestamp, method, path, status, duration. Errors include the exception class and message. There is no structured log format and no log file in v1.

## 2. Routes

### 2.1 `GET /`

The single page. Renders one of three states based on query parameters.

#### Query parameters

| Parameter | Type | Required | Meaning |
|---|---|---|---|
| `project` | integer | No | Id of the project to display. If absent, the response is the project-list state. |
| `ticket` | integer | No | Id of the ticket to display in the deep view. Requires `project` to also be present and to match the ticket's project. |
| `show_done` | any value | No | If present (any value, including empty string), the ticket list includes tickets whose status is `done`. The form submission renders this as `show_done=1`. If absent, done tickets are hidden. |

#### State resolution

Three states resolve in this order:

1. **Deep ticket view** — both `project` and `ticket` are positive integers, the project exists and is not archived, and the ticket exists in that project and is not archived. Renders the ticket header, the requirements section, the questions section (ticket 192, adjacent to requirements, above phases), and the phase / task tree.
2. **Ticket list** — `project` is present and resolves to an existing non-archived project, `ticket` is absent or does not resolve. Renders the list of tickets in that project, filtered by `show_done`.
3. **Project list** — `project` is absent or does not resolve. Renders the list of non-archived projects.

The `tasks` query parameter and the cross-project task list it drove (state resolution's former highest-precedence state) were removed under ticket 157 (requirement 142), along with the "Pending tasks" header link that was the only way to reach it. `GET /` no longer recognises `tasks` at all.

The fall-through is silent: a `?project=999999` request to a non-existent project id does **not** produce a 404. It renders the project list with no error message. The same lenience applies to a `?project=4&ticket=999` request when ticket 999 does not exist or belongs to a different project — the response is the ticket-list state for project 4 with no error.

The exception is when `project` and `ticket` both resolve correctly but `ai-lib`'s deep-fetch raises a `\AiToolset\AiLib\Domain\Exception\DomainException` (for example, a data integrity issue): the response keeps the deep-view URL unchanged, renders the breadcrumb (`tm › <project> › <ticket-id>`), and renders only the red error block in the page body. The deep view layout is **not** rendered, and there is no fallback to the ticket-list view. See §1.4.

#### Response

`200 OK`, `text/html`. Body is the rendered Twig template. The exact HTML structure is part of the contract; see `../ui/information-architecture.md` for the page inventory and `../ui/design-system.md` for the visual structure.

### 2.2 `GET /static/{path}`

Serves files from `public/static/` by path. The only file in v1 is `style.css`. The path is sanitised to prevent directory traversal; requests outside `public/static/` produce `404 Not Found`.

#### Response

`200 OK` with the file contents and the appropriate MIME type, or `404 Not Found` for an unknown file.

### 2.3 Unknown routes

Any route not matching one of the routes documented in this section produces `404 Not Found` with a minimal HTML body containing the text "Not Found". No styled error page in v1.

### 2.4 `POST /ticket/{id}/edit`

Saves edits made in the ticket header's edit panel (ticket 153, requirements 122/123/130/131/132/133). `{id}` is the ticket id and must match `\d+`; a non-numeric id does not match the route and produces `404 Not Found` the same as any other unmatched route. A `GET` (or any other method) against this path produces `405 Method Not Allowed` with an `Allow: POST` header.

#### Behaviour

The controller reads the ticket via `TicketService::show({id})` only to resolve its `projectId` for the redirect/re-render target. It then shells out to `bin/tm ticket:set` through `TmCliRunner` (§1.1). No `ai-lib` write ever happens on this route.

#### Request body

Standard HTML form POST (`application/x-www-form-urlencoded`), no JSON. Accepted fields, all optional:

| Field | Maps to `ticket:set` option | Meaning |
|---|---|---|
| `name` | `--name` | New ticket name |
| `description` | `--description` | New ticket description |
| `ai_description` | `--ai-description` | New ticket AI description |

A field absent from the POST body is not forwarded to `ticket:set` at all, so `bin/tm` leaves that column unchanged — the same "missing option means leave unchanged" rule `TicketSetCommand` already applies to its own callers.

#### Response

- **Success**: `302 Found`, `Location: /?project={projectId}&ticket={id}`. No body. The browser performs a plain full-page reload of the ticket deep view — no JSON, no partial response (requirement 132).
- **Ticket not found** (`{id}` does not resolve to a ticket): `404 Not Found`, `text/plain; charset=utf-8` body.
- **`bin/tm` failure** (non-zero exit, `ticket:set` rejects the change): `200 OK`, `text/html; charset=utf-8`. The full `ticket_deep.html.twig` page re-renders (the same rendering path `GET /` uses for the deep view), with the ticket panel opened already in edit mode, the parsed `error.message` from `bin/tm`'s JSON envelope displayed inside the panel (never the raw JSON — requirement 131), and the just-submitted `name`/`description`/`ai_description` values preserved in the form inputs instead of the stored values, so the user does not lose typed text.

### 2.5 `POST /phase/{id}/edit`

Saves edits made in a phase's edit panel (ticket 153, requirements 122/124/126/127/130/131/132/133/137/139). `{id}` is the phase id and must match `\d+`; a non-numeric id does not match the route and produces `404 Not Found` the same as any other unmatched route. A `GET` (or any other method) against this path produces `405 Method Not Allowed` with an `Allow: POST` header.

#### Behaviour

The controller reads the phase via `PhaseService::show({id})` to resolve its `ticketId`, then the ticket via `TicketService::show(ticketId)` to resolve the `projectId` needed for the redirect/re-render target. It then shells out to `bin/tm phase:set` through `TmCliRunner`, the same thin-wrapper pattern `TicketEditController` uses for `ticket:set` (§2.4). No `ai-lib` write ever happens on this route.

#### Request body

Standard HTML form POST (`application/x-www-form-urlencoded`), no JSON. Accepted fields, all optional:

| Field | Maps to `phase:set` option | Meaning |
|---|---|---|
| `name` | `--name` | New phase name |
| `description` | `--description` | New phase description |
| `ai_description` | `--ai-description` | New phase AI description |

A field absent from the POST body is not forwarded to `phase:set` at all, so `bin/tm` leaves that column unchanged, mirroring the `ticket:set` behaviour in §2.4.

#### Response

- **Success**: `302 Found`, `Location: /?project={projectId}&ticket={ticketId}`. No body. The browser performs a plain full-page reload of the ticket deep view — no JSON, no partial response (requirement 132).
- **Phase not found, or its ticket cannot be resolved** (`{id}` does not resolve to a phase, or the phase's ticket does not resolve): `404 Not Found`, `text/plain; charset=utf-8` body.
- **`bin/tm` failure** (non-zero exit, `phase:set` rejects the change): `200 OK`, `text/html; charset=utf-8`. The full `ticket_deep.html.twig` page re-renders (the same rendering path `GET /` uses for the deep view), with that specific phase's panel opened already in edit mode, the parsed `error.message` from `bin/tm`'s JSON envelope displayed inside that phase's panel (never the raw JSON — requirement 131), and the just-submitted `name`/`description`/`ai_description` values preserved in that phase's form inputs instead of the stored values. Every other phase on the page keeps its normal read-only rendering.

### 2.6 `POST /task/{id}/edit`

Saves edits made in a task's edit panel (ticket 153, requirements 124/126/127/130/131/132/133/136/137/139). `{id}` is the task id and must match `\d+`; a non-numeric id does not match the route and produces `404 Not Found` the same as any other unmatched route. A `GET` (or any other method) against this path produces `405 Method Not Allowed` with an `Allow: POST` header.

#### Behaviour

The controller reads the task via `TaskService::show({id})` to resolve its `phaseId`, then the phase via `PhaseService::show(phaseId)` to resolve its `ticketId`, then the ticket via `TicketService::show(ticketId)` to resolve the `projectId` needed for the redirect/re-render target — the same lookup-chain pattern `PhaseEditController` uses one level up (§2.5). It then shells out to `bin/tm task:set` through `TmCliRunner`, the same thin-wrapper pattern `TicketEditController`/`PhaseEditController` use for `ticket:set`/`phase:set`. No `ai-lib` write ever happens on this route.

#### Request body

Standard HTML form POST (`application/x-www-form-urlencoded`), no JSON. Accepted fields, all optional:

| Field | Maps to `task:set` option | Meaning |
|---|---|---|
| `name` | `--name` | New task name |
| `description` | `--description` | New task description |
| `ai_description` | `--ai-description` | New task AI description |
| `actor` | `--actor` | New task actor (`agent` or `human`) |
| `max_attempts` | `--max-attempts` | New task max-attempts value |

A field absent from the POST body is not forwarded to `task:set` at all, so `bin/tm` leaves that column unchanged, mirroring the `ticket:set`/`phase:set` behaviour in §2.4/§2.5.

#### Response

- **Success**: `302 Found`, `Location: /?project={projectId}&ticket={ticketId}`. No body. The browser performs a plain full-page reload of the ticket deep view — no JSON, no partial response (requirement 132).
- **Task not found, or its phase/ticket cannot be resolved** (`{id}` does not resolve to a task, or the task's phase or the phase's ticket does not resolve): `404 Not Found`, `text/plain; charset=utf-8` body.
- **`bin/tm` failure** (non-zero exit, `task:set` rejects the change — for example `TaskService::validateActor()` rejecting an `actor` value other than `agent`/`human`): `200 OK`, `text/html; charset=utf-8`. The full `ticket_deep.html.twig` page re-renders (the same rendering path `GET /` uses for the deep view), with that specific task's panel opened already in edit mode, the parsed `error.message` from `bin/tm`'s JSON envelope displayed inside that task's panel (never the raw JSON — requirement 131), and the just-submitted `name`/`description`/`ai_description`/`actor`/`max_attempts` values preserved in that task's form inputs instead of the stored values. Every other task on the page keeps its normal read-only rendering.

### 2.7 `POST /task/{id}/status`

Applies the final value the ticket page's click-to-cycle status control settles on (ticket 159, requirements 161/162/171/172/173). `{id}` is the task id and must match `\d+`; a non-numeric id does not match the route and produces `404 Not Found` the same as any other unmatched route. A `GET` (or any other method) against this path produces `405 Method Not Allowed` with an `Allow: POST` header.

The cycling itself — pending → active → blocked → done → pending, with any off-cycle status stepping to pending — happens entirely in the browser, with no request per click. This route is called once, after the user stops clicking, with the value the display settled on.

#### Behaviour

The controller reads the task via `TaskService::show({id})` to resolve its `phaseId`, then the phase via `PhaseService::show(phaseId)` to resolve its `ticketId` — the same lookup-chain pattern `TaskEditController` uses (§2.6). It then shells the status change out to `bin/tm task:set --status` through `TmCliRunner`, the same thin-wrapper pattern every other action route uses (§1.1). No `ai-lib` write ever happens on this route. After the CLI call, regardless of outcome, the controller re-reads the task, its phase, and its phase's ticket via `ai-lib`'s read services, so the response reflects any `autoStatus` rollup `bin/tm`'s own `RollupService` performed (requirement 173) — a task status change can change its phase's and, in turn, its ticket's derived status.

#### Request body

Standard HTML form POST (`application/x-www-form-urlencoded`), no JSON. One field:

| Field | Maps to `task:set` option | Meaning |
|---|---|---|
| `status` | `--status` | The status the display settled on (one of `pending`, `active`, `blocked`, `done`) |

#### Response

Unlike every other route in this document, the response body is JSON — see §1.2's amendment.

- **Success** (`bin/tm` exits `0`): `200 OK`, `application/json`. Body: `{"task": {"id": <id>, "status": "<status>"}, "phase": {"id": <id>, "status": "<status>"}, "ticket": {"id": <id>, "status": "<status>"}}`, reflecting the values stored after the re-read (including any rollup). The frontend applies these three statuses everywhere they render on the page.
- **Task not found, or its phase/ticket cannot be resolved** (`{id}` does not resolve to a task, or the task's phase or the phase's ticket does not resolve): `404 Not Found`, `text/plain; charset=utf-8` body — not JSON, matching every other route's not-found shape.
- **`bin/tm` failure** (non-zero exit, `task:set --status` rejects the change): `409 Conflict`, `application/json`. Body: the same `task`/`phase`/`ticket` shape as the success case, holding the currently stored statuses (unchanged, since the write did not happen), plus an `error` member carrying the parsed message from `bin/tm`'s JSON error envelope. The frontend uses this to revert its optimistic local display to the server-confirmed values and shows the message in the shared `#terminal-error` dialog (requirement 171).

### 2.8 `POST /phase/{id}/status`

Applies the final value the ticket page's click-to-cycle status control settles on for a phase (ticket 159, requirements 161/162/171/172/173). Same purpose, method rules, and request-body shape as §2.7 one level up, resolving phase → ticket the way `PhaseEditController` does (§2.5). The cycle for a phase is pending → active → done → pending (no `blocked` state), with any off-cycle status stepping to pending.

#### Behaviour

The controller reads the phase via `PhaseService::show({id})` to resolve its `ticketId`, then shells the status change out to `bin/tm phase:set --status` through `TmCliRunner`. No `ai-lib` write ever happens on this route. After the CLI call, regardless of outcome, the controller re-reads the phase and its ticket, so the response reflects any `autoStatus` rollup (a phase status change can change its ticket's derived status).

#### Request body

Standard HTML form POST (`application/x-www-form-urlencoded`), no JSON. One field:

| Field | Maps to `phase:set` option | Meaning |
|---|---|---|
| `status` | `--status` | The status the display settled on (one of `pending`, `active`, `done`) |

#### Response

- **Success** (`bin/tm` exits `0`): `200 OK`, `application/json`. Body: `{"phase": {"id": <id>, "status": "<status>"}, "ticket": {"id": <id>, "status": "<status>"}}`. There is no `task` member — a phase has no task to report.
- **Phase not found, or its ticket cannot be resolved**: `404 Not Found`, `text/plain; charset=utf-8` body.
- **`bin/tm` failure** (non-zero exit, `phase:set --status` rejects the change): `409 Conflict`, `application/json`. Body: the same `phase`/`ticket` shape as the success case, holding the currently stored statuses, plus an `error` member carrying the parsed message from `bin/tm`'s JSON error envelope.

### 2.9 `POST /project/{id}/ticket/create`

Creates a ticket from the project page's create-ticket popup (ticket 160, requirements 156/157/158/159, and the create half of 170). `{id}` is the project id and must match `\d+`; a non-numeric id does not match the route and produces `404 Not Found` the same as any other unmatched route. A `GET` (or any other method) against this path produces `405 Method Not Allowed` with an `Allow: POST` header.

#### Behaviour

The controller reads the project via `ProjectService::show({id})` first, to 404 on an unknown project before shelling out at all. It then shells out to `bin/tm ticket:add` through `TmCliRunner`, the same thin-wrapper pattern `TicketEditController` uses for `ticket:set` (§2.4). On success, it reads the new ticket's `id` from `bin/tm`'s JSON envelope (`ai-lib`'s `Serializer` emits snake_case keys, not camelCase). No `ai-lib` write ever happens on this route.

Ticket 269 removed the follow-on step this route used to perform after a successful create: shelling out to `ai-tmux open` to start an AI terminal session for the new ticket, seeded with a wrapper prompt. That integration was private to the owner's machine and could not be depended on by a public dashboard — see `docs/decisions.md` for tickets 137/160's original design and `AGENTS.md` for the current guardrails.

#### Request body

Standard HTML form POST (`application/x-www-form-urlencoded`), no JSON.

| Field | Maps to `ticket:add` option | Meaning |
|---|---|---|
| `title` | `--name` | New ticket's name |
| `description` | `--description` | New ticket's description |
| `template` | `--template` | Template to create the ticket from |

A field missing from the POST body is sent to `ticket:add` as an empty string rather than being omitted — unlike §2.4–2.6's edit routes, there is no existing stored value to leave unchanged; `--name` is empty by default here, exactly as the create-ticket form itself does not enforce a value client-side (requirement 155).

#### Response

- **Success**: `302 Found`, `Location: /?project={id}&ticket={newTicketId}`. No body.
- **Unknown project** (`{id}` does not resolve to a project): `404 Not Found`, `text/plain; charset=utf-8` body.
- **`bin/tm ticket:add` failure** (non-zero exit, for example a missing or unknown `--template`): `200 OK`, `text/html; charset=utf-8`. The project page (`ticket_list.html.twig`, the same rendering path `GET /?project={id}` uses) re-renders with the create-ticket popup already open, the parsed `error.message` from `bin/tm`'s JSON envelope displayed inside it (never the raw JSON), and the just-submitted `title`/`description`/`template` values preserved in the form inputs (requirement 170).

### 2.10 `POST /ticket/{id}/open-ide`

Opens the ticket's workspace directory in a configured IDE (ticket 164, requirements 201/202/204). `{id}` is the ticket id and must match `\d+`; a non-numeric id does not match the route and produces `404 Not Found` the same as any other unmatched route. A `GET` (or any other method) against this path produces `405 Method Not Allowed` with an `Allow: POST` header.

This route only exists when an IDE command is configured. `public/index.php` reads `~/.ai-dashboard/config.toml` fresh on every request via `DashboardConfig::readIdeCommand()` (see `../architecture/architecture.md` §7) and passes the result into `Application`, whose `buildKernel()` registers this route only when that read yields a non-null `ide_command`; with no command configured, the route is absent from the router entirely, and a `POST` to this path 404s the same as any other unmatched route, not a 500. The ticket page's `.ticket-ide-btn` button (`../ui/design-system.md` §9) is rendered only in that same case, so the two stay in lockstep.

#### Behaviour

The controller reads the ticket via `TicketService::show({id})` for its `projectId`, then reads the ticket's project via `ProjectService::show()` for the project's `path` (the project's stable root directory — never the per-ticket worktree). It resolves the workspace directory by ticket number through `TicketWorkspaceResolver::resolve()` (`src/Http/TicketWorkspaceResolver.php`): glob `{project path}/tickets/ticket-{id}-*`, use the one match when exactly one directory matches, and fall back to the project root when zero or more than one directory matches. No path is read from or written to `ai-lib` for this — the ticket carries no stored workspace path. It then shells out to the configured `ide_command` with that directory as its only argument, launched detached, through a thin wrapper class (`IdeOpener`) kept in the `Http` layer. This is a read from `ai-lib` followed by an external-command side effect; no `ai-lib` write ever happens on this route.

There is no request body. The route ignores query parameters.

#### Response

- **Success** (the configured command resolves via `command -v`): `204 No Content`, empty body. Deliberately silent — the observable feedback is the IDE window coming to the front, not anything in the page.
- **Ticket or project not found** (`{id}` does not resolve to a ticket, or the ticket's project does not resolve): `404 Not Found`, `text/plain; charset=utf-8` body.
- **IDE command not found** (`command -v` on the configured `ide_command` exits non-zero): `500 Internal Server Error`, `text/plain; charset=utf-8` body naming the command that did not resolve. The frontend reads this body as text and shows it in the shared `#terminal-error` dialog (`../ui/information-architecture.md` §1.3.1).

## 3. URL examples

For reference and copy-paste.

| URL | State |
|---|---|
| `http://127.0.0.1:8766/` | Project list. |
| `http://127.0.0.1:8766/?project=4` | Ticket list for the project whose id is 4, done tickets hidden. |
| `http://127.0.0.1:8766/?project=4&show_done=1` | Ticket list for project 4, done tickets included. |
| `http://127.0.0.1:8766/?project=4&ticket=42` | Deep view of ticket 42 in project 4. |
| `http://127.0.0.1:8766/static/style.css` | The stylesheet. |

## 4. Stability commitment

Routes, query parameter names, and the state-resolution rules in §2.1 are stable. Adding a new optional query parameter is allowed; renaming or removing one is a breaking change.

The HTML structure (semantic element choices, class names that the stylesheet relies on) is also part of the contract. Changing a class name requires updating the stylesheet in the same commit. See `../ui/design-system.md` for the class-name catalogue.

The JSON response shape of §2.7 and §2.8 is part of the contract on the same terms: the `task`/`phase`/`ticket` member names, their `id`/`status` shape, the `error` member on a `409`, and the `200`/`404`/`409` status codes are stable. Adding a new member to the JSON body is allowed; renaming or removing one is a breaking change. These two routes remain the only JSON endpoints — see §1.2.
