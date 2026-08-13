# AGENTS.md

This is `ai-dashboard`, the web UI for the toolset. This file is the implementation playbook — read it on every turn during the build.

## Documents to read before starting

1. **Toolset-wide architecture** at `../ai-toolset-docs/docs/architecture/architecture.md` (sibling repo). Conventions across all four toolset projects.
2. **`ai-dashboard`'s architecture** at `docs/architecture/architecture.md`. Project-specific structure and rules.
3. **`ai-dashboard`'s spec** at `spec.md`. Scope and decisions.
4. **`ai-dashboard`'s implementation plan** at `docs/plan.md`. The build sequence with phases, tests, and dogfood steps.
5. **HTTP surface** at `docs/api/http.md`. Routes, query parameters, error rendering.
6. **Information architecture** at `docs/ui/information-architecture.md`. Page inventory and navigation.
7. **Design system** at `docs/ui/design-system.md`. Tokens, marker shapes, status semantics, class-name catalogue.
8. **`ai-lib`'s service surface** at `../ai-lib/docs/architecture/architecture.md` and the service classes themselves. The dashboard reads from `ai-lib`; you need to know what `ai-lib` exposes.

## Architectural guardrails

- The three-layer model (`Kernel → Http → View`) is enforced by Deptrac. Layer-boundary violations are wrong, not stylistic.
- View-builders accept `ai-lib` DTOs and produce dashboard view-models. Templates receive view-models, not `ai-lib` DTOs.
- Templates are dumb. Loops, conditional rendering, and registered filters only. Anything more complicated lives in PHP.
- No controller mutates `ai-lib` state, directly or indirectly. That invariant does not change. A narrow exception exists for action routes that trigger a side effect by shelling out to an external CLI through a thin wrapper class kept inside `ai-dashboard`'s `Http` layer — three instances exist so far: the ticket/phase/task edit-save routes added under ticket 153 (`TmCliRunner`, shells out to `bin/tm`'s `*:set` commands); the create-ticket-from-project-page route added under ticket 160 (`TicketCreateController`, uses `TmCliRunner` for `bin/tm ticket:add`); and the `POST /ticket/{id}/open-ide` route added under ticket 164 (`TicketIdeController`, uses `IdeOpener` to shell out to the machine-local `ide_command` read from `~/.ai-dashboard/config.toml`). Ticket 269 removed the fourth instance, the terminal-jump route added under ticket 137 (`AiTmuxOpener`, shelled out to `ai-tmux`), along with `ai-dashboard`'s entire dependency on `ai-tmux`: that integration was private to the owner's machine and could not be depended on by a public dashboard. Such a route still never calls an `ai-lib` operation that writes; it only reads from `ai-lib` and then invokes the external CLI. If a phase appears to need a controller that mutates `ai-lib` state directly, stop and surface — that is still not in v1.
- The HTTP routes are the contract. Adding a query parameter is allowed; renaming or removing one is a breaking change.
- The HTML class names and `data-status` attributes are part of the contract; the stylesheet relies on them. Changing a class name requires updating the stylesheet in the same commit.

## Composer dependencies on ai-toolset/ai-lib and ai-toolset/tm: development state vs committed state

`ai-dashboard` depends on both `ai-toolset/ai-lib` and `ai-toolset/tm`. `composer.json` carries exactly one of two states for these two dependencies at any time — the `repositories` entries and the `require` constraints for both packages always move together, never a mix of the two states.

**Development state — while a ticket is in progress.** Path repository entries point at the sibling checkouts, with `@dev` constraints. Composer symlinks `vendor/ai-toolset/ai-lib` and `vendor/ai-toolset/tm` to the sibling directories, so a change made to either in the same ticket is visible without a `composer update`.

```json
{
    "require": {
        "ai-toolset/ai-lib": "@dev",
        "ai-toolset/tm": "@dev"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../ai-lib"
        },
        {
            "type": "path",
            "url": "../ai-tm"
        }
    ]
}
```

**Committed state — what ships.** VCS repository entries name the public git URLs; `require` tracks `dev-main` for both. No tags, no Packagist: an update always pulls the latest `main`.

```json
{
    "require": {
        "ai-toolset/ai-lib": "dev-main",
        "ai-toolset/tm": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mennozweistra/ai-lib"
        },
        {
            "type": "vcs",
            "url": "https://github.com/mennozweistra/ai-tm"
        }
    ]
}
```

