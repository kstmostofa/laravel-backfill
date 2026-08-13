<?php

namespace Kstmostofa\Backfill\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kstmostofa\Backfill\Events\BackfillCompleted;
use Kstmostofa\Backfill\Events\BackfillFailed;
use Kstmostofa\Backfill\Events\BackfillPaused;
use Kstmostofa\Backfill\Models\BackfillRun;
use Throwable;

/**
 * Tells someone when a long-running backfill needs attention.
 *
 * Only three moments are worth an interruption: it finished, it failed, or it
 * paused itself. An operator pausing it on purpose is not news, so those are
 * filtered out.
 */
class BackfillNotifier
{
    public function handleCompleted(BackfillCompleted $event): void
    {
        $this->send($event->run, 'completed');
    }

    public function handleFailed(BackfillFailed $event): void
    {
        $this->send($event->run, 'failed', $event->exception->getMessage());
    }

    public function handlePaused(BackfillPaused $event): void
    {
        // A human hitting pause already knows the backfill is paused.
        if (! $event->wasAutomatic()) {
            return;
        }

        $this->send($event->run, 'paused', $event->message);
    }

    protected function send(BackfillRun $run, string $event, ?string $detail = null): void
    {
        if (! config('backfill.notifications.enabled', false)) {
            return;
        }

        if (! in_array($event, (array) config('backfill.notifications.on', []), true)) {
            return;
        }

        $notification = new BackfillStatusNotification($run, $event, $detail);

        $this->mail($notification);
        $this->slack($notification);
    }

    protected function mail(BackfillStatusNotification $notification): void
    {
        $recipients = array_filter((array) config('backfill.notifications.mail'));

        if ($recipients === []) {
            return;
        }

        try {
            Notification::route('mail', count($recipients) === 1 ? reset($recipients) : $recipients)
                ->notify($notification);
        } catch (Throwable) {
            // A backfill that finished must not be reported as failed just
            // because the mail server was unreachable.
        }
    }

    /**
     * Posted straight to an incoming webhook rather than through a notification
     * channel, so Slack support does not drag in another package.
     */
    protected function slack(BackfillStatusNotification $notification): void
    {
        $webhook = config('backfill.notifications.slack_webhook');

        if (! $webhook) {
            return;
        }

        $run = $notification->run;

        $text = sprintf(
            "*%s*\n%s rows processed, %s failed, %s batches. Cursor: %s.%s",
            $notification->headline(),
            number_format($run->processed_count),
            number_format($run->failed_count),
            number_format($run->batch_count),
            $run->cursor ?? 'not started',
            $notification->detail ? "\n".$notification->detail : '',
        );

        try {
            Http::timeout(5)->post($webhook, ['text' => $text]);
        } catch (Throwable) {
            //
        }
    }
}
