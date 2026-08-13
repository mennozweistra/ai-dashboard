<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deptrac prints "Syntax Error on File <path>" for a file it cannot parse, drops that
 * file from the dependency graph, then reports Errors 0 and exits 0 — a clean run that
 * never actually checked the file. There is no machine-readable signal for this failure
 * mode: Deptrac's JsonOutputFormatter::finish() has no code path for an unparseable
 * file, so a guard built on `--formatter=json` would never see it. This is why the
 * "deptrac" composer script (see composer.json) greps Deptrac's console text for
 * "Syntax Error" instead. Do NOT rewrite the guard or this test to use
 * --formatter=json — that would silently disarm the check requirement 333 exists to
 * enforce, for the sake of a "cleaner" machine-readable channel that does not carry
 * this signal.
 *
 * This test shells out to the real `composer deptrac` script against the real src/
 * tree (not a stub or a fixture config) so that a future Deptrac release rewording its
 * message would be caught here too. A fixture-config test would bypass the composer
 * script entirely and could never catch that.
 */
final class DeptracGuardTest extends TestCase
{
    #[Test]
    public function it_fails_the_deptrac_script_when_a_file_cannot_be_parsed(): void
    {
        // Distinctive name: if this process is killed before the finally block runs,
        // the orphaned file is obviously test debris, not mistakable for real source.
        $probePath = dirname(__DIR__, 2) . '/src/__deptrac_guard_probe.php';

        file_put_contents($probePath, "<?php\n\nclass __DeptracGuardProbe\n{\n    public function broken( {\n");

        try {
            exec('composer deptrac 2>&1', $output, $exitCode);

            self::assertNotSame(
                0,
                $exitCode,
                "Expected `composer deptrac` to fail when a file cannot be parsed, got exit 0. Output:\n" . implode("\n", $output),
            );
        } finally {
            @unlink($probePath);
        }
    }
}
