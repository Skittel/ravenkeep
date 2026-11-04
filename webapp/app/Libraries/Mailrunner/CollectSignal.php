<?php

namespace App\Libraries\Mailrunner;

/**
 * Coordination of collect runs (T-22): ping, second ping, timer and manual runs never overlap.
 *
 * - request(): written by the ping; a systemd path unit (ravenkeep-collect.path) starts the
 *   collect service when the file is written.
 * - Every collect run takes a non-blocking kernel lock (flock). If it is held, the run only
 *   requests a follow-up run and ends at once; the running collect sees the request and does
 *   one more round (at most MAX_ROUNDS in a row). The kernel releases the lock after a crash.
 */
class CollectSignal
{
    public const MAX_ROUNDS = 5;

    /** @var resource|null */
    private $lock;

    public function __construct(private readonly string $runDir)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('Mailrunner')->runDir);
    }

    /** Request a collect run (ping, or a run that found the lock held). */
    public function request(): void
    {
        $this->ensureDir();
        // a real write (open/write/close) so that PathChanged= of the path unit fires
        file_put_contents($this->runDir . '/collect.pending', gmdate('c') . "\n", LOCK_EX);
    }

    /** Take the lock without waiting; false if another collect run holds it. */
    public function acquire(): bool
    {
        $this->ensureDir();
        $fh = fopen($this->runDir . '/collect.lock', 'c');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open lock file in {$this->runDir}");
        }
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        $this->lock = $fh;

        return true;
    }

    /** Called at the start of each round: consume a pending request. */
    public function startRound(): void
    {
        @unlink($this->runDir . '/collect.pending');
    }

    /** True if a request arrived during the round. */
    public function hasPending(): bool
    {
        clearstatcache(true, $this->runDir . '/collect.pending');

        return is_file($this->runDir . '/collect.pending');
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->runDir) && ! @mkdir($this->runDir, 0750, true) && ! is_dir($this->runDir)) {
            throw new \RuntimeException("Cannot create {$this->runDir}");
        }
    }
}
