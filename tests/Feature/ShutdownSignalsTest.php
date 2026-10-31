<?php

use Kstmostofa\Backfill\Runner\ShutdownSignals;

beforeEach(function () {
    if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions.');
    }
});

afterEach(function () {
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
    }
});

it('still calls the handler that was installed before it', function () {
    // Stands in for queue:work, which traps SIGTERM to know it should quit
    // once the current job is done.
    $workerAskedToQuit = false;
    pcntl_signal(SIGTERM, function () use (&$workerAskedToQuit) {
        $workerAskedToQuit = true;
    });

    $signals = new ShutdownSignals;
    $signals->listen();

    posix_kill(getmypid(), SIGTERM);
    pcntl_signal_dispatch();

    expect($signals->shouldStop())->toBeTrue()
        ->and($workerAskedToQuit)->toBeTrue();

    $signals->release();
});

it('puts the previous handler back when released', function () {
    $previous = function () {};
    pcntl_signal(SIGTERM, $previous);

    $signals = new ShutdownSignals;
    $signals->listen();

    expect(pcntl_signal_get_handler(SIGTERM))->not->toBe($previous);

    $signals->release();

    expect(pcntl_signal_get_handler(SIGTERM))->toBe($previous);
});

it('restores the default action when nothing was installed before', function () {
    pcntl_signal(SIGINT, SIG_DFL);

    $signals = new ShutdownSignals;
    $signals->listen();
    $signals->release();

    expect(pcntl_signal_get_handler(SIGINT))->toBe(SIG_DFL);
});
