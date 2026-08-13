<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

/**
 * `editOpen`/`editError`/`editName`/`editDescription`/`editAiDescription`
 * carry the phase panel's edit-form state for a single render (ticket 153
 * task 1269), mirroring TicketDeepViewModel's ticket-level equivalents. On a
 * normal page load `editOpen` is `false` for every phase and the three
 * `edit*` fields mirror the phase's own stored values. After a failed
 * `POST /phase/{id}/edit`, `PhaseEditController` re-renders through
 * `TicketDeepController::render()`; only the phase that was submitted gets
 * `editOpen: true`, `editError` set to the parsed `bin/tm` error message, and
 * the `edit*` fields set to the just-submitted values so the user does not
 * lose typed text. Every other phase on the page keeps its normal values.
 *
 * `ticketId`, `order`, `createdAt`, `updatedAt`, `archivedAt` were added
 * under task 1269 so the panel can show every column on the phase's own
 * database row (requirement 137), not just the fields the phase list itself
 * needs.
 *
 * `maxAttempts`/`attempts` mirror `TaskViewModel`'s equivalents (ticket 179
 * task 2072, requirement 306): `PhaseDeepOut`'s own run-count columns, so the
 * phase row and panel can show the same "N/M runs" marker the task row
 * already has.
 */
final readonly class PhaseRowViewModel
{
    /** @param array<TaskViewModel> $tasks */
    public function __construct(
        public int $id,
        public int $ticketId,
        public string $name,
        public string $status,
        public bool $isOpen,
        public string $description = '',
        public string $aiDescription = '',
        public int $order = 0,
        public string $createdAt = '',
        public string $updatedAt = '',
        public string $archivedAt = '',
        public int $maxAttempts = 0,
        public int $attempts = 0,
        public array $tasks = [],
        public bool $editOpen = false,
        public ?string $editError = null,
        public string $editName = '',
        public string $editDescription = '',
        public string $editAiDescription = '',
    ) {}
}