**The working rule.** While a ticket is in progress, `composer.json` in that ticket's worktree stays in the development (path) state — `composer install` must resolve the sibling worktrees, because the remote `main` branches do not yet carry the ticket's changes. The state that ships — the state committed on the branch that gets merged to `main` — is the versioned state. The swap in either direction is mechanical: replace both `repositories` entries and both `require` constraints with the block shown above. The Release phase performs the swap to the versioned state before merge; the QA phase's pre-merge checklist (`docs/pre-merge-checklist.md`) is what verifies it landed. Do not swap mid-ticket — every intermediate commit on a ticket branch keeps the path state.

**Note for install documentation.** Composer honours a `repositories` entry only from the *root* package; a dependency's own `repositories` block is ignored by whatever depends on it. Neither state above ever reaches someone who installs the dashboard as a package — it only takes effect while `ai-dashboard`'s own `composer.json` is the root package, i.e. during `ai-dashboard` development. A user installing the dashboard declares the VCS repositories in their own global (or project) `composer.json`, plus a dev stability flag, for the `dev-main` constraints to resolve. That is the install documentation's job (requirement 685), not this section's.

## Scope rule (v1)

Four scope decisions govern v1, all documented in `spec.md`:

- **Transcripts are out of scope.** No `/task/{id}/transcript` route, no transcript link in the task actions row.
- **Live refresh is out of scope.** No polling, no SSE, no `<meta refresh>`. The user reloads when ready.
- **Notes are out of scope.** `tm` has no notes feature; v1 of the dashboard does not render a notes section.
- **URLs use integer ids.** `?project=<id>` and `?ticket=<id>`. Reasons in `spec.md` §10.

V1 itself shipped and has since been extended with deliberate, explicitly-authorized features: the terminal-jump action (ticket 137), the ticket/phase/task edit-in-place routes (ticket 153), create-ticket-from-project-page — the always-visible "+" button, create-ticket popup, and `POST /project/{id}/ticket/create` route that also starts an `ai-tmux` session for the new ticket (ticket 160) — and the sticky two-row header band with the round terminal/IDE buttons on the ticket page and the machine-local, read-only `~/.ai-dashboard/config.toml` that turns the IDE button on (ticket 164). These are additions beyond the v1 baseline, approved individually per ticket; they are not violations of the scope rule above, which governs the initial v1 build. Do not read their existence as license to add further features "while you are here" — each one required its own design discussion.

## Build discipline

**`ai-lib` prerequisites first.** Before Phase 0 of the dashboard build starts, three changes must land in the sibling `ai-toolset/ai-lib` repository, in order: (A) add `\AiToolset\AiLib\Testing\InMemoryDatabase` and remove the hand-written `CREATE TABLE` block from `ai-lib`'s `BaseServiceTest`; (B) add the `outcome` column on the `tasks` table; (C) widen `TicketDeepOut` so `phases` carries `PhaseDeepOut` and each phase carries full `TaskDeepOut` instances. Each is a separate `ai-lib` commit with `composer ci` green. Full text in `docs/plan.md` §"`ai-lib` prerequisites".

**Follow `docs/plan.md` in order.** Phases 0 through 7. Each phase has a goal, the tests to write, the implementation tasks, and a manual dogfood step. Do not skip phases or reorder them.

**TDD for every controller and view-builder.** Red → green → refactor. Write the failing test first. Run it and watch it fail. Implement the smallest thing that turns it green. Refactor with tests passing.

