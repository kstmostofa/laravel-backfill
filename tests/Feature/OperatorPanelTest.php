<?php

use Illuminate\Support\Facades\Queue;
use Kstmostofa\Backfill\Dashboard\Dashboard;
use Kstmostofa\Backfill\Dashboard\OperatorPanel;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillOrderRefunds;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function () {
    BackfillOrderRefunds::reset();
    Dashboard::forgetAuth();
});

afterEach(fn () => Dashboard::forgetAuth());

it('offers only the backfills marked operator-runnable', function () {
    Livewire::test(OperatorPanel::class)
        ->assertSee('Re-issue refund receipts')
        // An engineer-only backfill must not be reachable from here.
        ->assertDontSee('user-slugs');
});

it('shows the declared inputs when a task is chosen', function () {
    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->assertSee('User IDs')
        ->assertSee('Paste the ids from the spreadsheet')
        ->assertSee('Up to 5');
});

it('seeds the form with the declared defaults', function () {
    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->assertSet('input.tone', 'formal');
});

it('queues the run with validated parameters', function () {
    Queue::fake();
    User::seedUnslugged(6);

    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->set('input.user_ids', "2,\n4")
        ->set('input.tone', 'friendly')
        ->call('run')
        ->assertSee('Started Re-issue refund receipts');

    Queue::assertPushed(RunBackfillJob::class, function ($job) {
        return $job->backfill === 'order-refunds'
            && $job->parameters['user_ids'] === ['2', '4']
            && $job->parameters['tone'] === 'friendly';
    });
});

it('refuses to queue anything when the input is wrong', function () {
    Queue::fake();

    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->set('input.user_ids', '1,2,3,4,5,6,7')
        ->call('run')
        ->assertSee('more than the 5 allowed');

    Queue::assertNothingPushed();
});

it('says which field is missing rather than failing silently', function () {
    Queue::fake();

    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->call('run')
        ->assertSee('User IDs is required');

    Queue::assertNothingPushed();
});

it('will not run a backfill that was never exposed', function () {
    Queue::fake();

    Livewire::test(OperatorPanel::class)
        ->set('selected', 'user-slugs')
        ->call('run')
        ->assertSee('not available here');

    Queue::assertNothingPushed();
});

/**
 * An operator hitting a production guard means something is misconfigured, so
 * they get told plainly and sent to a developer — there is deliberately no
 * "run anyway" here. Overriding a guard is an engineer's decision, taken on
 * the engineer's dashboard.
 */
it('will not let an operator override a production guard', function () {
    Queue::fake();
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 2);

    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->set('input.user_ids', '1,2,3')
        ->call('run')
        ->assertSee('Ask a developer to take a look')
        ->assertDontSee('Run anyway');

    Queue::assertNothingPushed();
});

it('describes progress in words rather than cursors', function () {
    User::seedUnslugged(6);

    runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['1', '2']],
    ]);

    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->assertSee('Finished. 2 processed.')
        // No jargon an operator would have to ask about.
        ->assertDontSee('cursor');
});

it('shows what the run was started with', function () {
    User::seedUnslugged(6);

    runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['1', '2'], 'tone' => 'friendly'],
    ]);

    Livewire::test(OperatorPanel::class)
        ->call('select', 'order-refunds')
        ->assertSee('Started with')
        ->assertSee('2 entries');
});

it('gates the operator panel separately from the engineer dashboard', function () {
    app()->detectEnvironment(fn () => 'production');

    Dashboard::auth(fn () => false);
    Dashboard::operatorAuth(fn () => true);

    $this->get('/backfills')->assertForbidden();
    $this->get('/backfills/tasks')->assertSuccessful()->assertSee('Run a task');
});

it('falls back to the engineer gate when no operator gate is set', function () {
    app()->detectEnvironment(fn () => 'production');

    Dashboard::auth(fn () => true);

    $this->get('/backfills/tasks')->assertSuccessful();
});

it('denies the operator panel by default in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/backfills/tasks')->assertForbidden();
});
