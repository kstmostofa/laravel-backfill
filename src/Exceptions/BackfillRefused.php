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

    public static function tooManyRows(string $name, int $estimate, int $ceiling): static
    {
        return new static(sprintf(
            'Backfill [%s] matches %s rows, above the %s row ceiling set by '
            .'backfill.guards.max_rows_without_confirmation. Dry-run it first with '
            .'`backfill:run %s --dry-run`, then pass --force if the number is expected.',
            $name,
            number_format($estimate),
            number_format($ceiling),
            $name,
        ));
    }

    public static function duringFreeze(string $name, string $window): static
    {
        return new static(
            "Backfill [{$name}] cannot start during the deploy freeze window {$window}. "
            .'Wait for the window to close, or pass --force if this is the emergency it exists for.'
        );
    }
}
