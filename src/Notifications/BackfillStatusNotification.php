<?php

namespace Kstmostofa\Backfill\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Kstmostofa\Backfill\Models\BackfillRun;

class BackfillStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly BackfillRun $run,
        public readonly string $event,
        public readonly ?string $detail = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->run;

        $message = (new MailMessage)
            ->subject($this->subject())
            ->line($this->headline());

        if ($this->detail) {
            $message->line($this->detail);
        }

        $message
            ->line(sprintf(
                '%s rows processed, %s failed, across %s batches.',
                number_format($run->processed_count),
                number_format($run->failed_count),
                number_format($run->batch_count),
            ))
            ->line('Cursor: '.($run->cursor ?? 'not started'));

        if ($this->event !== 'completed') {
            $message->line("Resume with: php artisan backfill:resume {$run->backfill}");
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'backfill' => $this->run->backfill,
            'run_id' => $this->run->id,
            'event' => $this->event,
            'status' => $this->run->status->value,
            'processed' => $this->run->processed_count,
            'failed' => $this->run->failed_count,
            'detail' => $this->detail,
        ];
    }

    public function subject(): string
    {
        return match ($this->event) {
            'completed' => "Backfill [{$this->run->backfill}] completed",
            'failed' => "Backfill [{$this->run->backfill}] failed",
            default => "Backfill [{$this->run->backfill}] paused",
        };
    }

    public function headline(): string
    {
        return match ($this->event) {
            'completed' => "Backfill [{$this->run->backfill}] finished cleanly.",
            'failed' => "Backfill [{$this->run->backfill}] stopped with an error.",
            default => "Backfill [{$this->run->backfill}] paused itself and needs a look.",
        };
    }
}
