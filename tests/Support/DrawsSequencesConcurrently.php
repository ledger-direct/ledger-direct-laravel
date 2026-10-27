<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Support;

/**
 * Several processes draw destination-tag sequences for one account at once;
 * every draw must succeed, and together they must be gapless and
 * duplicate-free. The worker is tests/Fixtures/draw-sequences.php.
 */
trait DrawsSequencesConcurrently
{
    /**
     * @param string $target a table prefix for the configured server, or "sqlite:<journal mode>:<path>"
     */
    private function assertConcurrentDrawsAreGaplessAndUnique(string $target, int $workers = 4, int $callsPerWorker = 50): void
    {
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $workers; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, __DIR__ . '/../Fixtures/draw-sequences.php', $target, 'rConcurrent', (string) $callsPerWorker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
        }

        $sequences = [];
        $failures = [];
        foreach ($processes as $i => $process) {
            $output = stream_get_contents($pipes[$i][1]);
            $errors = stream_get_contents($pipes[$i][2]);
            self::assertSame(0, proc_close($process), "worker {$i} failed: {$errors}");

            foreach (array_filter(explode("\n", (string) $output)) as $line) {
                if (str_starts_with($line, 'ERROR ')) {
                    $failures[] = substr($line, 6);
                } else {
                    $sequences[] = (int) $line;
                }
            }
        }

        self::assertSame([], array_count_values($failures), 'every draw succeeds under contention');
        self::assertCount($workers * $callsPerWorker, $sequences);
        self::assertCount(count($sequences), array_unique($sequences), 'no sequence is handed out twice');

        sort($sequences);
        self::assertSame(range($sequences[0], $sequences[0] + count($sequences) - 1), $sequences, 'and none is skipped');
    }
}
