<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

/**
 * `group`/`groupLabel` mirror `QuestionService::isInGroup()`'s three display
 * groups (requirement 386): `open` ("needs you"), `resolved_unprocessed`
 * ("needs agent"), `done` ("done"). `state` carries the raw lifecycle value
 * (`open`, `accepted`, `answered`, `withdrawn`) — for a resolved question
 * this doubles as its resolution.
 */
final readonly class QuestionViewModel
{
    public function __construct(
        public int $id,
        public string $name,
        public string $kind,
        public string $state,
        public string $group,
        public string $groupLabel,
        public string $question,
        public string $background,
        public string $recommendation,
        public ?string $answer,
    ) {}
}
