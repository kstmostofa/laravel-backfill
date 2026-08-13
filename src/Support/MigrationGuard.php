<?php

namespace Kstmostofa\Backfill\Support;

class MigrationGuard
{
    protected static int $depth = 0;

    public static function enter(): void
    {
        static::$depth++;
    }

    public static function leave(): void
    {
        static::$depth = max(0, static::$depth - 1);
    }

    public static function inMigration(): bool
    {
        return static::$depth > 0;
    }

    public static function reset(): void
    {
        static::$depth = 0;
    }
}
