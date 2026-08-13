<?php

use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Parameters\Parameter;
use Kstmostofa\Backfill\Parameters\ParameterBag;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillOrderRefunds;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(fn () => BackfillOrderRefunds::reset());

it('splits a pasted id list however the spreadsheet formatted it', function (string $input) {
    [$value] = Parameter::ids('ids')->validate($input);

    expect($value)->toBe(['1', '2', '3']);
})->with([
    '1,2,3',
    "1\n2\n3",
    '1, 2, 3',
    "1;2;  3\n",
    '1 2 3',
]);

it('drops blanks and duplicates from a pasted list', function () {
    [$value] = Parameter::ids('ids')->validate("7,,7, 8,\n\n9");

    expect($value)->toBe(['7', '8', '9']);
});

it('refuses a list longer than the ceiling', function () {
    [, $errors] = Parameter::ids('ids', 'Order IDs')->max(3)->validate('1,2,3,4');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('has 4 entries, which is more than the 3 allowed');
});

it('requires what is marked required', function () {
    [, $errors] = Parameter::ids('ids', 'Order IDs')->required()->validate('');

    expect($errors[0])->toBe('Order IDs is required.');
});

it('falls back to the default when nothing was given', function () {
    [$value, $errors] = Parameter::text('tone')->default('formal')->validate(null);

    expect($value)->toBe('formal')->and($errors)->toBe([]);
});

it('keeps a number inside its bounds', function () {
    expect(Parameter::number('days')->max(30)->validate(31)[1][0])->toContain('cannot be above 30');
    expect(Parameter::number('days')->min(1)->validate(0)[1][0])->toContain('cannot be below 1');
    expect(Parameter::number('days')->max(30)->validate(30)[1])->toBe([]);
});

it('rejects an option that is not on the list', function () {
    $parameter = Parameter::select('tone', ['formal' => 'Formal']);

    expect($parameter->validate('shouty')[1][0])->toContain('not one of the allowed options');
    expect($parameter->validate('formal')[1])->toBe([]);
});

it('treats an unticked checkbox as an answer, not a blank', function () {
    [$value, $errors] = Parameter::boolean('notify')->required()->validate(false);

    expect($value)->toBeFalse()->and($errors)->toBe([]);
});

it('derives a readable label from the key', function () {
    expect(Parameter::ids('user_ids')->label)->toBe('User ids');
});

it('validates a whole backfill and ignores undeclared input', function () {
    $result = ParameterBag::validate(new BackfillOrderRefunds, [
        'user_ids' => '1,2',
        'tone' => 'friendly',
        'notify' => true,
        'delete_everything' => true,
    ]);

    expect($result['errors'])->toBe([])
        ->and($result['values'])->toBe([
            'user_ids' => ['1', '2'],
            'tone' => 'friendly',
            'notify' => true,
        ]);
});

it('summarises the values for the audit trail', function () {
    $summary = ParameterBag::summarise(new BackfillOrderRefunds, [
        'user_ids' => ['1', '2', '3'],
        'tone' => 'friendly',
        'notify' => false,
    ]);

    expect($summary)->toBe('User IDs: 3 entries, Wording: friendly, Email the customer: no');
});

it('runs a backfill with the parameters it was given', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['2', '4'], 'tone' => 'friendly'],
    ]);

    expect($run->processed_count)->toBe(2)
        ->and(BackfillOrderRefunds::$processed)->toBe([2, 4])
        ->and(User::find(2)->slug)->toBe('friendly-2')
        ->and(User::find(1)->slug)->toBeNull();
});

it('records the parameters on the run', function () {
    User::seedUnslugged(4);

    $run = runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['1'], 'tone' => 'formal', 'notify' => false],
    ]);

    expect($run->meta['parameters']['user_ids'])->toBe(['1'])
        ->and($run->meta['parameter_summary'])->toContain('1 entries');
});

it('resumes with the parameters the run started with', function () {
    User::seedUnslugged(10);

    // Four matching rows, two per batch, stopped after one batch.
    $first = runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['1', '2', '3', '4'], 'tone' => 'friendly'],
        'maxBatches' => 1,
    ]);

    expect($first->processed_count)->toBe(2);

    BackfillOrderRefunds::reset();

    // Resumed with no parameters at all — it must use the stored ones, not run
    // against an empty id list and quietly do nothing.
    $second = runBackfill(BackfillOrderRefunds::class);

    expect($second->id)->toBe($first->id)
        ->and($second->processed_count)->toBe(4)
        ->and(BackfillOrderRefunds::$processed)->toBe([3, 4])
        ->and(User::find(4)->slug)->toBe('friendly-4');
});

it('refuses to resume a run with different parameters', function () {
    User::seedUnslugged(10);

    runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['1', '2', '3', '4']],
        'maxBatches' => 1,
    ]);

    // Half the rows were processed under one list; carrying on with another
    // would make the run mean two different things.
    expect(fn () => runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['7', '8']],
    ]))->toThrow(BackfillRefused::class, 'different parameters');
});

it('allows a fresh run with new parameters', function () {
    User::seedUnslugged(10);

    runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['1', '2', '3', '4']],
        'maxBatches' => 1,
    ]);

    $fresh = runBackfill(BackfillOrderRefunds::class, [
        'parameters' => ['user_ids' => ['7', '8']],
        'fresh' => true,
    ]);

    expect($fresh->processed_count)->toBe(2)
        ->and(User::find(7)->slug)->not->toBeNull();
});
