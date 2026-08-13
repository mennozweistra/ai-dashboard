<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\Http;

use AiToolset\AiDashboard\Http\TmCliRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TmCliRunnerTest extends TestCase
{
    private const string STUB = __DIR__ . '/fixtures/fake-tm';
    private const string NOISY_STUB = __DIR__ . '/fixtures/fake-tm-noisy';
    private const string ENV_ECHO_STUB = __DIR__ . '/fixtures/fake-tm-env-echo';

    #[Test]
    public function it_reports_success_and_the_decoded_data_when_the_stub_exits_zero(): void
    {
        $runner = new TmCliRunner(self::STUB);

        $result = $runner->run('ticket:set', ['ticket' => '153', 'name' => 'New name']);

        self::assertTrue($result->isSuccess());
        self::assertSame(['id' => 153, 'name' => 'New name', 'subcommand' => 'ticket:set'], $result->data());
    }

    #[Test]
    public function it_reports_the_decoded_error_message_when_the_stub_exits_nonzero(): void
    {
        $runner = new TmCliRunner(self::STUB);

        $result = $runner->run('ticket:set', ['ticket' => '153', 'name' => 'fail-me']);

        self::assertFalse($result->isSuccess());
        self::assertSame('simulated failure for name', $result->errorMessage());
        self::assertSame([], $result->data());
    }

    #[Test]
    public function it_shell_escapes_option_values_containing_quotes_and_spaces(): void
    {
        $runner = new TmCliRunner(self::STUB);

        $result = $runner->run('ticket:set', [
            'ticket' => '153',
            'name' => "O'Brien & co",
            'description' => 'multi word value',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame("O'Brien & co", $result->data()['name']);
    }

    #[Test]
    public function it_reports_the_generic_error_when_noise_precedes_the_json_on_the_success_stream(): void
    {
        // Ticket 153 task 1274 moved the fix for a diagnostic-noise leak
        // (task 1273's original bug) to bin/tm's own entrypoint, which now
        // discards PHP's routine diagnostics before any vendor code runs.
        // TmCliResult no longer tolerates noise on the receiving end: it
        // trusts bin/tm's contract and does a strict decode of the whole
        // trimmed stream. The noisy stub reproduces a hypothetical contract
        // violation (noise directly ahead of the JSON on stdout, the stream
        // read for a zero exit code); this asserts that now surfaces as the
        // generic error instead of being silently tolerated.
        $runner = new TmCliRunner(self::NOISY_STUB);

        $result = $runner->run('ticket:set', ['ticket' => '153', 'name' => 'New name']);

        self::assertFalse($result->isSuccess());
        self::assertSame('bin/tm exited without a parseable response.', $result->errorMessage());
        self::assertSame([], $result->data());
    }

    #[Test]
    public function it_reports_the_generic_error_when_noise_precedes_the_json_on_the_error_stream(): void
    {
        // Same contract-violation scenario as above, but for the failure
        // path: noise directly ahead of bin/tm's own error JSON on stderr,
        // the stream read for a non-zero exit code. Even though the real
        // error message (requirement 131) is present later in the stream,
        // it must not be salvaged — a strict decode fails, and that failure
        // is reported honestly rather than silently recovered from.
        $runner = new TmCliRunner(self::NOISY_STUB);

        $result = $runner->run('ticket:set', ['ticket' => '153', 'name' => 'fail-me']);

        self::assertFalse($result->isSuccess());
        self::assertSame('bin/tm exited without a parseable response.', $result->errorMessage());
        self::assertSame([], $result->data());
    }

    #[Test]
    public function it_passes_the_process_tm_db_value_through_to_the_bin_tm_child(): void
    {
        // Ticket 161 task 1565: TmCliRunner::run() calls proc_open() without
        // an explicit $env argument, which means the spawned bin/tm child
        // inherits the whole process environment, including TM_DB, rather
        // than starting from a bare environment. This is what lets the
        // dashboard's write actions (which shell out to bin/tm) land in the
        // same database the dashboard's read path opened via TM_DB, once
        // bin/tm itself resolves TM_DB the same way (landed separately in
        // ai-tm). The env-echo stub ignores its subcommand and options and
        // reports only the TM_DB value it observed, so this test isolates
        // the passthrough guarantee from the option-passing behaviour the
        // other tests already cover.
        $previousTmDb = getenv('TM_DB');
        putenv('TM_DB=/tmp/ticket-161-passthrough-test.db');

        try {
            $runner = new TmCliRunner(self::ENV_ECHO_STUB);
            $result = $runner->run('ticket:set', ['ticket' => '153']);
        } finally {
            $previousTmDb === false ? putenv('TM_DB') : putenv('TM_DB=' . $previousTmDb);
        }

        self::assertTrue($result->isSuccess());
        self::assertSame('/tmp/ticket-161-passthrough-test.db', $result->data()['tm_db']);
    }
}
