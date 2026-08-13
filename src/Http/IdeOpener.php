<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Http;

/**
 * Thin wrapper around shelling out to the machine-local IDE command read
 * from `~/.ai-dashboard/config.toml` (ticket 164, requirements 201/202).
 * Kept inside ai-dashboard's Http layer, never inside ai-lib, and the
 * command word is injected rather than resolved internally so a test can
 * point it at a fake stub script instead of a real GUI editor (see
 * tests/Http/fixtures/fake-ide).
 *
 * A GUI editor needs two things a simpler shell-out would not:
 *
 * 1. A GUI editor does not return until the user closes it, so `open()`
 *    launches detached (` >/dev/null 2>&1 &` appended) and returns as soon
 *    as the shell has forked the process, instead of blocking on `exec()`.
 * 2. A detached launch always exits 0 from the shell's point of view, so a
 *    wrong or missing command would otherwise always report success. Before
 *    launching, `open()` runs `command -v <command>` and checks its exit
 *    code; only a zero exit proceeds to the detached launch.
 */
final readonly class IdeOpener
{
    public function __construct(private string $command) {}

    public function open(string $directory): IdeOpenResult
    {
        $escapedCommand = escapeshellarg($this->command);

        $checkOutput = [];
        $checkExitCode = 0;
        exec("command -v {$escapedCommand} >/dev/null 2>&1", $checkOutput, $checkExitCode);

        if ($checkExitCode !== 0) {
            return new IdeOpenResult(success: false, message: "ide command not found: {$this->command}");
        }

        // DISPLAY is defaulted here because a `php -S` process commonly has
        // no DISPLAY set, but a GUI editor needs one.
        $launchCommand = 'DISPLAY=${DISPLAY:-:1} '
            . $escapedCommand
            . ' '
            . escapeshellarg($directory)
            . ' >/dev/null 2>&1 &';

        exec($launchCommand);

        return new IdeOpenResult(success: true, message: '');
    }
}
