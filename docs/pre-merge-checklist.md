# `ai-dashboard` — Pre-merge Checklist

Run every item below before merging a change into the main branch.
The checklist enforces the architectural rules in `docs/architecture/architecture.md`.
All commands are run from the `ai-dashboard` project root.

`ai-dashboard` is a read adapter on `ai-lib`: it owns no entities and no persistence, and
no controller mutates `ai-lib` state directly or indirectly. The one narrow exception is
a `POST` action-route controller that reads from `ai-lib` and then shells out to an
external command through a thin wrapper class kept inside the `Http` layer —
`TmCliRunner` (ticket 153) and `IdeOpener` (ticket 164). Ticket 269 removed the third
wrapper this exception used to cover, `AiTmuxOpener` (ticket 137), along with
`ai-dashboard`'s entire dependency on `ai-tmux`. That invariant, the `Kernel → Http →
View` layer direction, and the HTML-class-name contract are the rules this checklist
verifies.

---

Automated tool checks — the test suite, static analysis, code style, architecture and
dependency-boundary rules, and automated refactor suggestions — each run as their own
dedicated QA task in the ticket's phase. This file covers only what a tool cannot check
by itself.

## Manual checks

These checks require reading code or running a targeted search. They catch violations
that automated tools do not yet cover.

### `Kernel → Http → View` layer direction, and `View` depends only on `ai-lib` DTOs

Deptrac enforces this (architecture.md §2), but it is worth a manual grep the same way
`ai-lib`'s checklist double-checks its own Deptrac rules, because Deptrac catches import
statements, not incidental references such as PHPDoc strings or a stray `new` call typed
via a fully-qualified name.

```bash
grep -rln "AiToolset\\\\AiDashboard\\\\Http" src/View/ 2>/dev/null
grep -rln "AiToolset\\\\AiDashboard\\\\Kernel" src/Http/ src/View/ 2>/dev/null
grep -rln "AiToolset\\\\AiLib\\\\Domain\\\\Models" src/View/ 2>/dev/null
```

Expected: no output from any of the three commands. `View` must reference `ai-lib`'s
`Schemas` DTOs only, never `ai-lib`'s Domain models and never anything from `Http` or
`Kernel`; `Http` must never reference `Kernel`.

### No controller mutates `ai-lib` state directly

`ai-lib` write methods only ever run through `TmCliRunner`/`IdeOpener` shelling out to
an external command (architecture.md §5, http.md §1.1). Confirm no controller calls a
write-shaped method on an `ai-lib` service directly:

```bash
grep -rn "->set(\|->add(\|->delete(" src/Http/ --include="*.php"
```

Expected: matches limited to framework calls that are not `ai-lib` writes — currently the
only matches are `$response->headers->set(...)` calls on Symfony's `HeaderBag`. Any match
that calls a method named `set`/`add`/`delete` on an injected `ai-lib` service instance
(`TicketService`, `PhaseService`, `TaskService`, `ProjectService`, `LogService`) inside a
controller is a hard block — the real write must go through `bin/tm` via `TmCliRunner`
instead. Test fixtures are not in scope for this grep (it targets `src/Http/` only).

### Mutation-adjacent controller tests use a fake stub binary, not real `ai-lib` services

Every action-route controller test that exercises a save/mutation path (currently
`TicketEditControllerTest`, `PhaseEditControllerTest`, `TaskEditControllerTest`,
`TicketCreateControllerTest`, `TicketIdeControllerTest`, plus `TmCliRunnerTest`/
`IdeOpenerTest` themselves) defines a `STUB` constant pointing at a fake stub executable
under `tests/Http/fixtures/` rather than a real `bin/tm` or IDE process (architecture.md
§9's testing-category split; precedent: `tests/Http/fixtures/fake-tm` and
`tests/Http/fixtures/fake-ide`). Ticket 269 removed `TicketTerminalControllerTest` and
`AiTmuxOpenerTest` along with the feature they tested; `tests/Http/fixtures/fake-ai-tmux`
was removed in the same change.

```bash
grep -n "STUB\s*=" tests/Http/*.php
grep -Ln "STUB" tests/Http/*EditControllerTest.php tests/Http/TicketCreateControllerTest.php tests/Http/TicketIdeControllerTest.php 2>/dev/null
```

Expected: the first command's output shows every `STUB` constant assigned to a path under
`fixtures/fake-tm` or `fixtures/fake-ide`, never a real project `bin/` path; the second
command (any `*EditControllerTest.php` file, plus `TicketCreateControllerTest.php` and
`TicketIdeControllerTest.php`, with no `STUB` constant at all) produces no output. A new
or changed action-route controller test that fails either check is a real-process test
and must be fixed before merging, and a new wrapper class following this pattern gets its
own test file added to the second command's file list in the same commit.

### Every changed or added CSS class name is catalogued in the design system

The HTML class names are part of the contract (`AGENTS.md`, architecture.md §6.3);
`docs/ui/design-system.md` §9 is the canonical list. For a class name newly introduced or
renamed in a template, confirm it appears in the §9 table:

```bash
grep -n "class=\"" templates/**/*.html.twig 2>/dev/null
grep -n "<class-name>" docs/ui/design-system.md
```

Expected: run the second command once per new or changed class name (substitute the
actual name for `<class-name>`) and confirm at least one match inside the §9 table. If a
template uses a class absent from §9, either the class is a mistake or §9 is out of date
— update `design-system.md` §9 in the same commit as the template change.

### No route renamed or removed

`http.md` §4 commits to route stability: adding a query parameter or a new route is
allowed, renaming or removing an existing route or query parameter is a breaking change.
Compare the route table against the current routing file:

```bash
grep -n "Route::\|->add(" src/Kernel/*.php 2>/dev/null
```

Expected: every route listed in `docs/api/http.md` §2 still resolves to the same method
and path pattern it documents. A new route is fine as long as `http.md` gains a matching
section in the same commit. If a route path, HTTP method, or query-parameter name in the
routing file no longer matches what `http.md` documents, that is a hard block — either
the code change is wrong or `http.md` was not updated in the same commit.

### JSON responses exist only on the two status routes

`http.md` §1.2 states that `POST /task/{id}/status` and `POST /phase/{id}/status` (ticket
159) are the sole, deliberate exception to "no JSON endpoints" — they need to return the
rollup-changed parent statuses in one response for the click-to-cycle control. Every other
route stays HTML or plain text. Confirm no other controller introduces a `JsonResponse`:

```bash
grep -rln "JsonResponse" src/Http/ --include="*.php"
```

Expected: the only matches are `TaskStatusController.php` and `PhaseStatusController.php`.
A `JsonResponse` in any other controller is a hard block — either the route is wrong or
`http.md` §1.2 needs a new documented exception, which is itself a stop-and-ask per
`AGENTS.md`, not something to add silently.

---

### `composer.json`'s `ai-toolset/ai-lib` and `ai-toolset/tm` dependencies are in the committed (versioned) state, not the development (path) state

`AGENTS.md` ("Composer dependencies on ai-toolset/ai-lib and ai-toolset/tm") documents
two states: path repositories pointing at `../ai-lib` and `../ai-tm` with `@dev`
constraints while a ticket is in progress, and VCS repositories with `dev-main`
constraints in what ships. A ticket branch about to merge to `main` must carry the
versioned state for both — the path state only resolves on a machine with the sibling
checkouts present.

```bash
grep -n '"type": "path"' composer.json
grep -n '"type": "vcs"' composer.json
grep -n '"ai-toolset/ai-lib"\|"ai-toolset/tm"' composer.json
```

Expected: no `"type": "path"` entry; two `"type": "vcs"` entries
(`https://github.com/mennozweistra/ai-lib` and `https://github.com/mennozweistra/ai-tm`);
both `ai-toolset/ai-lib` and `ai-toolset/tm` require constraints read `dev-main`, not
`@dev`.

### The router self-migrates before it opens the store

`public/index.php` calls `ai-lib`'s `SchemaMigrator` before it constructs the `PDO`
(architecture §7, toolset architecture §4.7). The order matters: on a first run the store
and its directory do not exist yet, and `new PDO` would fail. The dashboard configures no
migration machinery of its own.

```bash
grep -n "SchemaMigrator\|new PDO" public/index.php
grep -rn "Phinx" src/ public/ --include="*.php"
```

Expected: the `SchemaMigrator` line appears **before** the `new PDO` line, and the second
command produces no output.

Confirm end to end against a scratch database — the router must answer on a store that does
not exist yet:

```bash
TM_DB=$(mktemp -d)/store.db php -S 127.0.0.1:8799 public/index.php &
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8799/
```

Expected: `200`, and the database file exists afterwards with a populated `phinxlog`.

## Sign-off

Mark each item before merging:

- [ ] `Kernel → Http → View` layer direction holds; `View` references only `ai-lib` DTOs, never `ai-lib` Domain models, `Http`, or `Kernel`
- [ ] No controller calls a write method (`->set(`, `->add(`, `->delete(`) on an `ai-lib` service directly — real writes go through `TmCliRunner`/`IdeOpener` only
- [ ] Every mutation-adjacent controller test uses a `fixtures/fake-tm` or `fixtures/fake-ide` stub binary, not a real `ai-lib`-backed process
- [ ] Every new or changed CSS class name used by a template is present in `docs/ui/design-system.md` §9's catalogue
- [ ] No route was renamed or removed — only additions, per `docs/api/http.md` §4
- [ ] No controller other than `TaskStatusController`/`PhaseStatusController` returns a `JsonResponse` — those two remain the sole JSON endpoints, per `docs/api/http.md` §1.2
- [ ] `public/index.php` calls `SchemaMigrator` before constructing the `PDO`, and the router answers 200 against a `TM_DB` path that does not exist yet
- [ ] `composer.json`'s `ai-toolset/ai-lib` and `ai-toolset/tm` dependencies are in the committed (versioned) state: two `"type": "vcs"` repository entries, `dev-main` constraints, no `"type": "path"` entry
