<?php

namespace Kstmostofa\Backfill\Runner;

/**
 * Traps SIGTERM/SIGINT so a batch in flight is allowed to finish and commit
 * its cursor before the process exits. Without this, a deploy's SIGTERM kills
 * the runner mid-batch and the work of that batch is redone on resume.
 */
class ShutdownSignals
{
    protected bool $shouldStop = false;

    protected bool $listening = false;

    public function listen(): void
    {
        $this->shouldStop = false;

        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () {
                $this->shouldStop = true;
            });
        }

        $this->listening = true;
    }

    public function release(): void
    {
        if (! $this->listening || ! function_exists('pcntl_signal')) {
            return;
        }

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        $this->listening = false;
    }

    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Request a graceful stop from inside the process — used by tests and by
     * callers that detect their own shutdown conditions.
     */
    public function requestStop(): void
    {
        $this->shouldStop = true;
    }
}
