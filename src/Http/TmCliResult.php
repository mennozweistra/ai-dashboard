<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

/**
 * Outcome of one `TmCliRunner::run()` call. Parses bin/tm's JSON envelope:
 * `{"ok":true,"data":{...}}` on success (BaseCommand::success(), written to
 * stdout) or `{"ok":false,"error":{"code":...,"message":...,"type":...}}`
 * on failure (BaseCommand::handleError(), written to stderr). Exposes only
 * the decoded `data` array on success or the decoded `error.message` string
 * on failure (requirement 131) — never the raw JSON and never the raw
 * process output.
 *
 * bin/tm's own contract (ticket 153 task 1274) guarantees a clean JSON
 * envelope on the stream matching its exit code: its entrypoint discards
 * PHP's own routine diagnostics (deprecation notices, warnings, notices)
 * before any vendor code runs, so nothing else is ever written to that
 * stream. This class trusts that contract: it decodes the whole trimmed
 * stream content as JSON, nothing more lenient. If the exit-code-matching
 * stream does not decode to the expected shape, that means bin/tm violated
 * its own contract (a crash before any JSON is written, or unexpected
 * output) — this falls back to a generic error message instead of
 * throwing, but it does not attempt to salvage a partial or offset parse.
 */
final readonly class TmCliResult
{
    private const string GENERIC_ERROR_MESSAGE = 'bin/tm exited without a parseable response.';

    /** @param array<array-key, mixed> $data */
    private function __construct(
        private bool $success,
        private array $data,
        private string $errorMessage,
    ) {}

    public static function fromProcessOutput(int $exitCode, string $stdout, string $stderr): self
    {
        $stream = $exitCode === 0 ? $stdout : $stderr;
        $decoded = json_decode(trim($stream), true);

        if (is_array($decoded)) {
            $data = $decoded['data'] ?? null;
            if ($exitCode === 0 && ($decoded['ok'] ?? null) === true && is_array($data)) {
                return new self(success: true, data: $data, errorMessage: '');
            }

            $error = $decoded['error'] ?? null;
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            if (($decoded['ok'] ?? null) === false && is_string($message)) {
                return new self(success: false, data: [], errorMessage: $message);
            }
        }

        return new self(success: false, data: [], errorMessage: self::GENERIC_ERROR_MESSAGE);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** @return array<array-key, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }
}
