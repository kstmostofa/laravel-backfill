<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * A multi-tenant backfill. Each tenant gets its own cursor and its own run, so
 * one tenant crashing does not rewind the others.
 *
 * Real tenancy would switch a database connection here; the fixture partitions
 * one table by name prefix, which exercises the same machinery.
 */
class BackfillPerTenant extends Backfill
{
    public int $batchSize = 2;

    public static array $tenants = ['acme', 'globex'];

    public static array $switchedTo = [];

    protected ?string $tenant = null;

    public function tenants(): ?iterable
    {
        return static::$tenants;
    }

    public function useTenant(string|int $tenant): void
    {
        $this->tenant = (string) $tenant;

        static::$switchedTo[] = $this->tenant;
    }

    public function collection(): Builder
    {
        return User::query()
            ->whereNull('slug')
            ->where('name', 'like', $this->tenant.'-%');
    }

    public function process($record): void
    {
        $record->forceFill([
            'slug' => $record->name,
            'process_count' => $record->process_count + 1,
        ])->save();
    }

    public static function reset(): void
    {
        static::$tenants = ['acme', 'globex'];
        static::$switchedTo = [];
    }
}
