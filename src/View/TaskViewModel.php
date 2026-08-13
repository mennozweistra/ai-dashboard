<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

/**
 * `phaseId`, `order`, `archivedAt` were added under ticket 153 task 1270 so
 * the panel shows every column on the task's own database row (requirement
 * 137), matching how `PhaseRowViewModel` was extended under task 1269.
 *
 * `model` mirrors `TaskDeepOut::$model` (ticket 174, requirement 281): the
 * model id chosen for this task's step, or `null` when unset. It has no
 * "required at creation" semantic here — `buildTask()` always supplies it
 * explicitly on every render — so it defaults to `null` like the other
 * trailing optional properties below, unlike `ai-lib`'s `TaskIn` where the
 * same field is a required, no-default constructor parameter.
 *
 * `editOpen`/`editError`/`editName`/`editDescription`/`editAiDescription`/
 * `editActor`/`editMaxAttempts` carry the task panel's edit-form state for a
 * single render, mirroring `PhaseRowViewModel`'s equivalents. On a normal
 * page load `editOpen` is `false` and the `edit*` fields mirror the task's
 * own stored values. After a failed `POST /task/{id}/edit`,
 * `TaskEditController` re-renders through `TicketDeepController::render()`;
 * only the task that was submitted gets `editOpen: true`, `editError` set to
 * the parsed `bin/tm` error message, and the `edit*` fields set to the
 * just-submitted values so the user does not lose typed text. `editActor`
 * and `editMaxAttempts` stay strings (not `string`/`int` typed to match the
 * domain) for the same reason the other `edit*` fields are strings: they
 * hold raw, possibly-invalid submitted form values, not validated domain
 * values.
 */
final readonly class TaskViewModel
{
    /**
     * @param list<TaskTransitionViewModel> $transitions
     * @param list<LogEntryViewModel> $logs
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public string $actor,
        public string $description,
        public bool $hasDesc,
        public string $result,
        public string $aiResult,
        public bool $hasSummary,
        public bool $hasExtras,
        public string $aiDescription,
        public string $createdAt,
        public string $updatedAt,
        public array $transitions,
        public array $logs,
        public int $phaseId,
        public int $order,
        public string $archivedAt,
        public int $maxAttempts = 0,
        public int $attempts = 0,
        public ?string $model = null,
        public bool $editOpen = false,
        public ?string $editError = null,
        public string $editName = '',
        public string $editDescription = '',
        public string $editAiDescription = '',
        public string $editActor = '',
        public string $editMaxAttempts = '',
    ) {}
}
