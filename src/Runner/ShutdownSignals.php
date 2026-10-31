<?php

namespace Kstmostofa\Backfill\Runner;

/**
 * Traps SIGTERM/SIGINT so a batch in flight is allowed to finish and commit
 * its cursor before the process exits. Without this, a deploy's SIGTERM kills
 * the runner mid-batch and the work of that batch is redone on resume.
 *
 * Whatever handler was installed before is remembered, called alongside ours,
 * and put back afterwards. Inside a queue worker that handler is the worker's
 * own: it is how `queue:work` learns it should quit once the current job is
 * done. Replacing it would leave the worker running after a deploy asked it to
 * stop, and resetting it to the default afterwards would let the next SIGTERM
 * kill the worker mid-job.
 */
class ShutdownSignals
{
    protected bool $shouldStop = false;

    protected bool $listening = false;

    /** @var array<int, mixed> Handlers that were installed before ours, keyed by signal. */
    protected array $previous = [];

    public function listen(): void
    {
        $this->shouldStop = false;

        if ($this->listening || ! $this->supported()) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            $previous = pcntl_signal_get_handler($signal);
            $this->previous[$signal] = $previous;

            pcntl_signal($signal, function (mixed ...$arguments) use ($previous) {
                $this->shouldStop = true;

                // Pass through whatever PHP handed us: the signal number, and
                // the signal info array on PHP builds that provide it.
                if (is_callable($previous)) {
                    call_user_func_array($previous, $arguments);
                }
            });
        }

        $this->listening = true;
    }

    public function release(): void
    {
        if (! $this->listening || ! $this->supported()) {
            return;
        }

        foreach ([SIGTERM, SIGINT] as $signal) {
            $previous = $this->previous[$signal] ?? SIG_DFL;

            pcntl_signal($signal, is_callable($previous) || is_int($previous) ? $previous : SIG_DFL);
        }

        $this->previous = [];
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

    protected function supported(): bool
    {
        return function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler');
    }
}