**No mocks. No exceptions.** All tests run against `ai-lib`'s real services backed by in-memory SQLite. No Mockery, no `createMock()`, no `createStub()`. Seed test data via `ai-lib`'s services (the same way you would in `ai-lib`'s own test suite).

**Test naming.** Methods start with `it_`. Marked with PHPUnit's `#[Test]` attribute. Tests live under `tests/Http/` (controller tests), `tests/View/` (view-builder tests), or `tests/Kernel/` (Kernel-layer unit tests with no `ai-lib` database dependency, added under ticket 164 — see `docs/architecture/architecture.md` §9).

**Useful tests only.** Tests must catch a real bug. No tests for getters, framework plumbing, static constants, or coverage padding. The full guidance is in your global `CLAUDE.md` ("Useful tests only — no test padding").

## Quality gates

`composer ci` must pass before any commit. It runs:

- `composer test` — PHPUnit, all tests green.
- `composer stan` — PHPStan at level `max`, zero violations, no baseline.
- `composer fix --dry-run` — PHP CS Fixer (PER 2.0), zero unfixed violations.
- `composer deptrac` — layer-boundary enforcement, zero violations. A green result also means every file under `deptrac.yaml`'s scanned paths was actually parsed — the script fails on an unparseable file instead of silently excluding it (ticket 167; see `../ai-tm/docs/decisions.md`, 2026-07-10).
- `composer rector --dry-run` — zero suggested upgrades.

If a gate fails, fix the underlying problem. Do **not** add a PHPStan baseline. Do **not** suppress Deptrac rules. Do **not** disable Rector rules. If you genuinely cannot satisfy a rule, stop and surface the issue per "When to stop and ask" below.

## Commit policy

**You have upfront permission to commit at the end of each completed phase.** A phase is complete when:

1. All tests for the phase exist and pass.
2. `composer ci` is fully green.
3. The dogfood step for the phase has been performed and produced the expected result.

Commit message format:

```
Phase N: <phase title>

<one short paragraph: what was built, what was tested, anything notable>
```

One commit per phase. Do not amend across phases. Do not push — the user pushes manually.

You do **not** have permission to commit mid-phase. If you need an interim checkpoint for safety, finish to a green-test state and commit only at the phase boundary.

You do **not** have permission for any other git operation that rewrites history (rebase, reset --hard, force-push, branch deletion). If something goes wrong, stop and surface the issue.

## Recording small judgment calls

Small decisions you make during implementation that the architecture did not anticipate go in `docs/decisions.md`, appended to the end. Format:

```markdown
## YYYY-MM-DD — Phase N — <short title>

**Decision:** <what you chose>

**Reason:** <why; one paragraph>

**Where:** <file:line or file path>
```

Examples of "small": where to put a private helper, naming a view-model field, picking between two equivalent CSS approaches that look the same. Examples of "not small" (these are stop-and-ask): a route URL change, a view-model public-property rename across templates, adding a new layer to the Deptrac configuration, anything that breaks the scope rule.

## Visual discipline

The dogfood step at every phase boundary opens the dashboard and checks it by eye against `docs/ui/design-system.md`. Do not introduce visual changes that the design system does not describe. If a CSS decision needs a workaround, record it in `decisions.md` and confirm the rendered output still matches.

## Chat output cadence

Default: silent. Do the work, commit per phase, continue. Do not write progress narration mid-phase. Do not ask the user for acknowledgement.

At each phase boundary, write one short message to chat so the user can glance at the terminal and see progress:

```
Phase N complete. Commit <short-sha>. composer ci green.
Dogfood: <one-line outcome>.
Starting Phase N+1.
```

The dogfood line should be the actual outcome (e.g. "ticket list confirmed: 3 projects, 14 tickets, status colours match, show-done toggle round-trips correctly"), not generic ("dogfood succeeded"). Keep the whole boundary message under five lines.

If a stop-and-ask case arises (see below), break this cadence and write the full message that case requires. Otherwise, the boundary line is the only chat output.

## When to stop and ask

Per your global `CLAUDE.md` collaboration model: small unanticipated decisions get recorded and you continue; large architectural decisions stop the work.

**Continue and record:**

- A naming choice (variable, view-model field, helper class, test method).
- Where to put a private helper.
- A minor refactor that arose from the work.
- A library quirk that has an obvious workaround.

**Stop and surface in chat:**

- A library does not behave as documented and there is no obvious workaround.
- PHPStan max cannot be satisfied without a baseline.
- A test reveals an architectural gap (something the architecture document did not anticipate).
- A route URL would need to change to fit a new requirement.
- An `ai-lib` service interface change is needed to make the dashboard work — this is an `ai-lib` decision, not a dashboard one.
- The dogfood step at the end of a phase shows a visual gap you cannot resolve with CSS or template changes.
- You realise an earlier phase has a bug that has been propagating.
- You are about to violate the scope rule (introduce a feature, redesign, refactor) "while you are here".

When stopping, write a clear chat message: what you tried, what failed, what the options are, and your lean. Do not write multi-page analyses; one screenful is enough.

## Plain English in user-facing text

The status-first output style (`~/.claude/output-styles/status-first.md`) governs chat replies fully, including its format (opening status line, detail only when needed). Commit messages, log entries, and task results are not chat replies, so that format does not apply to them — but they follow the same plain-English language rules from that file: short sentences, everyday words, no idioms, precise technical verbs. This repo adds no writing rules of its own. Code comments are unaffected: write minimal comments and only when the *why* is non-obvious.
