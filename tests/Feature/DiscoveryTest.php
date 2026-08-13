<?php

use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;

it('discovers backfill classes in the configured path', function () {
    $names = app(BackfillRegistry::class)->all()->map->name();

    expect($names)->toContain('user-slugs')
        ->and($names)->toContain('with-failing-row')
        ->and($names)->toContain('without-hydration');
});

it('derives a command-line name from the class name', function () {
    expect((new BackfillUserSlugs)->name())->toBe('user-slugs');
});

it('finds a backfill by name, basename or fully qualified class', function (string $needle) {
    expect(app(BackfillRegistry::class)->find($needle))
        ->toBeInstanceOf(BackfillUserSlugs::class);
})->with([
    'user-slugs',
    'BackfillUserSlugs',
    BackfillUserSlugs::class,
]);

it('explains itself when the name is wrong', function () {
    expect(fn () => app(BackfillRegistry::class)->find('user-slug'))
        ->toThrow(BackfillNotFound::class, 'Known backfills:');
});

it('ignores abstract classes and files without a backfill in them', function () {
    expect(app(BackfillRegistry::class)->all()->pluck('name'))
        ->not->toContain('backfill');
});
