<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

/**
 * `editOpen`/`editError`/`editName`/`editDescription`/`editAiDescription`
 * carry the ticket-panel edit-form state for a single render (ticket 153
 * task 1268). On a normal page load `editOpen` is `false` and the three
 * `edit*` fields mirror the header's stored values, so opening the panel's
 * edit mode by hand always starts from the current data. After a failed
 * `POST /ticket/{id}/edit`, `TicketEditController` re-renders through
 * `TicketDeepController::render()` with `editOpen: true`, `editError` set to
 * the parsed `bin/tm` error message, and the `edit*` fields set to the
 * just-submitted values so the user does not lose typed text.
 *
 * `phaseEditId` (ticket 153 task 1269) is the phase-level equivalent of
 * `editOpen`, but since a ticket has several phases the flag has to name
 * which one — the id of the phase whose panel must auto-open in edit mode,
 * or `null` on a normal render. The corresponding `PhaseRowViewModel` for
 * that id carries its own `editOpen`/`editError`/`edit*` fields; every other
 * phase's `editOpen` stays `false`. The template reads `phaseEditId` only to
 * decide whether `#phase-panel` needs `data-open-on-load` and which
 * `<template id="phase-data-{id}">` to clone into it on page load.
 *
 * `taskEditId` (ticket 153 task 1270) is the task-level equivalent of
 * `phaseEditId`, for the same reason: several tasks exist per ticket, so the
 * flag has to name which one — the id of the task whose panel must
 * auto-open in edit mode, or `null` on a normal render. The corresponding
 * `TaskViewModel` for that id carries its own `editOpen`/`editError`/
 * `edit*` fields; every other task's `editOpen` stays `false`. The template
 * reads `taskEditId` to decide whether `#task-panel` needs
 * `data-open-on-load`/`data-open-task-id` and which
 * `<template id="task-data-{id}">` to clone into it on page load — the same
 * mechanism `#phase-panel` uses, layered on top of `#task-panel`'s existing
 * `?task=<id>` URL-based auto-open used for plain read-only opens.
 *
 * `ideConfigured` (ticket 164, requirement 202) is `true` when
 * `~/.ai-dashboard/config.toml` carries a usable `ide_command`, `false`
 * otherwise. The title-row "i" button (added by the follow-up round-buttons
 * task) renders only when this is `true`.
 */
final readonly class TicketDeepViewModel
{
    /**
     * @param array<RequirementViewModel> $requirements
     * @param array<QuestionViewModel> $questions
     * @param array<PhaseRowViewModel> $phases
     * @param list<LogEntryViewModel> $logs
     */
    public function __construct(
        public int $projectId,
        public string $projectName,
        public TicketHeaderViewModel $header,
        public array $requirements,
        public array $questions,
        public array $phases,
        public array $logs,
        public bool $editOpen,
        public ?string $editError,
        public string $editName,
        public string $editDescription,
        public string $editAiDescription,
        public ?int $phaseEditId = null,
        public ?int $taskEditId = null,
        public bool $ideConfigured = false,
    ) {}
}
