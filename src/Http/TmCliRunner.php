<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

/**
 * Thin wrapper around shelling out to `bin/tm`'s `*:set` commands (ticket
 * 153, requirements 130, 131, 140). Kept inside ai-dashboard's Http layer:
 * no controller mutates ai-lib state directly or indirectly, so a
 * ticket/phase/task edit save shells out to `bin/tm ticket:set` / `phase:set`
 * / `task:set` through this wrapper instead of calling an ai-lib write
 * service.
 *
 * The binary path is injected rather than resolved internally, so tests can
 * point it at a fake stub script (see tests/Http/fixtures/fake-tm) instead of
 * a real ai-lib-backed database.
 *
 * `run()` takes every changed field for one save in a single $options array
 * and issues exactly one `bin/tm` invocation — one process spawn per save,
 * never one invocation per changed field.
 */
final readonly class TmCliRunner
{
    public function __construct(private string $binaryPath) {}

    /**
     * @param array<string, string> $options `--key value` pairs, e.g.
     *     ['ticket' => '153', 'name' => 'New name'].
     */
    public function run(string $subcommand, array $options): TmCliResult
    {
        $command = escapeshellarg($this->binaryPath) . ' ' . escapeshellarg($subcommand);
        foreach ($options as $key => $value) {
            $command .= ' --' . escapeshellarg($key) . ' ' . escapeshellarg($value);
        }

        // stdout and stderr are captured as two separate pipes, not merged
        // (no `2>&1`): bin/tm's success JSON (BaseCommand::success()) goes
        // to stdout and its error JSON (BaseCommand::handleError()) goes to
        // stderr. Keeping the streams apart lets TmCliResult pick the exact
        // stream that matches the exit code and decode it directly, rather
        // than guessing which half of a merged blob is the payload.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return TmCliResult::fromProcessOutput(1, '', 'Failed to start bin/tm.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return TmCliResult::fromProcessOutput($exitCode, $stdout !== false ? $stdout : '', $stderr !== false ? $stderr : '');
    }
}
