# The operator panel

The reason teams keep this installed after the migration that prompted it is long finished.

A developer marks a backfill available and declares what it needs. Support staff then run it themselves — paste a list of ids, press Run, watch a progress bar. No shell, no developer, no ticket, and no way to reach anything that was not deliberately exposed.

<img class="only-light" src="/screenshots/operator.png" alt="The operator panel: choose a task, fill in the declared fields, press Run">
<img class="only-dark" src="/screenshots/operator-dark.png" alt="The operator panel in dark mode">

## Exposing a backfill

```php
use Kstmostofa\Backfill\Parameters\Parameter;

class BackfillOrderReceipts extends Backfill
{
    public bool $operatorRunnable = true;

    public function description(): string
    {
        return 'Re-issue refund receipts';
    }

    public function parameters(): array
    {
        return [
            Parameter::ids('order_ids', 'Order IDs')
                ->required()
                ->max(50_000)
                ->help('Paste the ids from the spreadsheet.'),

            Parameter::select('tone', [
                'formal' => 'Formal',
                'friendly' => 'Friendly',
            ])->default('formal'),

            Parameter::boolean('notify', 'Email the customer'),
        ];
    }

    public function collection(): Builder
    {
        return Order::query()
            ->whereNull('receipt_sent_at')
            ->whereIn('id', $this->parameter('order_ids', []));
    }

    public function process($record): void
    {
        // ...
    }
}
```

`description()` is what appears in the panel — write it for the person who will read it, not for you.

## What the operator can and cannot do

Only backfills marked `$operatorRunnable` appear. Only their declared parameters can be set. Anything not declared is dropped rather than passed through, so there is no way to smuggle in an extra input.

Every value is validated before a job is queued:

- A pasted id list is split on commas, newlines, semicolons or spaces — whichever the spreadsheet produced — then trimmed, de-duplicated, and checked against its ceiling.
- A select must be one of its options.
- Required fields are named individually when missing: `User IDs is required.`

Nothing is queued until every input passes.

## Written for the reader

Progress is described in plain words:

```
Working — 1,204 done so far.
Finished. 4,120 processed.
Paused after 900.
Stopped with a problem. Someone technical needs to look.
```

There is a test asserting the word "cursor" never appears on that page. Batch counts, cursors and stop codes belong on the [engineer dashboard](/features/dashboard); an operator needs to know whether it worked.

Failures are reported the same way:

```
213 rows could not be processed. Everything else went through.
Ask a developer to look at the details.
```

## Its own gate

::: danger Separate from the engineer dashboard on purpose
The people who should be pasting order ids into a form are rarely the people who should be able to cancel a run half way through.
:::

```php
use Kstmostofa\Backfill\Dashboard\Dashboard;

Dashboard::auth(fn ($request) => $request->user()?->isAdmin() === true);
Dashboard::operatorAuth(fn ($request) => $request->user()?->isSupport() === true);
```

`operatorAuth()` falls back to `auth()` when you have not set it, so a single gate still works if that is what you want. Both are closed outside `local` until you open them.

The panel lives at its own route:

```php
'dashboard' => [
    'enabled' => true,
    'path' => 'backfills',
    'operator_path' => 'backfills/tasks',
],
```

## Parameters and resuming

Parameters are recorded on the run and re-applied when it resumes. A resumed run uses the parameters it **started** with, not whatever the caller passes the second time — otherwise a resume with an empty form would quietly run against an empty id list and do nothing.

Resuming with *different* parameters is refused outright:

```
Backfill [order-receipts] has a run in progress that was started with different
parameters (Order IDs: 4,000 entries, Wording: formal). Resuming it with new
ones would mean half the rows were processed under one set of inputs and half
under another. Resume it as it was, or cancel it and start fresh.
```

Half the rows processed under one set of inputs and half under another is not a state anyone wants to reason about a week later.

## From the command line

The same parameters work without the panel, validated identically:

```bash
php artisan backfill:run order-receipts \
    --param=order_ids=1,2,3 \
    --param=tone=friendly
```

Malformed input is rejected rather than guessed at:

```
Parameter [order_ids] should be written as key=value.
```

## Design notes

**Runs are queued, never run in the request.** Pressing Run dispatches a job and returns. You need a queue worker for anything to happen.

**A ceiling on the id list is not optional in spirit.** `Parameter::ids()->max(50_000)` is what stops someone pasting a million-line export into a form and starting a run nobody expected. Set it to something you would be comfortable seeing at 3am.

See the [parameter reference](/reference/parameters) for every type and rule.
