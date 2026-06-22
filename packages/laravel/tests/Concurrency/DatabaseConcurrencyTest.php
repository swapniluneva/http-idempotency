<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Tests\Concurrency;

use HttpIdempotency\Laravel\Store\DatabaseStore;
use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\BeginResult;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real parallelism, not simulated: forks many OS processes that each open their
 * own connection to a shared on-disk SQLite database and race to begin() the
 * same idempotency key at a synchronized start barrier.
 *
 * This proves the locking is enforced by the database (UNIQUE constraint to
 * acquire, lock-token CAS to complete) rather than by in-process bookkeeping.
 * Skipped where pcntl is unavailable (non-unix / threaded SAPIs).
 */
final class DatabaseConcurrencyTest extends TestCase
{
    private string $dbFile;

    private string $resultDir;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required for the multi-process concurrency test.');
        }

        $this->dbFile = tempnam(sys_get_temp_dir(), 'idem_conc_').'.sqlite';
        $this->resultDir = $this->dbFile.'.results';
        mkdir($this->resultDir);

        $this->migrate();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->resultDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->resultDir);
        foreach (glob($this->dbFile.'*') ?: [] as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function exactly_one_of_many_concurrent_processes_wins_the_lock(): void
    {
        $workers = 30;
        // Start every child at the same wall-clock instant to maximise the race.
        $barrier = microtime(true) + 1.0;

        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                $this->runWorker($i, $barrier);
                exit(0); // never return to the test runner
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = $this->collectResults($workers);

        $acquired = array_filter($results, fn (array $r): bool => $r['result'] === 'Acquired');
        $inProgress = array_filter($results, fn (array $r): bool => $r['result'] === 'InProgress');

        self::assertCount($workers, $results, 'every worker recorded a result');
        self::assertCount(1, $acquired, 'exactly one process may acquire the lock');
        self::assertCount(
            $workers - 1,
            $inProgress,
            'all other processes (same fingerprint) must see the original in progress',
        );

        // The lock-token CAS: only the winner can complete the record.
        $store = $this->store();
        $winner = array_values($acquired)[0];

        self::assertTrue(
            $store->complete('race-key', $winner['token'], new StoredResponse(201, [], 'ok')),
            'the winning process can finalize with its token',
        );
        self::assertFalse(
            $store->complete('race-key', 'some-other-token', new StoredResponse(201, [], 'nope')),
            'a non-owner token cannot finalize the record',
        );

        // And the now-completed key replays for everyone afterwards.
        self::assertSame(BeginResult::Replay, $store->begin('race-key', 'race', 'fp-same', 60)->result);
    }

    private function runWorker(int $id, float $barrier): void
    {
        $store = $this->store();

        // Busy-wait to the barrier so all children call begin() near-simultaneously.
        while (microtime(true) < $barrier) {
            usleep(200);
        }

        $outcome = $store->begin('race-key', 'race', 'fp-same', 60);

        file_put_contents(
            $this->resultDir.'/'.$id,
            $outcome->result->name."\n".($outcome->lockToken ?? ''),
        );
    }

    /**
     * @return list<array{result: string, token: string}>
     */
    private function collectResults(int $workers): array
    {
        $results = [];
        for ($i = 0; $i < $workers; $i++) {
            $raw = (string) file_get_contents($this->resultDir.'/'.$i);
            [$result, $token] = array_pad(explode("\n", $raw, 2), 2, '');
            $results[] = ['result' => $result, 'token' => $token];
        }

        return $results;
    }

    private function store(): DatabaseStore
    {
        return new DatabaseStore($this->connection(), 'idempotency_keys');
    }

    private function connection(): ConnectionInterface
    {
        // Build the connection from a raw PDO (rather than Capsule's SQLite
        // connector, which resolves paths via app helpers we don't boot here).
        // Each call opens a fresh handle — children must not share a PDO across
        // a fork.
        $pdo = new \PDO('sqlite:'.$this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // WAL + a generous busy timeout so concurrent writers serialise on the
        // UNIQUE insert instead of erroring out with "database is locked".
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=10000');

        return new SQLiteConnection($pdo, $this->dbFile);
    }

    private function migrate(): void
    {
        $this->connection()->getSchemaBuilder()->create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('lookup_key', 64)->unique();
            $table->string('idempotency_key');
            $table->string('fingerprint', 64);
            $table->string('state', 16);
            $table->string('lock_token', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('completed_at')->nullable();
        });
    }
}
