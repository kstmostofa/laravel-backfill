# Notifications

Off by default. When a backfill runs for four hours, somebody wants to know how it went without watching it.

```php
'notifications' => [
    'enabled' => true,
    'on' => ['completed', 'failed', 'paused'],
    'mail' => 'ops@example.com',
    'slack_webhook' => env('BACKFILL_SLACK_WEBHOOK'),
],
```

`mail` accepts a single address or an array. Both channels can be used together, or either alone.

## Three moments, not eight

Only three things are worth interrupting someone about:

| Event | Sent when |
| --- | --- |
| `completed` | A run finished cleanly |
| `failed` | A run stopped with an error |
| `paused` | A run paused **itself** |

Narrow the list with `on` — `['failed', 'paused']` is a reasonable choice if success is not news.

::: tip A human pressing pause is never notified
They already know. Only automatic pauses — the [circuit breaker](/safety/failures#a-systemic-problem) tripping or the [throttle](/safety/throttling) giving up — send anything. A `--max-batches` slice finishing or a `SIGTERM` during a deploy stays silent too.
:::

## What arrives

```
Subject: Backfill [user-slugs] paused

Backfill [user-slugs] paused itself and needs a look.

Circuit breaker tripped: 50 of 50 rows failed in this session (100%, limit 25%).
That rate usually means something systemic rather than bad rows.

2,140,000 rows processed, 50 failed, across 2,140 batches.
Cursor: 2140317

Resume with: php artisan backfill:resume user-slugs
```

Slack gets the same content as a single message.

## A broken mailer cannot fail a run

Delivery errors are swallowed deliberately. A run that completed successfully must not be reported as failed because the mail server was unreachable — the notification is about the run, it is not part of it.

If notifications matter to you operationally, monitor the [events](/features/events) rather than trusting delivery.

## Slack without another package

Slack posts straight to an incoming webhook with a plain HTTP call, rather than going through a notification channel. Laravel's Slack channel lives in a separate package, and requiring it would mean every user of this package installs it whether or not they use Slack.

Create an incoming webhook in Slack, put the URL in `slack_webhook`, done.

## Writing your own

The built-in notifier is just three listeners. Leave `notifications.enabled` off and write your own:

```php
Event::listen(BackfillFailed::class, function (BackfillFailed $event) {
    PagerDuty::trigger([
        'summary' => "Backfill {$event->run->backfill} failed",
        'details' => $event->exception->getMessage(),
        'cursor' => $event->run->cursor,
    ]);
});
```

Or send the packaged notification to somewhere else entirely:

```php
use Kstmostofa\Backfill\Notifications\BackfillStatusNotification;

Event::listen(BackfillCompleted::class, function ($event) {
    $team->notify(new BackfillStatusNotification($event->run, 'completed'));
});
```

It implements `toMail()` and `toArray()`, so database notifications work without any extra work.
