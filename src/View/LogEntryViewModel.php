<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\View;

final readonly class LogEntryViewModel
{
    public function __construct(
        public int $id,
        public string $logType,
        public string $title,
        public string $detail,
        public string $timestamp,
        public string $level,
        public ?int $taskId = null,
    ) {}
}
