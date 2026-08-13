<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

use AiToolset\AiLib\Schemas\LogEntryOut;
use AiToolset\AiLib\Schemas\PhaseDeepOut;
use AiToolset\AiLib\Schemas\ProjectOut;
use AiToolset\AiLib\Schemas\QuestionDeepOut;
use AiToolset\AiLib\Schemas\RequirementDeepOut;
use AiToolset\AiLib\Schemas\StatusTransitionOut;
use AiToolset\AiLib\Schemas\TaskDeepOut;
use AiToolset\AiLib\Schemas\TicketDeepOut;

final readonly class TicketDeepViewBuilder
{
    /**
     * @param list<LogEntryOut> $logEntries
     * @param string|null $editName Just-submitted `name` value to preserve in the edit form
     *     after a failed save; falls back to the stored value when null.
     * @param string|null $editDescription Just-submitted `description` value; same fallback rule.
     * @param string|null $editAiDescription Just-submitted `ai_description` value; same fallback rule.
     * @param int|null $phaseEditId Id of the single phase whose panel must re-open in edit mode
     *     after a failed `POST /phase/{id}/edit` (ticket 153 task 1269); null on a normal render.
     * @param string|null $phaseEditError Parsed `bin/tm` error message for that phase's save failure.
     * @param string|null $phaseEditName Just-submitted `name` value for that phase; same fallback rule as $editName.
     * @param string|null $phaseEditDescription Just-submitted `description` value for that phase.
     * @param string|null $phaseEditAiDescription Just-submitted `ai_description` value for that phase.
     * @param int|null $taskEditId Id of the single task whose panel must re-open in edit mode
     *     after a failed `POST /task/{id}/edit` (ticket 153 task 1270); null on a normal render.
     * @param string|null $taskEditError Parsed `bin/tm` error message for that task's save failure.
     * @param string|null $taskEditName Just-submitted `name` value for that task; same fallback rule as $editName.
     * @param string|null $taskEditDescription Just-submitted `description` value for that task.
     * @param string|null $taskEditAiDescription Just-submitted `ai_description` value for that task.
     * @param string|null $taskEditActor Just-submitted `actor` value for that task.
     * @param string|null $taskEditMaxAttempts Just-submitted `max_attempts` value for that task, kept as a
     *     string since it is a raw, possibly-invalid form value, not a validated int.
     * @param bool $ideConfigured Whether `~/.ai-dashboard/config.toml` carries a usable `ide_command`
     *     (ticket 164, requirement 202). Set once per `Application` instance by `TicketDeepController`,
     *     not per request, since it reflects a machine-local config file, not request state.
     */
    public function build(
        ProjectOut $project,
        TicketDeepOut $deep,
        array $logEntries = [],
        bool $editOpen = false,
        ?string $editError = null,
        ?string $editName = null,
        ?string $editDescription = null,
        ?string $editAiDescription = null,
        ?int $phaseEditId = null,
        ?string $phaseEditError = null,
        ?string $phaseEditName = null,
        ?string $phaseEditDescription = null,
        ?string $phaseEditAiDescription = null,
        ?int $taskEditId = null,
        ?string $taskEditError = null,
        ?string $taskEditName = null,
        ?string $taskEditDescription = null,
        ?string $taskEditAiDescription = null,
        ?string $taskEditActor = null,
        ?string $taskEditMaxAttempts = null,
        bool $ideConfigured = false,
    ): TicketDeepViewModel {
        $name = $deep->name !== '' ? $deep->name : (string) $deep->id;

        $header = new TicketHeaderViewModel(
            id: $deep->id,
            projectId: $deep->projectId,
            name: $name,
            status: $deep->status,
            type: $deep->type,
            objective: $deep->description,
            aiDescription: $deep->aiDescription,
            createdAt: $deep->createdAt->format('Y-m-d H:i'),
            updatedAt: $deep->updatedAt->format('Y-m-d H:i'),
            archivedAt: $deep->archivedAt?->format('Y-m-d H:i') ?? '',
            sessionId: $deep->sessionId,
            priority: $deep->priority,
        );

        $requirements = $this->buildRequirements($deep->requirements);
        $questions = $this->buildQuestions($deep->questions);

        $phases = $this->buildPhases(
            $deep->phases,
            $phaseEditId,
            $phaseEditError,
            $phaseEditName,
            $phaseEditDescription,
            $phaseEditAiDescription,
            $taskEditId,
            $taskEditError,
            $taskEditName,
            $taskEditDescription,
            $taskEditAiDescription,
            $taskEditActor,
            $taskEditMaxAttempts,
        );

        $logs = array_map($this->buildLogEntry(...), $logEntries);

        return new TicketDeepViewModel(
            projectId: $project->id,
            projectName: $project->name,
            header: $header,
            requirements: $requirements,
            questions: $questions,
            phases: $phases,
            logs: $logs,
            editOpen: $editOpen,
            editError: $editError,
            editName: $editName ?? $deep->name,
            editDescription: $editDescription ?? $deep->description,
            editAiDescription: $editAiDescription ?? $deep->aiDescription,
            phaseEditId: $phaseEditId,
            taskEditId: $taskEditId,
            ideConfigured: $ideConfigured,
        );
    }

    private function buildLogEntry(LogEntryOut $entry): LogEntryViewModel
    {
        $level = $entry->taskId !== null
            ? 'task'
            : ($entry->phaseId !== null ? 'phase' : 'ticket');

        return new LogEntryViewModel(
            id: $entry->id,
            logType: $entry->logType,
            title: $entry->title,
            detail: $entry->aiContent,
            timestamp: $entry->timestamp->format('Y-m-d H:i'),
            level: $level,
            taskId: $entry->taskId,
        );
    }

    /**
     * @param array<RequirementDeepOut> $requirements
     * @return array<RequirementViewModel>
     */
    private function buildRequirements(array $requirements): array
    {
        $rows = [];

        foreach ($requirements as $requirement) {
            $rows[] = new RequirementViewModel(
                id: $requirement->id,
                name: $requirement->name,
                description: $requirement->description,
                aiDescription: $requirement->aiDescription,
                verification: $requirement->verification,
                order: $requirement->order,
            );
        }

        return $rows;
    }

    /**
     * @param array<QuestionDeepOut> $questions
     * @return array<QuestionViewModel>
     */
    private function buildQuestions(array $questions): array
    {
        return array_map($this->buildQuestion(...), $questions);
    }

    /**
     * Derives the display group the same way `QuestionService::isInGroup()`
     * does (requirement 386): `open` while the question is still open,
     * `resolved_unprocessed` once resolved but not yet followed up on, and
     * `done` once the follow-up work is marked processed.
     */
    private function buildQuestion(QuestionDeepOut $q): QuestionViewModel
    {
        [$group, $groupLabel] = match (true) {
            $q->state === 'open' => ['open', 'needs you'],
            !$q->processedAt instanceof \DateTimeImmutable => ['resolved_unprocessed', 'needs agent'],
            default => ['done', 'done'],
        };

        return new QuestionViewModel(
            id: $q->id,
            name: $q->name,
            kind: $q->kind,
            state: $q->state,
            group: $group,
            groupLabel: $groupLabel,
            question: $q->question,
            background: $q->background,
            recommendation: $q->recommendation,
            answer: $q->answer,
        );
    }

    /**
     * @param array<PhaseDeepOut> $phases
     * @return array<PhaseRowViewModel>
     */
    private function buildPhases(
        array $phases,
        ?int $phaseEditId = null,
        ?string $phaseEditError = null,
        ?string $phaseEditName = null,
        ?string $phaseEditDescription = null,
        ?string $phaseEditAiDescription = null,
        ?int $taskEditId = null,
        ?string $taskEditError = null,
        ?string $taskEditName = null,
        ?string $taskEditDescription = null,
        ?string $taskEditAiDescription = null,
        ?string $taskEditActor = null,
        ?string $taskEditMaxAttempts = null,
    ): array {
        $rows = [];

        foreach ($phases as $phase) {
            $tasks = array_map(
                fn(TaskDeepOut $t) => $this->buildTask(
                    $t,
                    $taskEditId,
                    $taskEditError,
                    $taskEditName,
                    $taskEditDescription,
                    $taskEditAiDescription,
                    $taskEditActor,
                    $taskEditMaxAttempts,
                ),
                $phase->tasks,
            );
            $isEditedPhase = $phaseEditId !== null && $phase->id === $phaseEditId;
            $rows[] = new PhaseRowViewModel(
                id: $phase->id,
                ticketId: $phase->ticketId,
                name: $phase->name,
                status: $phase->status,
                isOpen: false,
                description: $phase->description,
                aiDescription: $phase->aiDescription,
                order: $phase->order,
                createdAt: $phase->createdAt->format('Y-m-d H:i'),
                updatedAt: $phase->updatedAt->format('Y-m-d H:i'),
                archivedAt: $phase->archivedAt?->format('Y-m-d H:i') ?? '',
                maxAttempts: $phase->maxAttempts,
                attempts: $phase->attempts,
                tasks: $tasks,
                editOpen: $isEditedPhase,
                editError: $isEditedPhase ? $phaseEditError : null,
                editName: ($isEditedPhase ? $phaseEditName : null) ?? $phase->name,
                editDescription: ($isEditedPhase ? $phaseEditDescription : null) ?? $phase->description,
                editAiDescription: ($isEditedPhase ? $phaseEditAiDescription : null) ?? $phase->aiDescription,
            );
        }

        return $rows;
    }

    private function buildTask(
        TaskDeepOut $t,
        ?int $taskEditId = null,
        ?string $taskEditError = null,
        ?string $taskEditName = null,
        ?string $taskEditDescription = null,
        ?string $taskEditAiDescription = null,
        ?string $taskEditActor = null,
        ?string $taskEditMaxAttempts = null,
    ): TaskViewModel {
        $title = $t->name !== '' ? $t->name : $t->description;

        $transitions = array_values(array_map($this->buildTransition(...), $t->transitions));
        $logs = array_values(array_map($this->buildLogEntry(...), $t->logs));
        $isEditedTask = $taskEditId !== null && $t->id === $taskEditId;

        return new TaskViewModel(
            id: $t->id,
            title: $title,
            status: $t->status,
            actor: $t->actor,
            description: $t->description,
            hasDesc: $t->description !== '' && $t->description !== $title,
            result: $t->result,
            aiResult: $t->aiResult,
            hasSummary: $t->result !== '' || $t->status === 'done',
            hasExtras: $t->aiResult !== '',
            aiDescription: $t->aiDescription,
            createdAt: $t->createdAt->format('Y-m-d H:i'),
            updatedAt: $t->updatedAt->format('Y-m-d H:i'),
            transitions: $transitions,
            logs: $logs,
            phaseId: $t->phaseId,
            order: $t->order,
            archivedAt: $t->archivedAt?->format('Y-m-d H:i') ?? '',
            maxAttempts: $t->maxAttempts,
            attempts: $t->attempts,
            model: $t->model,
            editOpen: $isEditedTask,
            editError: $isEditedTask ? $taskEditError : null,
            editName: ($isEditedTask ? $taskEditName : null) ?? $t->name,
            editDescription: ($isEditedTask ? $taskEditDescription : null) ?? $t->description,
            editAiDescription: ($isEditedTask ? $taskEditAiDescription : null) ?? $t->aiDescription,
            editActor: ($isEditedTask ? $taskEditActor : null) ?? $t->actor,
            editMaxAttempts: ($isEditedTask ? $taskEditMaxAttempts : null) ?? (string) $t->maxAttempts,
        );
    }

    private function buildTransition(StatusTransitionOut $transition): TaskTransitionViewModel
    {
        return new TaskTransitionViewModel(
            fromStatus: $transition->fromStatus,
            toStatus: $transition->toStatus,
            timestamp: $transition->timestamp->format('Y-m-d H:i'),
        );
    }
}
