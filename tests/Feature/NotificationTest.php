<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kstmostofa\Backfill\Notifications\BackfillStatusNotification;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillThatDeadlocks;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(function () {
    BackfillWithFailingRow::reset();
    BackfillThatDeadlocks::reset();

    config()->set('backfill.notifications', [
        'enabled' => true,
        'on' => ['completed', 'failed', 'paused'],
        'mail' => 'ops@example.com',
        'slack_webhook' => null,
    ]);
});

it('sends nothing when notifications are off', function () {
    config()->set('backfill.notifications.enabled', false);
    Notification::fake();

    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Notification::assertNothingSent();
});

it('notifies on completion', function () {
    Notification::fake();

    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Notification::assertSentOnDemand(
        BackfillStatusNotification::class,
        fn ($notification) => $notification->event === 'completed'
    );
});

it('notifies on failure with the reason', function () {
    Notification::fake();

    User::seedUnslugged(4);
    BackfillThatDeadlocks::$bugsLeft = 99;

    expect(fn () => runBackfill(BackfillThatDeadlocks::class))->toThrow(RuntimeException::class);

    Notification::assertSentOnDemand(
        BackfillStatusNotification::class,
        fn ($notification) => $notification->event === 'failed'
            && str_contains($notification->detail, 'column does not exist')
    );
});

it('notifies when a run pauses itself', function () {
    Notification::fake();

    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    Notification::assertSentOnDemand(
        BackfillStatusNotification::class,
        fn ($notification) => $notification->event === 'paused'
    );
});

it('stays quiet when an operator pauses on purpose', function () {
    Notification::fake();

    User::seedUnslugged(6);

    // Someone asked for this pause; they do not need telling about it.
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    Notification::assertNothingSent();
});

it('respects the list of events to notify on', function () {
    config()->set('backfill.notifications.on', ['failed']);
    Notification::fake();

    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Notification::assertNothingSent();
});

it('posts to a slack webhook when configured', function () {
    config()->set('backfill.notifications.slack_webhook', 'https://hooks.slack.test/abc');
    Notification::fake();
    Http::fake();

    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/abc'
        && str_contains($request['text'], 'finished cleanly'));
});

it('does not fail a completed run because the mail server is down', function () {
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1]);

    User::seedUnslugged(4);

    // The run completed. A broken mailer must not turn that into a failure.
    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(4);
});

it('builds a mail message with the numbers that matter', function () {
    User::seedUnslugged(4);
    $run = runBackfill(BackfillUserSlugs::class);

    $mail = (new BackfillStatusNotification($run, 'completed'))->toMail(new stdClass);

    expect($mail->subject)->toBe('Backfill [user-slugs] completed')
        ->and(collect($mail->introLines)->implode(' '))->toContain('4 rows processed');
});
