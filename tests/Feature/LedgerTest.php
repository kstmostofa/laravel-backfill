<?php

use Illuminate\Support\Facades\Log;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Runner\Ledger;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillThatEmails;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(fn () => BackfillThatEmails::reset());

it('processes every row once on a clean run', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillThatEmails::class);

    expect($run->processed_count)->toBe(6)
        ->and(BackfillThatEmails::$sent)->toBe([1, 2, 3, 4, 5, 6])
        ->and(app(Ledger::class)->unconfirmedCount('that-emails'))->toBe(0);
});

it('never re-sends to a row it has already handled', function () {
    User::seedUnslugged(6);

    runBackfill(BackfillThatEmails::class);
    BackfillThatEmails::$sent = [];

    // The collection is not self-excluding, so without the ledger this second
    // pass would email all six people a second time.
    $second = runBackfill(BackfillThatEmails::class, ['fresh' => true]);

    expect(BackfillThatEmails::$sent)->toBe([])
        ->and($second->processed_count)->toBe(0)
        ->and($second->skipped_count)->toBe(6)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});

it('leaves an unconfirmed claim when the work fails part way', function () {
    User::seedUnslugged(4);
    BackfillThatEmails::$failAfterSending = [2];

    $run = runBackfill(BackfillThatEmails::class);

    // Row 2 was emailed and then something went wrong. Nobody can tell from
    // here whether the email escaped, so the claim stays and is flagged rather
    // than being quietly retried into a second email.
    expect($run->failed_count)->toBe(1)
        ->and(app(Ledger::class)->unconfirmedCount('that-emails'))->toBe(1)
        ->and(app(Ledger::class)->unconfirmed('that-emails')->first()->record_id)->toBe('2');
});

it('does not retry a row whose claim is unresolved', function () {
    User::seedUnslugged(4);
    BackfillThatEmails::$failAfterSending = [2];

    runBackfill(BackfillThatEmails::class);

    BackfillThatEmails::$sent = [];
    BackfillThatEmails::$failAfterSending = [];

    runBackfill(BackfillThatEmails::class, ['fresh' => true]);

    // Row 2 stays untouched: sending nothing is recoverable, sending twice is not.
    expect(BackfillThatEmails::$sent)->toBe([]);
});

it('survives the claim being committed while the batch rolls back', function () {
    User::seedUnslugged(4);

    // The claim is written in its own transaction precisely so a rolled-back
    // batch cannot erase the record that prevents a redo.
    $ledger = app(Ledger::class);

    expect($ledger->claim('manual-test', '99', null))->toBeTrue()
        ->and($ledger->claim('manual-test', '99', null))->toBeFalse()
        ->and($ledger->seen('manual-test', [99]))->toBe(['99']);
});

it('confirms and releases claims', function () {
    $ledger = app(Ledger::class);

    $ledger->claim('manual-test', '1', null);
    expect($ledger->unconfirmedCount('manual-test'))->toBe(1);

    $ledger->confirm('manual-test', '1');
    expect($ledger->unconfirmedCount('manual-test'))->toBe(0);

    $ledger->claim('manual-test', '2', null);
    $ledger->release('manual-test', '2');
    expect($ledger->seen('manual-test', [2]))->toBe([]);
});

it('keeps one backfill ledger separate from another', function () {
    $ledger = app(Ledger::class);

    $ledger->claim('one', '5', null);

    expect($ledger->seen('two', [5]))->toBe([]);
});

it('warns loudly when side effects have nothing protecting them', function () {
    Log::spy();

    User::seedUnslugged(4);

    $backfill = new class extends BackfillUserSlugs
    {
        public bool $externalSideEffects = true;

        public bool $ledger = false;
    };

    runBackfill($backfill);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'declares external side effects but has no ledger')
    );
});

it('says nothing when the side effects are protected', function () {
    Log::spy();

    User::seedUnslugged(4);
    runBackfill(BackfillThatEmails::class);

    Log::shouldNotHaveReceived('warning');
});

it('stays out of the way for an ordinary backfill', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->skipped_count)->toBe(0)
        ->and(app(Ledger::class)->unconfirmedCount('user-slugs'))->toBe(0);
});
