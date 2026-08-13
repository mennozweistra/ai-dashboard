<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

/**
 * Outcome of one `IdeOpener::open()` call: a success flag and a message.
 * This carries no exit code — `IdeOpener` launches the editor detached, so
 * the only exit code it could observe is the shell's own (always 0), which
 * would tell a caller nothing. `$success` instead reflects the `command -v`
 * resolution check `IdeOpener` runs before launching; on failure `$message`
 * names the command that did not resolve, on success `$message` is empty.
 */
final readonly class IdeOpenResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }
}
