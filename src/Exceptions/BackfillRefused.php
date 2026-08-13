<?php

namespace Kstmostofa\Backfill\Exceptions;

use RuntimeException;

class BackfillRefused extends RuntimeException
{
    public static function insideMigration(string $name): static
    {
        return new static(
            "Backfill [{$name}] cannot run inside a migration. Migrations run synchronously "
            ."during deploy, often inside a transaction, so a long-running data change there "
            .'blocks the pipeline and risks a statement timeout. Run it separately with '
            ."`php artisan backfill:run {$name}` once the deploy has finished."
        );
    }

    public static function byGuard(string $name): static
    {
        return new static("Backfill [{$name}] refused to start: guard() returned false.");
    }
}
